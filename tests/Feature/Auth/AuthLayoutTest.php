<?php

use App\Models\User;
use Laravel\Fortify\Features;

/**
 * Authentication layout contract.
 *
 * Login, registration, and the forgot-password request are modal-first now —
 * their URLs redirect to the flagged home page where the `x-auth.modals`
 * dialogs open, and no standalone pages exist for them. The auth layout still
 * carries the four mid-flow screens (verify email, two-factor challenge,
 * confirm password, and the token-bearing reset form), which makes it the
 * single highest-leverage view left in the auth journey — and the single
 * easiest place for a regression to hide, because a broken frame still renders
 * a usable form.
 *
 * The reset-password screen is the only auth screen a guest can reach
 * directly, so it stands in for the layout in these tests.
 *
 * These tests pin down the three properties that were actually wrong before
 * the redesign, so that none of them can come back quietly:
 *
 *   1. The theme was hardcoded to dark, overriding the visitor's choice.
 *   2. The layout rendered framework placeholder branding.
 *   3. The screens had no shared visual signature tying them to the site.
 */
test('the auth layout does not force a colour scheme on the visitor', function () {
    $response = $this->get(route('password.reset', ['token' => 'layout-check']));

    $response->assertOk();

    // A hardcoded `class="dark"` on <html> is the exact bug this guards
    // against: it made the sign-in page ignore the visitor's theme setting.
    expect($response->getContent())
        ->not->toContain('<html lang="en" class="dark"')
        ->not->toContain('class="dark"');
});

test('the auth layout delegates theming to the appearance controller', function () {
    $response = $this->get(route('password.reset', ['token' => 'layout-check']));

    // @fluxAppearance is what reads and applies the stored preference. Without
    // it the page would render with no theme resolution at all.
    $response->assertOk();

    expect($response->getContent())->toContain('flux.appearance');
});

test('the modal-first auth urls redirect to the flagged home', function () {
    // Login, registration, and the forgot-password request have no standalone
    // pages: each URL lands on the public home with the matching dialog open
    // server-side. Keeping these redirects — not 404s — is what keeps the
    // no-JS fallbacks and the email/legacy links working.
    $expected = [
        'login' => ['signin' => 'required'],
        'register' => ['signup' => 'required'],
        'password.request' => ['forgot' => 'required'],
    ];

    foreach ($expected as $route => $flag) {
        $this->get(route($route))->assertRedirect(route('home', $flag));
    }
});

test('every remaining auth screen renders without framework placeholder branding', function () {
    $response = $this->get(route('password.reset', ['token' => 'layout-check']));

    $response->assertOk();

    // The starter kit's layout printed config('app.name', 'Laravel') as a
    // fallback and shipped a stock quote panel. Neither belongs here.
    expect($response->getContent())->not->toContain('Inspiring');
});

test('the auth layout carries the site brand and a route back to it', function () {
    $response = $this->get(route('password.reset', ['token' => 'layout-check']));

    $response->assertOk()
        ->assertSee(route('home'), escape: false)
        ->assertSee('Back to site', escape: false);
});

test('auth screens expose an accessible skip link to the form', function () {
    $response = $this->get(route('password.reset', ['token' => 'layout-check']));

    $response->assertOk()
        ->assertSee('href="#auth-form"', escape: false)
        ->assertSee('id="auth-form"', escape: false);
});

test('the two factor challenge renders both input modes in one form', function () {
    // The challenge screen is only reachable mid-login, after the password step
    // has succeeded. Driving the session to that point is the only way to get
    // a real request — and therefore a populated $errors bag — into the view.
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    // Both input modes and both headings belong to the same Alpine scope as
    // the inputs they label, inside one form.
    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertSee('action="'.route('two-factor.login.store').'"', escape: false)
        ->assertSee('Authentication code', escape: false)
        ->assertSee('Recovery code', escape: false)
        ->assertSee('name="recovery_code"', escape: false);
});
