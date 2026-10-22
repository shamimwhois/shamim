<?php

namespace App\Actions\Marketplace;

use App\Enums\ProductStatus;
use App\Models\MarketplaceProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Submit a draft product for review.
 *
 * The product moves from `Draft` to `PendingReview`, where an operator can
 * approve or reject it. Two things have to be true before it is allowed to
 * take that step, and both are decided here rather than in the screen that
 * asks for it:
 *
 *   - the listing carries enough for a reviewer to judge — a half-written
 *     draft in the queue costs somebody time and teaches the seller nothing;
 *   - the author's plan still has a slot for it. A submitted or live listing
 *     occupies one (`MarketplaceProduct::scopeOccupyingQuota()`); a draft does
 *     not, so the quota is charged at the moment of submission, not at birth.
 */
class SubmitProduct
{
    /**
     * Submit the product for review.
     */
    public function handle(User $user, MarketplaceProduct $product): MarketplaceProduct
    {
        return DB::transaction(function () use ($user, $product): MarketplaceProduct {
            if (! $product->isOwnedBy($user)) {
                throw new InvalidArgumentException(__('You may not submit this product for review.'));
            }

            if ($product->status !== ProductStatus::Draft) {
                throw new InvalidArgumentException(__('Only draft products can be submitted for review.'));
            }

            if (($missing = $product->missingSubmissionFields()) !== []) {
                throw new InvalidArgumentException(
                    __('Add :fields before submitting for review.', ['fields' => implode(', ', $missing)]),
                );
            }

            $this->assertPlanHasRoom($user);

            $product->forceFill(['status' => ProductStatus::PendingReview])->save();

            return $product->refresh();
        });
    }

    /**
     * Refuse the submission when the author's shelf is already full.
     */
    protected function assertPlanHasRoom(User $user): void
    {
        $limit = $user->plan()->productListingLimit();

        if ($limit === null) {
            return;
        }

        $inUse = MarketplaceProduct::query()
            ->where('user_id', $user->getKey())
            ->occupyingQuota()
            ->count();

        if ($inUse >= $limit) {
            throw new InvalidArgumentException(
                __('Your :plan plan allows :limit listings and all :limit are in use. Archive one to submit this.', [
                    'plan' => $user->plan()->label(),
                    'limit' => $limit,
                ]),
            );
        }
    }
}
