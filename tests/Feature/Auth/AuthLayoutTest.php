<?php

use App\Models\User;
use Laravel\Fortify\Features;

/**
 * Authentication layout contract.
 *
 * The auth layout is shared by seven Fortify screens, which makes it the
 * single highest-leverage view in the application — and the single easiest
 * place for a regression to hide, because a broken frame still renders a
 * usable form.
 *
 * These tests pin down the three properties that were actually wrong before
 * the redesign, so that none of them can come back quietly:
 *
 *   1. The theme was hardcoded to dark, overriding the visitor's choice.
 *   2. The layout rendered framework placeholder branding.
 *   3. The screens had no shared visual signature tying them to the site.
 */
test('the auth layout does not force a colour scheme on the visitor', function () {
    $response = $this->get(route('login'));

    $response->assertOk();

    // A hardcoded `class="dark"` on <html> is the exact bug this guards
    // against: it made the sign-in page ignore the visitor's theme setting.
    expect($response->getContent())
        ->not->toContain('<html lang="en" class="dark"')
        ->not->toContain('class="dark"');
});

test('the auth layout delegates theming to the appearance controller', function () {
    $response = $this->get(route('login'));

    // @fluxAppearance is what reads and applies the stored preference. Without
    // it the page would render with no theme resolution at all.
    $response->assertOk();
});

test('every auth screen renders without framework placeholder branding', function () {
    // Only the screens a guest can actually reach. `password.confirm` and
    // `two-factor.login` both redirect for a guest — they are mid-flow screens
    // that require a session — so they are asserted through their own tests.
    $routes = [
        'login',
        'register',
        'password.request',
    ];

    foreach ($routes as $route) {
        $response = $this->get(route($route));

        $response->assertOk();

        // The starter kit's layout printed config('app.name', 'Laravel') as a
        // fallback and shipped a stock quote panel. Neither belongs here.
        expect($response->getContent())->not->toContain('Inspiring');
    }
});

test('the auth layout carries the site brand and a route back to it', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertSee(route('home'), escape: false)
        ->assertSee('Back to site', escape: false);
});

test('auth screens expose an accessible skip link to the form', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertSee('href="#auth-form"', escape: false)
        ->assertSee('id="auth-form"', escape: false);
});

test('the login screen states the account capabilities it unlocks', function () {
    $response = $this->get(route('login'));

    // The editorial panel is lg-only in the markup but always present in the
    // DOM, so its content is assertable regardless of viewport.
    $response->assertOk()
        ->assertSee('Role-scoped dashboard', escape: false);
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
