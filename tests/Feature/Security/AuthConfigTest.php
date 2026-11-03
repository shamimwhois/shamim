<?php

use App\Models\User;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Cookie;

/*
|--------------------------------------------------------------------------
| Authentication configuration
|--------------------------------------------------------------------------
|
| Three decisions here are invisible while they are correct: how strong a
| password has to be, what the session cookie claims about itself, and whether
| a recovery code can be spent twice. Each is a piece of policy that lives in a
| closure or a config key, which means a edit somewhere else can change the
| behaviour without touching anything that looks like authentication code.
|
| The password rule is the interesting one because it is deliberately
| environment-dependent: `AppServiceProvider` returns a strict rule in
| production and `null` everywhere else, so local accounts and seeded fixtures
| are not held to a rule their test passwords do not meet. That trade-off is
| only safe while the production branch really is strict, which is what the
| first two tests pin — including the half people forget, that the lax branch
| is a decision and not an accident.
|
*/

/**
 * Run a closure with the application believing it is production.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function withProductionEnvironment(callable $callback): mixed
{
    $previous = app()['env'] ?? null;

    app()['env'] = 'production';

    try {
        return $callback();
    } finally {
        if ($previous === null) {
            unset(app()['env']);
        } else {
            app()['env'] = $previous;
        }
    }
}

test('a production password rule is the one the docblock promises', function (): void {
    $rule = withProductionEnvironment(fn (): Password => Password::defaults());

    // `appliedRules()` is the rule's own record of what it requires, keyed by
    // capability rather than by validation string.
    $applied = $rule->appliedRules();

    expect($applied['min'])->toBe(12)
        ->and($applied['letters'])->toBeTrue()
        ->and($applied['numbers'])->toBeTrue()
        ->and($applied['symbols'])->toBeTrue()
        ->and($applied['mixedCase'])->toBeTrue()
        ->and($applied['uncompromised'])->toBeTrue('a production password is not checked against the breach lists');
});

test('a local password rule falls back to the framework floor on purpose', function (): void {
    // Outside production the application's closure returns `null`, and the
    // framework substitutes its own `min(8)` with no other requirement — which
    // is what lets a seeded account hold the password `password`. This is the
    // assertion that keeps the previous test honest: if the ternary in
    // `AppServiceProvider` ever inverts, production stays strict and only this
    // test notices that every fixture and local account now fails validation.
    $applied = Password::defaults()->appliedRules();

    expect($applied['min'])->toBe(8)
        ->and($applied['letters'])->toBeFalse()
        ->and($applied['numbers'])->toBeFalse()
        ->and($applied['symbols'])->toBeFalse()
        ->and($applied['mixedCase'])->toBeFalse()
        ->and($applied['uncompromised'])->toBeFalse();
});

test('a session cookie is issued http only and same site lax', function (): void {
    // Asserted on a real response rather than on `config('session.*')`: the
    // framework derives several of these attributes at the point it writes the
    // header, so a config value alone does not prove what a browser is told.
    $response = $this->get('/');

    expect($response->getStatusCode())->toBe(200);

    $session = collect($response->headers->getCookies())
        ->first(fn (Cookie $cookie): bool => ! in_array($cookie->getName(), ['XSRF-TOKEN'], true));

    expect($session)->not->toBeNull('the response set no session cookie')
        ->and($session->isHttpOnly())->toBeTrue('the session cookie is readable by script')
        ->and($session->getSameSite())->toBe('lax');
});

test('the csrf cookie is deliberately readable by script', function (): void {
    // The inverse of the test above: `XSRF-TOKEN` must stay readable, because
    // the axios/Livewire interceptors that send the header on a JSON request are
    // JavaScript. A change to `config('session.http_only')` reaching this cookie
    // would break every mutating request in the editor rather than improve it.
    $xsrf = collect($this->get('/')->headers->getCookies())
        ->first(fn (Cookie $cookie): bool => $cookie->getName() === 'XSRF-TOKEN');

    expect($xsrf)->not->toBeNull()
        ->and($xsrf->isHttpOnly())->toBeFalse();
});

test('a recovery code authenticates exactly once', function (): void {
    $this->skipUnlessFortifyHas('two-factor-authentication');

    $user = User::factory()->withTwoFactor()->create();

    expect($user->recoveryCodes())->toBe(['recovery-code-1']);

    $this->withSession(['login.id' => $user->getKey(), 'login.remember' => false]);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);

    // Fortify spends a code by replacing it, so the invariant is not "the set
    // empties" but "the value she had stops being one of them" — which is the
    // property that makes a leaked list worthless after use.
    $remaining = $user->fresh()->recoveryCodes();

    expect($remaining)->not->toContain('recovery-code-1')
        ->and($remaining)->toHaveCount(1);

    $this->post(route('logout'))->assertRedirect();
    $this->withSession(['login.id' => $user->getKey(), 'login.remember' => false]);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1']);

    expect(auth()->check())->toBeFalse('a recovery code that has been spent can be spent again');
    $this->assertGuest();
});

test('an unknown recovery code does not authenticate', function (): void {
    $this->skipUnlessFortifyHas('two-factor-authentication');

    $user = User::factory()->withTwoFactor()->create();

    $this->withSession(['login.id' => $user->getKey(), 'login.remember' => false]);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'not-one-of-hers']);

    $this->assertGuest();

    expect($user->fresh()->recoveryCodes())->toBe(['recovery-code-1'], 'a failed attempt consumed a code');
});

test('a wrong six digit code does not authenticate and consumes nothing', function (): void {
    $this->skipUnlessFortifyHas('two-factor-authentication');

    $user = User::factory()->withTwoFactor()->create();

    $this->withSession(['login.id' => $user->getKey(), 'login.remember' => false]);

    $this->post(route('two-factor.login.store'), ['code' => '123456']);

    $this->assertGuest();

    expect($user->fresh()->recoveryCodes())->toBe(['recovery-code-1'])
        ->and(Features::enabled('two-factor-authentication'))->toBeTrue();
});
