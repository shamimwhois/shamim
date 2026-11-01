<?php

namespace App\Enums;

/**
 * Account-level roles.
 *
 * Every account has exactly one role, stored on `users.role`. This is the
 * coarse, application-wide identity that decides which dashboard an account
 * lands on and which navigation it sees.
 *
 * It is the only role axis in the application. The fine-grained, team-scoped
 * role that once coexisted with it was removed along with the team feature, so
 * there is no longer a second, narrower role to keep in sync.
 *
 * Hierarchy (ascending privilege): User → Seller → Admin.
 */
enum AccountRole: string
{
    case User = 'user';
    case Seller = 'seller';
    case Admin = 'admin';

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::User => __('User'),
            self::Seller => __('Seller'),
            self::Admin => __('Admin'),
        };
    }

    /**
     * Get a one-line description of what the role is for.
     */
    public function description(): string
    {
        return match ($this) {
            self::User => __('Reads content, writes posts and manages their own account.'),
            self::Seller => __('Everything a user can do, plus publishing and selling products.'),
            self::Admin => __('Full access, including moderation, user management and platform settings.'),
        };
    }

    /**
     * Get the Flux badge colour token for the role.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::User => 'zinc',
            self::Seller => 'blue',
            self::Admin => 'purple',
        };
    }

    /**
     * Get the role's plan tier.
     */
    public function plan(): RolePlan
    {
        return match ($this) {
            self::User => RolePlan::Free,
            self::Seller => RolePlan::Seller,
            self::Admin => RolePlan::Platform,
        };
    }

    /**
     * Get every permission granted to this role.
     *
     * Seller deliberately *inherits* the user permission set rather than
     * restating it, so adding a baseline permission automatically reaches both
     * higher roles and the matrix cannot drift out of sync.
     *
     * @return array<AccountPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::User => [
                AccountPermission::CreatePost,
                AccountPermission::UpdateOwnPost,
                AccountPermission::DeleteOwnPost,

                AccountPermission::UseDeveloperLab,
                AccountPermission::UseAiHub,
                AccountPermission::UseIdeas,
            ],

            self::Seller => [
                ...self::User->permissions(),

                AccountPermission::PublishPost,
                AccountPermission::ManageTaxonomy,

                AccountPermission::UseMarketplace,

                AccountPermission::CreateProduct,
                AccountPermission::UpdateOwnProduct,
                AccountPermission::DeleteOwnProduct,
                AccountPermission::SubmitProductForReview,
                AccountPermission::ViewOwnSales,
                AccountPermission::ManageOwnSupportTickets,
            ],

            self::Admin => AccountPermission::cases(),
        };
    }

    /**
     * Determine whether the role has the given permission.
     */
    public function hasPermission(AccountPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Determine whether the role has every given permission.
     */
    public function hasAllPermissions(AccountPermission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether the role has at least one of the given permissions.
     */
    public function hasAnyPermission(AccountPermission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the hierarchy level for this role.
     *
     * Higher numbers indicate higher privileges. Used for "at least this
     * privileged" checks, e.g. whether one role may act on another's account.
     */
    public function level(): int
    {
        return match ($this) {
            self::User => 1,
            self::Seller => 2,
            self::Admin => 3,
        };
    }

    /**
     * Check if this role is at least as privileged as another role.
     */
    public function isAtLeast(AccountRole $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Determine whether this role reaches the admin panel.
     */
    public function canAccessAdminPanel(): bool
    {
        return $this->hasPermission(AccountPermission::AccessAdminPanel);
    }

    /**
     * Get the route name of this role's dashboard home.
     *
     * These are the canonical, role-owned dashboard routes. They are
     * deliberately **not** nested under one another: `/dashboard` is the reader
     * and author workspace, `/seller/dashboard` is the selling workspace, and
     * `/admin/dashboard` is platform administration. A role's home is the first
     * segment of its URL, so the address bar alone tells you which surface you
     * are on.
     */
    public function dashboardRoute(): string
    {
        return match ($this) {
            self::User => 'dashboard.index',
            self::Seller => 'seller.dashboard',
            self::Admin => 'admin.dashboard',
        };
    }

    /**
     * Get the roles that may be assigned by an actor holding `$actorRole`.
     *
     * An actor may never promote an account above their own level — that is how
     * privilege escalation is prevented without a second permission. Admin is
     * excluded from the result even for an admin actor, because granting the
     * admin role is a deliberate act performed outside the normal role picker.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function assignableBy(AccountRole $actorRole): array
    {
        return collect(self::cases())
            ->filter(fn (self $role) => $role !== self::Admin)
            ->filter(fn (self $role) => $actorRole->isAtLeast($role))
            ->map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()])
            ->values()
            ->toArray();
    }

    /**
     * Get every role as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()])
            ->values()
            ->toArray();
    }
}
