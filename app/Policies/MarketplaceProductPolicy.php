<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Enums\ProductStatus;
use App\Models\MarketplaceProduct;
use App\Models\User;

/**
 * Access rules for marketplace products.
 *
 * Ownership governs authoring; `product:review` governs publishing; an admin sees
 * everything. This matches the same pattern used for ideas and lab snippets, so a
 * single rule pattern is reused across the application.
 */
class MarketplaceProductPolicy
{
    /**
     * Determine whether the user can list products in their own workspace.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(AccountPermission::UseMarketplace);
    }

    /**
     * Determine whether the user can read the product.
     */
    public function view(User $user, MarketplaceProduct $product): bool
    {
        return $product->isVisibleTo($user);
    }

    /**
     * Determine whether the user can capture products.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user)
            && $user->hasPermission(AccountPermission::CreateProduct);
    }

    /**
     * Determine whether the user can rewrite the product.
     *
     * Authors only. Moderation moves a listing between status, not edits.
     */
    public function update(User $user, MarketplaceProduct $product): bool
    {
        return $product->isOwnedBy($user)
            && $product->status === ProductStatus::Draft;
    }

    /**
     * Determine whether the user can delete the product.
     */
    public function delete(User $user, MarketplaceProduct $product): bool
    {
        return $product->isOwnedBy($user)
            || $user->hasPermission(AccountPermission::AccessAdminPanel);
    }

    /**
     * Determine whether the user can submit the product for review.
     */
    public function submit(User $user, MarketplaceProduct $product): bool
    {
        return $product->isOwnedBy($user)
            && $product->status === ProductStatus::Draft;
    }

    /**
     * Determine whether the user can publish the product.
     */
    public function publish(User $user, MarketplaceProduct $product): bool
    {
        return $user->hasPermission(AccountPermission::ReviewProducts)
            && $product->status === ProductStatus::PendingReview;
    }
}
