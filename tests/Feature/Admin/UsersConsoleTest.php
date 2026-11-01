<?php

use App\Enums\AccountRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The accounts console
|--------------------------------------------------------------------------
|
| This is the one admin screen whose whole job is a filtered, paginated list,
| and the filters are the part that was untested. They are also the part that
| was broken: `updatedSearch()`, `updatedRoleFilter()` and `clearFilters()` all
| call `resetPage()`, which only exists on a component that uses
| `Livewire\WithPagination` — and this class did not. So every filter change
| answered with `BadMethodCallException: Method … resetPage does not exist`,
| while the page itself rendered happily and the role picker (tested in the
| browser) kept working. A list you cannot narrow is not much of a console, and
| nothing in the suite drove a keystroke through it.
|
| The reset is worth pinning on its own, not merely the absence of a throw. A
| filter that shrinks the result set below the page you are standing on leaves
| an empty table with no explanation; `resetPage()` is what stops that, and it
| is invisible unless a test walks to page two first.
|
*/

/**
 * Sign in as an admin who can open the console, and return them.
 */
function consoleActor(): User
{
    $actor = User::factory()->admin()->create(['name' => 'Console Actor']);

    test()->actingAs($actor);

    return $actor;
}

test('the accounts console lists every account regardless of who owns it', function () {
    $actor = consoleActor();
    $stranger = User::factory()->create(['name' => 'Ola Strand', 'email' => 'ola@strand.example']);

    Livewire::test('pages::admin.users')
        ->assertSee($actor->name)
        ->assertSee($stranger->name);
})->group('security');

test('a search narrows the list instead of throwing', function () {
    consoleActor();
    User::factory()->count(25)->create(['name' => 'Archive Reader']);
    $found = User::factory()->create(['name' => 'Widow Maker', 'email' => 'widow@example.test']);

    // Before the trait was added this line raised BadMethodCallException out of
    // `updatedSearch()`, which Livewire reports as a failed request rather than
    // a validation error — the loudest kind of bug a silent page can have.
    Livewire::test('pages::admin.users')
        ->set('search', 'Widow')
        ->assertSee($found->name)
        ->assertDontSee('Archive Reader');
})->group('security');

test('a filter pulls a reader standing on page two back to page one', function () {
    consoleActor();
    User::factory()->count(25)->create(['name' => 'Archive Reader']);
    User::factory()->create(['name' => 'Widow Maker', 'email' => 'widow@example.test']);

    Livewire::test('pages::admin.users')
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('search', 'Widow')
        // The reset is the whole point: page two of one row does not exist, and
        // staying there would render an empty table under a filter that matches.
        ->assertSet('paginators.page', 1);
})->group('security');

test('the role filter keeps only the accounts that carry it', function () {
    consoleActor();
    $sellers = User::factory()->count(2)->seller()->create();
    User::factory()->count(3)->create(['name' => 'Plain Reader']);

    $accounts = Livewire::test('pages::admin.users')
        ->set('roleFilter', AccountRole::Seller->value)
        ->instance()
        ->accounts();

    expect($accounts->pluck('id')->sort()->values()->all())
        ->toBe($sellers->pluck('id')->sort()->values()->all());
})->group('security');

test('clearing the filters empties both fields and starts the list over', function () {
    consoleActor();
    User::factory()->count(25)->create(['name' => 'Archive Reader']);

    Livewire::test('pages::admin.users')
        ->set('search', 'Archive')
        ->set('roleFilter', AccountRole::User->value)
        ->call('gotoPage', 2)
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('roleFilter', 'all')
        ->assertSet('paginators.page', 1);
})->group('security');

test('a filtered list arrives through the URL as well as the controls', function () {
    consoleActor();
    $seller = User::factory()->seller()->create(['name' => 'Brick Ledger', 'email' => 'brick@ledger.example']);
    User::factory()->create(['name' => 'Plain Reader']);

    // The chip row writes `?role=` and an admin pastes that link into a ticket,
    // so the query string has to produce the same list the control does.
    $this->get('/admin/users?role=seller')
        ->assertOk()
        ->assertSee($seller->name)
        ->assertDontSee('Plain Reader');
})->group('security');

test('the chip row counts each role rather than the filtered page', function () {
    consoleActor();
    User::factory()->count(2)->seller()->create();
    User::factory()->count(3)->create(['name' => 'Plain Reader']);

    $counts = collect(Livewire::test('pages::admin.users')->instance()->roleCounts())
        ->mapWithKeys(fn (array $row): array => [$row['role']->value => $row['count']]);

    expect($counts[AccountRole::Seller->value])->toBe(2)
        ->and($counts[AccountRole::User->value])->toBe(3)
        // The actor is the only admin in this database.
        ->and($counts[AccountRole::Admin->value])->toBe(1);
})->group('security');

/*
|--------------------------------------------------------------------------
| Create / edit / delete
|--------------------------------------------------------------------------
|
| The editor writes accounts, so its tests are mostly about the two guards
| that keep it from becoming an escalation path: the role an admin may hand
| out ({@see AccountRole::assignableBy()}), and the accounts an admin may
| touch (peer-or-above is refused by {@see \App\Policies\UserPolicy}).
|
*/

test('an admin can create an account with an assignable role', function () {
    consoleActor();

    Livewire::test('pages::admin.users.edit')
        ->set('name', 'New Recruit')
        ->set('handle', 'recruit')
        ->set('email', 'recruit@example.test')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->set('role', AccountRole::Seller->value)
        ->call('save')
        ->assertRedirect(route('admin.users'));

    $created = User::query()->where('email', 'recruit@example.test')->first();

    expect($created)->not->toBeNull()
        ->and($created->accountRole())->toBe(AccountRole::Seller)
        ->and($created->email_verified_at)->toBeNull()
        ->and(Hash::check('password', $created->password))->toBeTrue();
})->group('security');

test('the editor never offers the admin role, and refuses it outright', function () {
    consoleActor();

    // Even if the property is set directly — bypassing the filtered picker —
    // validation refuses it, and the action behind it would refuse again.
    Livewire::test('pages::admin.users.edit')
        ->set('name', 'Would-be Admin')
        ->set('email', 'wannabe@example.test')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->set('role', AccountRole::Admin->value)
        ->call('save')
        ->assertHasErrors(['role']);

    expect(User::query()->where('email', 'wannabe@example.test')->exists())->toBeFalse();
})->group('security');

test('an admin can edit a lower account without touching its password', function () {
    $actor = consoleActor();
    $target = User::factory()->create(['name' => 'Plain Reader', 'handle' => null]);
    $originalHash = $target->password;

    Livewire::test('pages::admin.users.edit', ['user' => $target])
        ->set('name', 'Renamed Reader')
        ->set('handle', 'renamed')
        ->set('email', 'reader@example.test')
        ->call('save')
        ->assertRedirect(route('admin.users'));

    $target->refresh();

    expect($target->name)->toBe('Renamed Reader')
        ->and($target->handle)->toBe('renamed')
        ->and($target->email)->toBe('reader@example.test')
        // A blank password field means "leave it alone".
        ->and($target->password)->toBe($originalHash);
})->group('security');

test('a filled password on the edit form replaces the hash', function () {
    consoleActor();
    $target = User::factory()->create(['name' => 'Plain Reader']);

    Livewire::test('pages::admin.users.edit', ['user' => $target])
        ->set('password', 's3cret-password')
        ->set('password_confirmation', 's3cret-password')
        ->call('save');

    expect(Hash::check('s3cret-password', $target->refresh()->password))->toBeTrue();
})->group('security');

test('an admin cannot open the editor for a peer admin', function () {
    $actor = consoleActor();
    $peer = User::factory()->admin()->create(['name' => 'Other Admin']);

    $this->get("/admin/users/{$peer->id}/edit")->assertForbidden();

    Livewire::test('pages::admin.users.edit', ['user' => $peer])
        ->assertForbidden();
})->group('security');

test('the list deletes a lower account', function () {
    consoleActor();
    $target = User::factory()->create(['name' => 'Doomed Reader']);

    Livewire::test('pages::admin.users')
        ->call('delete', $target->id)
        ->assertHasNoErrors();

    expect(User::query()->find($target->id))->toBeNull();
})->group('security');

test('an admin cannot delete themselves or a peer admin', function () {
    $actor = consoleActor();
    $peer = User::factory()->admin()->create(['name' => 'Other Admin']);

    Livewire::test('pages::admin.users')
        ->call('delete', $actor->id)
        ->assertForbidden();

    Livewire::test('pages::admin.users')
        ->call('delete', $peer->id)
        ->assertForbidden();

    expect(User::query()->find($actor->id))->not->toBeNull()
        ->and(User::query()->find($peer->id))->not->toBeNull();
})->group('security');
