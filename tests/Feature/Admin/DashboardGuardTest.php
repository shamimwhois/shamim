<?php

use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The admin dashboard's own guard
|--------------------------------------------------------------------------
|
| This is the landing surface of the admin rail, and it reads the whole account
| table: role counts, total accounts, and six recently created rows rendered
| with their email addresses. It is also the only page in that rail that was
| relying on the route's `permission:admin:access` alone.
|
| A Livewire request is a POST to `/livewire/update`. It does not pass through
| the route, so it does not pass through that middleware either. Every other
| admin console re-states the requirement in `boot()`; this file pins that the
| dashboard does too, and — the part a status code alone would not prove — that
| the account rows are absent from the response rather than merely not linked.
|
*/

test('guests are bounced to the sign-in dialog', function () {
    $this->get('/admin/dashboard')->assertRedirect(route('home', ['signin' => 'required']));
});

test('accounts without admin:access are refused the dashboard over http', function () {
    User::factory()->create(['name' => 'Plain Person', 'email' => 'plain@example.test']);

    $this->actingAs(User::factory()->create())
        ->get('/admin/dashboard')
        ->assertForbidden();
});

test('an account without admin:access cannot read the account table through the endpoint', function () {
    User::factory()->create(['name' => 'Quiet Account', 'email' => 'quiet@example.test']);

    // The guard lives in the component, so this is the request shape that
    // would have succeeded before it did: a signed-in ordinary account posting
    // to the Livewire endpoint with the component name and nothing else.
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.dashboard')
        ->assertForbidden()
        ->assertDontSee('Quiet Account')
        ->assertDontSee('quiet@example.test');
});

test('an admin lands on the platform overview and sees the accounts it owns', function () {
    User::factory()->create(['name' => 'Newest Person', 'email' => 'newest@example.test']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.dashboard')
        ->assertOk()
        ->assertSee('Newest Person')
        ->assertSee('newest@example.test');
});
