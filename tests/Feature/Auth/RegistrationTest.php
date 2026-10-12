<?php

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

    // Registration lands on the new user's personal team dashboard.
    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('team.dashboard', ['current_team' => $user->personalTeam()->slug]));

    $this->assertAuthenticated();
});
