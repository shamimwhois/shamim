<?php

use App\Enums\AccountRole;
use App\Models\User;

test('registration screen can be rendered', function () {
    // Registration is modal-first: the URL bounces to the flagged home, where
    // the dialog opens server-side. No standalone register page exists.
    $this->get(route('register'))->assertRedirect(route('home', ['signup' => 'required']));
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    // Registration lands on the role dashboard. New accounts are always
    // created as plain users, so that is the author workspace at /dashboard.
    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard.index'));

    expect($user->role)->toBe(AccountRole::User);

    $this->assertAuthenticated();
});
