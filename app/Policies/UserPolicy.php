<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\User;

/**
 * Account management policy.
 *
 * Registered by Laravel's automatic policy discovery: `App\Models\User` maps to
 * `App\Policies\UserPolicy`. Every ability here reduces to an account-level
 * permission, so this class contains no role names at all — adding a fourth
 * role later is a change to {@see AccountRole} and nothing here.
 */
class UserPolicy
{
    /**
     * Determine whether the user can view the account list.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(AccountPermission::ManageUsers);
    }

    /**
     * Determine whether the user can view a specific account.
     */
    public function view(User $user, User $account): bool
    {
        return $user->is($account)
            || $user->hasPermission(AccountPermission::ManageUsers);
    }

    /**
     * Determine whether the user can change account roles.
     *
     * The per-target rules — no self-editing, no editing a peer — live in the
     * component, because they depend on the *target* account and this ability
     * answers a blanket question. Both checks run; this one gates the surface.
     */
    public function updateRole(User $user): bool
    {
        return $user->hasPermission(AccountPermission::ManageRoles);
    }

    /**
     * Determine whether the user can update an account.
     */
    public function update(User $user, User $account): bool
    {
        return $user->is($account)
            || $user->hasPermission(AccountPermission::ManageUsers);
    }

    /**
     * Determine whether the user can suspend or delete an account.
     *
     * An account may never delete itself through this path — self-deletion is a
     * deliberate flow with its own confirmation, not an admin action — and an
     * admin may never remove another admin.
     */
    public function delete(User $user, User $account): bool
    {
        if ($user->is($account)) {
            return false;
        }

        if ($account->accountRole()->isAtLeast($user->accountRole())) {
            return false;
        }

        return $user->hasPermission(AccountPermission::ManageUsers);
    }
}
