<?php

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Throttling
|--------------------------------------------------------------------------
|
| Two sets of limits exist in this application for different reasons, and both
| are the kind of thing that silently rots: the five `/editor/ai` endpoints
| because every accepted call spends money on somebody's account, and the
| Fortify login/two-factor limiters because they are the only thing between a
| script and a password list.
|
| A throttle is worth pinning twice, and the two halves say different things.
| The declared number is checked against the route, because an edit to
| `routes/web.php` can widen a limit without changing a single line of the
| endpoints' code — and a limit that has moved from 5 to 500 is no longer the
| contract the docblock above it describes. The behaviour is checked against a
| live request loop, because a `throttle:` middleware that is declared but
| bypassed (a changed key, a `RateLimiter::clear` in a listener, a limiter name
| that resolves to no definition) still reports the right string.
|
| The behavioural half also pins the two properties the numbers alone cannot
| express: that the buckets are keyed per account and per identifier rather
| than globally, so one caller cannot lock out another, and that each endpoint
| has its own budget rather than sharing one.
|
*/

/**
 * The throttle middleware string attached to a named route, if it has one.
 */
function declaredThrottle(string $routeName): ?string
{
    $route = RouteFacade::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull("route [{$routeName}] is not registered");

    /** @var Route $route */
    foreach ($route->gatherMiddleware() as $middleware) {
        if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
            return $middleware;
        }
    }

    return null;
}

/**
 * An account that reaches the editor AI group: signed in, verified, authoring.
 */
function throttledAuthor(): User
{
    return User::factory()->create();
}

test('the editor ai endpoints declare the limits their docblock promises', function (): void {
    // Preview is pure local rendering — markdown to HTML, no provider call —
    // so it carries the loosest limit of the group. The four that reach a model
    // are tighter, and image generation is tightest because it is the expensive
    // one. `capabilities` returns static configuration and is deliberately not
    // limited; pinning that too, so "no throttle" stays a decision rather than
    // becoming an omission.
    expect(declaredThrottle('editor.ai.capabilities'))->toBeNull()
        ->and(declaredThrottle('editor.ai.preview'))->toBe('throttle:120,1')
        ->and(declaredThrottle('editor.ai.correct'))->toBe('throttle:20,1')
        ->and(declaredThrottle('editor.ai.image-prompt'))->toBe('throttle:15,1')
        ->and(declaredThrottle('editor.ai.generate'))->toBe('throttle:10,1')
        ->and(declaredThrottle('editor.ai.image'))->toBe('throttle:5,1');
});

test('the image endpoint stops a caller after five requests in a minute', function (): void {
    $author = throttledAuthor();

    $statuses = [];

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $statuses[] = $this->actingAs($author)
            ->postJson(route('editor.ai.image'), ['prompt' => 'a diagram'])
            ->getStatusCode();
    }

    // Whatever the five accepted attempts did — this suite runs without a
    // provider key, so they answered 503 — the sixth is refused by the
    // limiter, which is the only thing being tested here.
    expect(array_slice($statuses, 0, 5))->not->toContain(429)
        ->and($statuses[5])->toBe(429);
});

test('the image budget belongs to one account and does not reach the next', function (): void {
    $first = throttledAuthor();
    $second = throttledAuthor();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->actingAs($first)->postJson(route('editor.ai.image'), ['prompt' => 'a diagram']);
    }

    $this->actingAs($first)
        ->postJson(route('editor.ai.image'), ['prompt' => 'a diagram'])
        ->assertStatus(429);

    // An account cannot be locked out of image generation by somebody else's
    // keystrokes. The default key for an authenticated request is the account
    // id, which is the property this asserts.
    expect($this->actingAs($second)
        ->postJson(route('editor.ai.image'), ['prompt' => 'a diagram'])
        ->getStatusCode())->not->toBe(429);
});

test('each editor endpoint keeps its own budget rather than sharing one', function (): void {
    $author = throttledAuthor();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->actingAs($author)->postJson(route('editor.ai.image'), ['prompt' => 'a diagram']);
    }

    $this->actingAs($author)
        ->postJson(route('editor.ai.image'), ['prompt' => 'a diagram'])
        ->assertStatus(429);

    // Exhausting the expensive endpoint must not stop the cheap rendering one,
    // or the editor's preview pane would go dead for the rest of the minute.
    $this->actingAs($author)
        ->postJson(route('editor.ai.preview'), ['markdown' => '# Heading'])
        ->assertOk();
});

test('a throttled editor request is refused before its payload is validated', function (): void {
    $author = throttledAuthor();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->actingAs($author)->postJson(route('editor.ai.image'), ['prompt' => 'a diagram']);
    }

    // A 422 rather than a 429 would mean the controller ran, which is the whole
    // point of the limiter sitting in front of it: an over-budget caller must
    // not be able to keep paying for validation work.
    $this->actingAs($author)
        ->postJson(route('editor.ai.image'), [])
        ->assertStatus(429);
});

test('the login limiter stops the sixth attempt for one email from one address', function (): void {
    $user = User::factory()->create(['email' => 'throttle-target@example.test']);

    $statuses = [];

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $statuses[] = $this->post(route('login.store'), [
            'email' => 'throttle-target@example.test',
            'password' => 'wrong-password',
        ])->getStatusCode();
    }

    expect($statuses[5])->toBe(429)
        ->and(array_slice($statuses, 0, 5))->not->toContain(429);

    // The refused account can still sign in once the window passes; the limiter
    // is not a lockout.
    $this->travel(61)->seconds();

    expect($this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->getStatusCode())->not->toBe(429);
});

test('the login limiter keys on the email as well as the address', function (): void {
    User::factory()->create(['email' => 'throttle-neighbour@example.test']);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->post(route('login.store'), [
            'email' => 'throttle-target@example.test',
            'password' => 'wrong-password',
        ]);
    }

    $this->post(route('login.store'), [
        'email' => 'throttle-target@example.test',
        'password' => 'wrong-password',
    ])->assertStatus(429);

    // Every account behind one office NAT address would be locked out by this
    // loop if the key were the IP alone.
    expect($this->post(route('login.store'), [
        'email' => 'throttle-neighbour@example.test',
        'password' => 'wrong-password',
    ])->getStatusCode())->not->toBe(429);
});

test('the two factor challenge limiter stops the sixth attempt in a minute', function (): void {
    $this->skipUnlessFortifyHas('two-factor-authentication');

    expect(Features::enabled('two-factor-authentication'))->toBeTrue();

    $user = User::factory()->withTwoFactor()->create();

    // The challenge state Fortify's own login redirect would have written.
    $this->withSession(['login.id' => $user->getKey(), 'login.remember' => false]);

    $statuses = [];

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $statuses[] = $this->post(route('two-factor.login.store'), [
            'code' => '000000',
        ])->getStatusCode();
    }

    expect($statuses[5])->toBe(429)
        ->and(array_slice($statuses, 0, 5))->not->toContain(429, 'the challenge accepted a wrong code');

    $this->assertGuest();
});

test('the limiter definitions the routes name actually resolve', function (): void {
    // `throttle:login` and `throttle:two-factor` name limiters rather than
    // numbers. A limiter name with no `RateLimiter::for` definition is not a
    // missing limit — it is a 500 on every request to that route, so the name
    // and the definition have to be pinned together.
    foreach (['login', 'two-factor', 'passkeys', 'feed-publish'] as $name) {
        expect(RateLimiterFacade::limiter($name))
            ->not->toBeNull("limiter [{$name}] is not defined");
    }
});
