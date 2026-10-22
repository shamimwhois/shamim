<?php

namespace App\Actions\Marketplace;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\MarketplaceOrder;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws an order that has not captured money.
 *
 * Only the payable states can be cancelled — an order that was paid is
 * refunded instead, never cancelled, so the ledger keeps every movement of
 * real money. An open payment attempt is closed out as failed so the ledger
 * and the order tell the same story.
 */
class CancelOrder
{
    /**
     * Cancel the order unless it has already resolved.
     */
    public function handle(MarketplaceOrder $order): MarketplaceOrder
    {
        if (! $order->status->isPayable()) {
            return $order;
        }

        return DB::transaction(function () use ($order): MarketplaceOrder {
            $order->fill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            $order->currentPayment?->update([
                'status' => PaymentStatus::Failed,
                'failure_reason' => __('The order was cancelled before the payment completed.'),
                'processed_at' => now(),
            ]);

            return $order->refresh();
        });
    }
}
