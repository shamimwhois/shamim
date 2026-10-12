<?php

namespace App\Enums;

/**
 * Account-level permissions.
 *
 * These are distinct from {@see TeamPermission}, which governs what a member
 * may do *inside one team*. Account permissions are global: they describe what
 * an account may do anywhere in the application, independent of any team.
 *
 * Cases are namespaced `resource:action` so a permission name is unambiguous
 * when it appears in logs, policy checks, or a database seeder.
 */
enum AccountPermission: string
{
    // --- Content authoring -------------------------------------------------
    case CreatePost = 'post:create';
    case UpdateOwnPost = 'post:update-own';
    case UpdateAnyPost = 'post:update-any';
    case DeleteOwnPost = 'post:delete-own';
    case DeleteAnyPost = 'post:delete-any';
    case PublishPost = 'post:publish';

    case ModerateComments = 'comment:moderate';

    case ManageTaxonomy = 'taxonomy:manage';

    // --- Selling -----------------------------------------------------------
    case CreateProduct = 'product:create';
    case UpdateOwnProduct = 'product:update-own';
    case DeleteOwnProduct = 'product:delete-own';
    case SubmitProductForReview = 'product:submit';
    case ViewOwnSales = 'sale:view-own';
    case ManageOwnSupportTickets = 'support:manage-own';

    // --- Administration ----------------------------------------------------
    case AccessAdminPanel = 'admin:access';
    case ManageUsers = 'user:manage';
    case ManageRoles = 'role:manage';
    case ReviewProducts = 'product:review';
    case ManageAnyProduct = 'product:manage-any';
    case ManageSellers = 'seller:manage';
    case ViewAnySales = 'sale:view-any';
    case ProcessRefunds = 'refund:process';
    case ConfigurePayments = 'payment:configure';
    case ViewAuditLog = 'audit:view';

    /**
     * Get the display label for the permission.
     */
    public function label(): string
    {
        return match ($this) {
            self::CreatePost => __('Create posts'),
            self::UpdateOwnPost => __('Edit own posts'),
            self::UpdateAnyPost => __('Edit any post'),
            self::DeleteOwnPost => __('Delete own posts'),
            self::DeleteAnyPost => __('Delete any post'),
            self::PublishPost => __('Publish posts'),
            self::ModerateComments => __('Moderate comments'),
            self::ManageTaxonomy => __('Manage categories and tags'),
            self::CreateProduct => __('Create products'),
            self::UpdateOwnProduct => __('Edit own products'),
            self::DeleteOwnProduct => __('Delete own products'),
            self::SubmitProductForReview => __('Submit products for review'),
            self::ViewOwnSales => __('View own sales'),
            self::ManageOwnSupportTickets => __('Manage own support tickets'),
            self::AccessAdminPanel => __('Access the admin panel'),
            self::ManageUsers => __('Manage users'),
            self::ManageRoles => __('Manage roles'),
            self::ReviewProducts => __('Review submitted products'),
            self::ManageAnyProduct => __('Manage any product'),
            self::ManageSellers => __('Manage sellers'),
            self::ViewAnySales => __('View all sales'),
            self::ProcessRefunds => __('Process refunds'),
            self::ConfigurePayments => __('Configure payment providers'),
            self::ViewAuditLog => __('View the audit log'),
        };
    }

    /**
     * Get the resource group this permission belongs to.
     */
    public function group(): PermissionGroup
    {
        return match ($this) {
            self::CreatePost,
            self::UpdateOwnPost,
            self::UpdateAnyPost,
            self::DeleteOwnPost,
            self::DeleteAnyPost,
            self::PublishPost => PermissionGroup::Content,

            self::ModerateComments,
            self::ManageTaxonomy => PermissionGroup::Moderation,

            self::CreateProduct,
            self::UpdateOwnProduct,
            self::DeleteOwnProduct,
            self::SubmitProductForReview,
            self::ViewOwnSales,
            self::ManageOwnSupportTickets => PermissionGroup::Selling,

            self::AccessAdminPanel,
            self::ManageUsers,
            self::ManageRoles,
            self::ReviewProducts,
            self::ManageAnyProduct,
            self::ManageSellers,
            self::ViewAnySales,
            self::ProcessRefunds,
            self::ConfigurePayments,
            self::ViewAuditLog => PermissionGroup::Administration,
        };
    }

    /**
     * Get the group's display label.
     */
    public function groupLabel(): string
    {
        return $this->group()->label();
    }

    /**
     * Get every permission as a value/label pair.
     *
     * @return array<int, array{value: string, label: string, group: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $permission) => [
                'value' => $permission->value,
                'label' => $permission->label(),
                'group' => $permission->group()->value,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Get every permission grouped by its resource group.
     *
     * Keyed by the group's string value so the result can be iterated in a
     * Blade `@foreach` without unwrapping an enum for the loop key.
     *
     * @return array<string, array<int, self>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::cases() as $permission) {
            $grouped[$permission->group()->value][] = $permission;
        }

        return $grouped;
    }
}
