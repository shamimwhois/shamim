<?php

namespace App\Actions\Marketplace;

use App\Enums\ReviewStatus;
use App\Models\MarketplaceReview;

/**
 * Make a product review public.
 *
 * Approval is also the moment the review starts counting toward the product's
 * average rating, because that average is derived from approved reviews only.
 */
class ApproveReview
{
    /**
     * Publish the review.
     */
    public function handle(MarketplaceReview $review): MarketplaceReview
    {
        $review->forceFill(['status' => ReviewStatus::Approved])->save();

        return $review->refresh();
    }
}
