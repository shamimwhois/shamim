<?php

use App\Enums\AccountRole;
use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
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
