<?php

namespace App\Actions\Marketplace;

use App\Enums\ReviewStatus;
use App\Models\MarketplaceReview;

/**
 * Refuse a product review.
 *
 * The row is kept, not deleted, so a moderator can see what was rejected and
 * the author cannot immediately resubmit the same text unnoticed. A rejected
 * review never contributes to a rating.
 */
class RejectReview
{
    /**
     * Withhold the review from the product page.
     */
    public function handle(MarketplaceReview $review): MarketplaceReview
    {
        $review->forceFill(['status' => ReviewStatus::Rejected])->save();

        return $review->refresh();
    }
}
