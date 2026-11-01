<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

test('the page previews the signature the log will print', function () {
    $user = User::factory()->create(['name' => 'Amara Diallo', 'handle' => 'amara']);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->assertSee('data-test="byline-preview"', false)
        ->assertSee('Amara Diallo')
        ->assertSee('@amara')
        // The role a commit's byline carries is the account's, not an editable field.
        ->assertSee($user->accountRole()->label());
});

test('a changed name changes the preview before it is saved', function () {
    $user = User::factory()->create(['handle' => 'amara']);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Renamed Person')
        ->assertSee('Renamed Person', false);
});

test('a handle can be checked before it is claimed', function () {
    User::factory()->create(['handle' => 'taken-name']);
    $user = User::factory()->create(['handle' => 'mine']);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('handle', 'taken-name')
        ->call('checkHandle')
        ->assertSet('handleStatus', 'taken')
        ->set('handle', 'free-name')
        ->call('checkHandle')
        ->assertSet('handleStatus', 'free')
        // The account's own handle is nothing to announce.
        ->set('handle', 'mine')
        ->call('checkHandle')
        ->assertSet('handleStatus', null)
        ->set('handle', 'bad handle with spaces')
        ->call('checkHandle')
        ->assertSet('handleStatus', 'invalid');
});
