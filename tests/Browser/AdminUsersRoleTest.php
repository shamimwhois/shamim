<?php

/**
 * Handing out a role, from the row that offers it.
 *
 * `Admin\UsersTest` already proves the rules: an actor cannot assign a role above
 * their own, cannot change their own, and the route refuses anybody without
 * `AccessAdminPanel`. Those are all questions about the request. The question this
 * file asks is about the widget, and it is the one a native `<select>` driven by
 * `wire:change` has always been a hazard for: after Livewire re-renders the row in
 * answer to the change it just accepted, does the control still show the role the
 * account now has, or does it snap back to whatever the option list was built with?
 *
 * A snapped-back select is not a cosmetic problem. The next read of that row is the
 * role nobody chose, the admin sees the old value, sets it again, and the second
 * write is the one that lands — with a first write in the history nothing explains.
 * So the assertion is made twice: once against the DOM the browser is looking at,
 * and once against the committed row the other process wrote.
 *
 * The self row is checked too, and for the same reason: it is the only row on the
 * page where the absence of a control is the safety property.
 */

use App\Enums\AccountRole;
use App\Models\User;
use Laravel\Dusk\Browser;

it('changes an account’s role and leaves the picker showing the role it changed to', function () {
    /** @var User $admin */
    $admin = User::query()->where('email', 'test@example.com')->firstOrFail();

    /** @var User $target */
    $target = User::query()->where('email', 'user@example.com')->firstOrFail();

    $target->forceFill(['role' => AccountRole::User->value])->save();

    $selector = '[data-test="role-select-'.$target->id.'"]';

    $this->browse(function (Browser $browser) use ($admin, $target, $selector) {
        $browser
            ->loginAs($admin)
            ->visit('/admin/users')
            ->waitFor($selector)
            ->assertSelected($selector, AccountRole::User->value)
            ->select($selector, AccountRole::Seller->value)
            // The row is re-rendered by the response to the change. `waitFor` on the
            // same selector is what gives that response time to arrive before the
            // value is read back off the control.
            ->pause(500)
            ->assertSelected($selector, AccountRole::Seller->value);

        // The row the served process wrote, read from this one: the picker and the
        // database have to agree, or the page is showing an answer to a question
        // nobody asked.
        expect($target->fresh()->accountRole())->toBe(AccountRole::Seller);

        // Leave the seed data as it was found, because every later Dusk run and the
        // hand-inspection that follows this one read the same committed file.
        $browser->select($selector, AccountRole::User->value)
            ->pause(500)
            ->assertSelected($selector, AccountRole::User->value);

        expect($target->fresh()->accountRole())->toBe(AccountRole::User);
    });
});

it('will not offer the admin a picker for their own account', function () {
    /** @var User $admin */
    $admin = User::query()->where('email', 'test@example.com')->firstOrFail();

    $this->browse(function (Browser $browser) use ($admin) {
        $browser
            ->loginAs($admin)
            ->visit('/admin/users')
            // Searching the admin out is what puts their own row on screen: the table
            // is paginated, and the row that must have no picker might be anywhere in
            // twenty-one accounts.
            ->type('#user-search', $admin->email)
            ->pause(800)
            ->assertSee($admin->name)
            ->assertMissing('[data-test="role-select-'.$admin->id.'"]');
    });
});
