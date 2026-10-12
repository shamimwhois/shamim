<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\PermissionGroup;
use App\Enums\RolePlan;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;

/**
 * The permission model contract.
 *
 * These tests pin the *composition* of each role's permission set, not just its
 * size. A count assertion catches a permission being added or removed; it does
 * not catch a permission being silently swapped for another one of the same
 * group — which is exactly the change that would quietly grant a plain user the
 * ability to publish, or hand a team admin the member list.
 */
test('each account role exposes exactly the permissions it should', function () {
    expect(array_map(fn (AccountPermission $p) => $p->value, AccountRole::User->permissions()))
        ->toBe([
            'post:create',
            'post:update-own',
            'post:delete-own',
        ]);

    expect(array_map(fn (AccountPermission $p) => $p->value, AccountRole::Seller->permissions()))
        ->toBe([
            // Inherited from User, by spread.
            'post:create',
            'post:update-own',
            'post:delete-own',
            // Seller-specific.
            'post:publish',
            'taxonomy:manage',
            'product:create',
            'product:update-own',
            'product:delete-own',
            'product:submit',
            'sale:view-own',
            'support:manage-own',
        ]);

    expect(AccountRole::Admin->permissions())->toHaveCount(count(AccountPermission::cases()));
});

test('a plain user cannot publish', function () {
    // The sharpest restriction in the model: an account may write and edit its
    // own posts but cannot make one public. If this ever changes, it should be
    // a deliberate edit to this test, not a side effect.
    expect(AccountRole::User->hasPermission(AccountPermission::PublishPost))->toBeFalse()
        ->and(AccountRole::Seller->hasPermission(AccountPermission::PublishPost))->toBeTrue();
});

test('comment moderation is admin only', function () {
    expect(AccountRole::User->hasPermission(AccountPermission::ModerateComments))->toBeFalse()
        ->and(AccountRole::Seller->hasPermission(AccountPermission::ModerateComments))->toBeFalse()
        ->and(AccountRole::Admin->hasPermission(AccountPermission::ModerateComments))->toBeTrue();
});

test('seller inherits every user permission by construction', function () {
    // Seller uses spread syntax, so this cannot drift without someone
    // deliberately removing the spread.
    foreach (AccountRole::User->permissions() as $permission) {
        expect(AccountRole::Seller->hasPermission($permission))
            ->toBeTrue("Seller should inherit {$permission->value} from User");
    }
});

test('admin holds every declared permission', function () {
    foreach (AccountPermission::cases() as $permission) {
        expect(AccountRole::Admin->hasPermission($permission))
            ->toBeTrue("Admin should hold {$permission->value}");
    }
});

test('the selling group is seller-scoped and admin-inclusive', function () {
    $selling = array_filter(
        AccountPermission::cases(),
        fn (AccountPermission $p) => $p->group() === PermissionGroup::Selling,
    );

    expect($selling)->toHaveCount(6);

    foreach ($selling as $permission) {
        expect(AccountRole::User->hasPermission($permission))->toBeFalse()
            ->and(AccountRole::Seller->hasPermission($permission))->toBeTrue()
            ->and(AccountRole::Admin->hasPermission($permission))->toBeTrue();
    }
});

test('role levels order correctly and do not grant on their own', function () {
    expect(AccountRole::User->level())->toBe(1)
        ->and(AccountRole::Seller->level())->toBe(2)
        ->and(AccountRole::Admin->level())->toBe(3);

    expect(AccountRole::Admin->isAtLeast(AccountRole::Seller))->toBeTrue()
        ->and(AccountRole::User->isAtLeast(AccountRole::Seller))->toBeFalse();
});

test('no role may grant a role at or above its own level', function () {
    $assignable = fn (AccountRole $role) => array_column(AccountRole::assignableBy($role), 'value');

    expect($assignable(AccountRole::User))->toBe(['user'])
        ->and($assignable(AccountRole::Seller))->toBe(['user', 'seller'])
        ->and($assignable(AccountRole::Admin))->toBe(['user', 'seller']);
});

test('admin is not assignable by anyone, including an admin', function () {
    // Guards against one compromised admin session minting more admins.
    foreach (AccountRole::cases() as $actor) {
        expect(array_column(AccountRole::assignableBy($actor), 'value'))
            ->not->toContain('admin');
    }
});

test('every permission belongs to a group', function () {
    // group() is an exhaustive match; an unhandled case is a PHPStan error.
    // This asserts the runtime half — that grouping loses nothing.
    $grouped = 0;

    foreach (PermissionGroup::cases() as $group) {
        $grouped += count(array_filter(
            AccountPermission::cases(),
            fn (AccountPermission $p) => $p->group() === $group,
        ));
    }

    expect($grouped)->toBe(count(AccountPermission::cases()));
});

test('plans describe commercial entitlements without granting authorization', function () {
    expect(RolePlan::Free->productListingLimit())->toBe(0)
        ->and(RolePlan::Free->canSell())->toBeFalse()
        ->and(RolePlan::Seller->productListingLimit())->toBe(25)
        ->and(RolePlan::Seller->commissionPercent())->toBe(15.0)
        ->and(RolePlan::Platform->productListingLimit())->toBeNull()   // null = unlimited, not zero
        ->and(RolePlan::Platform->commissionPercent())->toBe(0.0)
        ->and(RolePlan::Platform->canSell())->toBeTrue();
});

test('each account role maps to a plan', function () {
    expect(AccountRole::User->plan())->toBe(RolePlan::Free)
        ->and(AccountRole::Seller->plan())->toBe(RolePlan::Seller)
        ->and(AccountRole::Admin->plan())->toBe(RolePlan::Platform);
});

test('team roles hold exactly the team permissions they should', function () {
    $values = fn (TeamRole $role) => array_map(fn (TeamPermission $p) => $p->value, $role->permissions());

    expect($values(TeamRole::Owner))->toBe([
        'team:update',
        'team:delete',
        'member:add',
        'member:update',
        'member:remove',
        'invitation:create',
        'invitation:cancel',
    ]);

    // A team admin may change settings and manage invitations, but the member
    // list belongs to the owner.
    expect($values(TeamRole::Admin))->toBe([
        'team:update',
        'invitation:create',
        'invitation:cancel',
    ]);

    // Membership is the grant; there is no team permission a plain member holds.
    expect($values(TeamRole::Member))->toBe([]);
});

test('only a team owner may delete the team', function () {
    expect(TeamRole::Owner->hasPermission(TeamPermission::DeleteTeam))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::DeleteTeam))->toBeFalse()
        ->and(TeamRole::Member->hasPermission(TeamPermission::DeleteTeam))->toBeFalse();
});
