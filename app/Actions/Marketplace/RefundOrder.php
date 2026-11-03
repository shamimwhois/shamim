<?php

namespace App\Actions\Marketplace;

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a refund against a paid order.
 *
 * Refunds accumulate as rows, so the guards here are arithmetic over the
 * ledger rather than a single flag: the amount must land between one minor
 * unit and whatever remains refundable, which is what refuses both an
 * over-refund and a second refund that forgets the first. The order's own
 * status is the aggregate view of those rows — `Refunded` only once the last
 * cent has returned, so a partial refund leaves the buyer entitled, which is
 * the deal the two statuses exist to describe.
 */
class RefundOrder
{
    /**
     * Refund the given amount against the order.
     */
    public function handle(MarketplaceOrder $order, int $amountCents, User $actor, ?string $reason = null): MarketplaceRefund
    {
        if (! $order->status->isRefundable()) {
            throw ValidationException::withMessages([
                'amount' => __('This order cannot be refunded.'),
            ]);
        }

        $refundable = $order->refundableCents();

        if ($amountCents < 1 || $amountCents > $refundable) {
            throw ValidationException::withMessages([
                'amount' => __('The refund must be between :min and :max.', [
                    'min' => $order->formattedAmountFor(1),
                    'max' => $order->formattedAmountFor($refundable),
                ]),
            ]);
        }

        return DB::transaction(function () use ($order, $amountCents, $actor, $reason): MarketplaceRefund {
            $refund = MarketplaceRefund::create([
                'marketplace_order_id' => $order->getKey(),
                'amount_cents' => $amountCents,
                'currency' => $order->currency,
                'status' => RefundStatus::Succeeded,
                'reason' => $reason,
                'processed_by' => $actor->id,
                'processed_at' => now(),
            ]);

            $fullyRefunded = $order->refundedCents() >= $order->price_cents;

            $order->fill([
                'status' => $fullyRefunded ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded,
                'refunded_at' => $fullyRefunded ? now() : $order->refunded_at,
            ])->save();

            return $refund;
        });
    }
}
