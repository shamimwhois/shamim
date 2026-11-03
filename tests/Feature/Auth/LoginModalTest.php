<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Login modal
|--------------------------------------------------------------------------
|
| The authentication prompt is a modal dialog rendered on every guest-facing
| surface, opened three ways: a protected route bounce (the `?signin=required`
| flag), a failed submit (validation `$errors`), or a click on a guest control.
| It is the real Fortify login form — a plain POST to `login.store` — so this
| suite exercises that the modal submits to the same endpoint, rejects bad
| credentials with the same errors, and accepts good ones into the role's
| dashboard. The client-side open state is covered by the Playwright run; the
| PHP assertions here pin the contract the browser relies on.
|
*/

test('the login modal renders on public surfaces with the real form', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('action="'.route('login.store').'"', escape: false)
        ->assertSee('data-test="login-modal-email"', escape: false)
        ->assertSee('data-test="login-modal-password"', escape: false)
        ->assertSee('data-test="login-modal-submit"', escape: false)
        ->assertSee('Forgot your password?', escape: false)
        ->assertSee('Sign up', escape: false);
});

test('the modal is absent for signed-in accounts', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('data-test="login-modal-form"', escape: false);
});

test('the modal submits to the real login endpoint and rejects bad credentials', function () {
    $user = User::factory()->create();

    // Posting the modal's form with the wrong password must land back on the
    // same page (the dialog reopens via `$errors`) carrying the validation
    // message and the typed email — never silently succeed.
    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertRedirect();

    $this->followRedirects($response)
        ->assertSee('data-test="login-modal-form"', escape: false)
        ->assertSee($user->email, escape: false)
        ->assertSee('These credentials do not match', escape: false);
});

test('the modal authenticates and lands on the role dashboard', function () {
    $this->post(route('login.store'), [
        'email' => User::factory()->create()->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard.index'));

    $this->assertAuthenticated();
});

test('a seller lands on their own dashboard through the modal', function () {
    $this->post(route('login.store'), [
        'email' => User::factory()->seller()->create()->email,
        'password' => 'password',
    ])->assertRedirect(route('seller.dashboard'));

    $this->assertAuthenticated();
});

test('an admin lands on their own dashboard through the modal', function () {
    $this->post(route('login.store'), [
        'email' => User::factory()->admin()->create()->email,
        'password' => 'password',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticated();
});

test('a guest hitting a protected route is bounced with the sign-in flag', function () {
    $response = $this->get(route('dashboard.index'))
        ->assertRedirect(route('home', ['signin' => 'required']));

    // The bounced page carries the modal — presence of the form is what the
    // client reads to open it; the open state itself is a Flux/Alpine concern
    // exercised by the Playwright run.
    $this->followRedirects($response)
        ->assertOk()
        ->assertSee('data-test="login-modal-form"', escape: false);
});

test('the attempted url survives the bounce as the intended destination', function () {
    $this->get(route('dashboard.index'));

    // `redirect()->guest()` records the refused URL before bouncing, so a
    // successful sign-in from the modal continues exactly where the guest was
    // refused rather than dumping them on the generic dashboard.
    expect(session('url.intended'))->toBe(url(route('dashboard.index')));
});

test('guest controls open the modal instead of navigating', function () {
    // The shell's Sign-in trigger carries the Flux open call and drops
    // `wire:navigate`, so Livewire's capture-phase navigate listener cannot
    // walk the guest through the dialog before it opens. The feed wears the
    // same public shell, so it answers with the same trigger.
    $this->get(route('home'))
        ->assertSee('data-test="sign-in-trigger"', escape: false)
        ->assertSee('$flux.modal(\'login\').show();', escape: false);

    $this->get(route('feed.index'))
        ->assertSee('data-test="sign-in-trigger"', escape: false)
        ->assertSee('$flux.modal(\'login\').show();', escape: false);
});

test('the protected nav item opens the modal for guests', function () {
    // Dev Lab is gated; its guest nav entry must not carry `wire:navigate`, or
    // the dialog would never open before the SPA navigation fires.
    $this->get(route('home'))
        ->assertSee('x-on:click="$flux.modal(\'login\').show(); $event.preventDefault()"', escape: false)
        ->assertSee('Dev Lab', escape: false);
});

test('the standalone login url redirects to the flagged home with the dialog open', function () {
    // Without JS the Sign-in trigger's href reaches /login, which bounces to
    // the flagged home whose server-side `show` binding opens the dialog —
    // the modal-first replacement for the deleted standalone page.
    $this->get(route('login'))->assertRedirect(route('home', ['signin' => 'required']));

    $this->followRedirects($this->get(route('login')))
        ->assertOk()
        ->assertSee('data-test="login-modal-form"', escape: false);
});

test('the register and forgot-password urls redirect to their flagged dialogs', function () {
    // The other two standalone auth pages were removed the same way: their
    // routes survive as redirects so no link or email breaks.
    $this->get(route('register'))->assertRedirect(route('home', ['signup' => 'required']));

    $this->get(route('password.request'))->assertRedirect(route('home', ['forgot' => 'required']));
});

test('the register modal renders the real registration form', function () {
    $this->get(route('home', ['signup' => 'required']))
        ->assertOk()
        ->assertSee('action="'.route('register.store').'"', escape: false)
        ->assertSee('data-test="register-modal-name"', escape: false)
        ->assertSee('data-test="register-modal-email"', escape: false)
        ->assertSee('data-test="register-modal-password"', escape: false)
        ->assertSee('data-test="register-modal-password-confirmation"', escape: false)
        ->assertSee('data-test="register-modal-submit"', escape: false)
        ->assertSee('name="auth_flow" value="register"', escape: false)
        // The marker is how the suite asserts server-side open state without
        // a browser: exactly one dialog is open on this page load.
        ->assertSee('data-modal-open="true"', escape: false);
});

test('the forgot-password modal renders the real reset-link form', function () {
    $this->get(route('home', ['forgot' => 'required']))
        ->assertOk()
        ->assertSee('action="'.route('password.email').'"', escape: false)
        ->assertSee('data-test="forgot-modal-email"', escape: false)
        ->assertSee('data-test="forgot-modal-submit"', escape: false)
        ->assertSee('data-modal-open="true"', escape: false);
});

test('no auth dialog is open on a plain page load', function () {
    expect(substr_count($this->get(route('home'))->getContent(), 'data-modal-open="true"'))->toBe(0);
});

test('a failed registration reopens the register dialog, not the login one', function () {
    // Login and registration share the `email` and `password` error keys, so
    // the hidden `auth_flow` field — flashed back through Fortify's
    // `withInput()` — is what decides which dialog reopens.
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'not-an-email',
        'password' => 'x',
        'password_confirmation' => 'y',
    ])->assertRedirect();

    $html = $this->followRedirects($response)->getContent();

    expect(substr_count($html, 'data-modal-open="true"'))->toBe(1)
        ->and($html)->toContain('name="auth_flow" value="register"')
        ->and($html)->toContain('The email field must be a valid email address');
});

test('a failed password-reset request reopens the forgot dialog', function () {
    $response = $this->post(route('password.email'), [
        'email' => 'not-an-email',
    ])->assertRedirect();

    $html = $this->followRedirects($response)->getContent();

    expect(substr_count($html, 'data-modal-open="true"'))->toBe(1)
        ->and($html)->toContain('name="auth_flow" value="forgot"');
});
