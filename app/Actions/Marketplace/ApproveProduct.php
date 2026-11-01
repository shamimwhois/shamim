<?php

namespace App\Actions\Marketplace;

use App\Enums\ProductStatus;
use App\Models\MarketplaceProduct;

/**
 * Publish a product listing.
 *
 * Approval is the only path to `Published`, and it stamps `published_at` once:
 * re-approving an already published listing must not move it back to the top of
 * a "newest" sort, because the release date is a fact about the listing, not
 * about the moderation action.
 */
class ApproveProduct
{
    /**
     * Make the listing readable by the public.
     */
    public function handle(MarketplaceProduct $product): MarketplaceProduct
    {
        $product->forceFill([
            'status' => ProductStatus::Published,
            'published_at' => $product->published_at ?? now(),
        ])->save();

        return $product->refresh();
    }
}
