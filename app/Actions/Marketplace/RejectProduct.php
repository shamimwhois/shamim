<?php

namespace App\Actions\Marketplace;

use App\Enums\ProductStatus;
use App\Models\MarketplaceProduct;

/**
 * Return a product listing to its creator.
 *
 * The listing goes back to `Draft` rather than being deleted: a rejected
 * submission is work the seller still owns, and they need the record in order
 * to fix and resubmit it. `published_at` is deliberately left untouched so a
 * previously published listing that is withdrawn and re-approved keeps its
 * original release date.
 */
class RejectProduct
{
    /**
     * Withdraw the listing from review.
     */
    public function handle(MarketplaceProduct $product): MarketplaceProduct
    {
        $product->forceFill(['status' => ProductStatus::Draft])->save();

        return $product->refresh();
    }
}
