<?php

namespace App\Concerns;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\RolePlan;
use App\Models\User;

/**
 * Account-level role and permission behaviour.
 *
 * This trait answers a single question: "what may this account do at all",
 * independent of any other account. It is the only authorisation axis in the
 * application — the team-scoped role axis and the team feature itself were
 * removed, so there is no longer a second, narrower role to reconcile against.
 *
 * @property AccountRole $role
 */
trait HasAccountRole
{
    /**
     * Get the account's role.
     *
     * The `role` column is cast to {@see AccountRole}, so this accessor exists
     * for the null-safe reads below rather than to transform the value.
     */
    public function accountRole(): AccountRole
    {
        return $this->role;
    }

    /**
     * Get the plan attached to the account's role.
     */
    public function plan(): RolePlan
    {
        return $this->accountRole()->plan();
    }

    /**
     * Determine whether the account has the given permission.
     */
    public function hasPermission(AccountPermission $permission): bool
    {
        return $this->accountRole()->hasPermission($permission);
    }

    /**
     * Determine whether the account has every given permission.
     */
    public function hasAllPermissions(AccountPermission ...$permissions): bool
    {
        return $this->accountRole()->hasAllPermissions(...$permissions);
    }

    /**
     * Determine whether the account has at least one of the given permissions.
     */
    public function hasAnyPermission(AccountPermission ...$permissions): bool
    {
        return $this->accountRole()->hasAnyPermission(...$permissions);
    }

    /**
     * Determine whether the account holds exactly the given role.
     */
    public function hasRole(AccountRole $role): bool
    {
        return $this->accountRole() === $role;
    }

    /**
     * Determine whether the account's role is at least as privileged as the given one.
     */
    public function isAtLeastRole(AccountRole $role): bool
    {
        return $this->accountRole()->isAtLeast($role);
    }

    /**
     * Determine whether the account is a plain user.
     */
    public function isUser(): bool
    {
        return $this->hasRole(AccountRole::User);
    }

    /**
     * Determine whether the account is a seller.
     */
    public function isSeller(): bool
    {
        return $this->hasRole(AccountRole::Seller);
    }

    /**
     * Determine whether the account is an administrator.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(AccountRole::Admin);
    }

    /**
     * Determine whether the account may reach the admin panel.
     */
    public function canAccessAdminPanel(): bool
    {
        return $this->accountRole()->canAccessAdminPanel();
    }

    /**
     * Determine whether the account may act on a resource owned by `$owner`.
     *
     * This is the single rule every ownership check should route through: an
     * account may act on its own records, and an admin may act on anyone's.
     * Stating it once means the "admins can do anything" clause cannot be
     * forgotten in one policy and remembered in another.
     *
     * Guards against a self-referential comparison that would let an account
     * act on a record with no owner.
     */
    public function canActOn(mixed $owner): bool
    {
        if ($owner instanceof self) {
            return $owner->is($this) || $this->isAdmin();
        }

        return $this->isAdmin();
    }

    /**
     * Get the route name of this account's dashboard home.
     */
    public function dashboardRoute(): string
    {
        return $this->accountRole()->dashboardRoute();
    }
}
