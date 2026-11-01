<?php

namespace App\Actions\Marketplace;

use App\Enums\AccountPermission;
use App\Models\MarketplaceProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Delete a marketplace product listing.
 *
 * Sellers may delete only their own draft products. Admin may delete any product.
 * Soft deletion is used so the record remains visible in history and can be
 * restored if needed.
 */
class DeleteProduct
{
    /**
     * Remove the product listing.
     */
    public function handle(User $user, MarketplaceProduct $product): bool
    {
        return DB::transaction(function () use ($user, $product): bool {
            if ($product->isOwnedBy($user)) {
                $product->delete(); // soft delete
            } elseif ($user->hasPermission(AccountPermission::AccessAdminPanel)) {
                $product->forceDelete();
            } else {
                throw new InvalidArgumentException(__('You may not delete this product.'));
            }

            return true;
        });
    }
}
