<?php

namespace App\Actions\Marketplace;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\MarketplaceOrder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves an order's pending payment attempt — the capture, or its refusal.
 *
 * The `isPayable()` gate is what makes this idempotent: an order already
 * standing at Paid, Failed, or any later state is returned untouched, so a
 * double click on the confirmation — or a gateway answering twice — can never
 * capture twice or re-increment the download counter. In production this
 * method would be driven by a verified server-side callback, never by a
 * browser returning to a success URL; in the sandbox the demo buttons post
 * here, which keeps the shape the real integration will need.
 */
class ConfirmPayment
{
    /**
     * Record the gateway's outcome for the order.
     */
    public function handle(MarketplaceOrder $order, bool $success, ?string $reference = null): MarketplaceOrder
    {
        if (! $order->status->isPayable()) {
            return $order;
        }

        return DB::transaction(function () use ($order, $success, $reference): MarketplaceOrder {
            $payment = $order->currentPayment;

            $payment?->update([
                'status' => $success ? PaymentStatus::Succeeded : PaymentStatus::Failed,
                'reference' => $reference ?? $payment->reference,
                'failure_reason' => $success ? null : ($payment->failure_reason ?? __('The sandbox gateway declined the attempt.')),
                'processed_at' => now(),
            ]);

            if ($success) {
                $order->fill([
                    'status' => OrderStatus::Paid,
                    'paid_at' => now(),
                    'commission_cents' => $this->commissionCents($order),
                    'failure_reason' => null,
                ]);

                $order->save();

                // The counter is incremented from the order, exactly once,
                // because this same method is the only door into `Paid`.
                $order->product?->increment('download_count');
            } else {
                $order->fill([
                    'status' => OrderStatus::Failed,
                    'failure_reason' => $payment->failure_reason ?? __('The payment attempt was declined.'),
                ]);

                $order->save();
            }

            return $order->refresh();
        });
    }

    /**
     * Snapshot the platform's cut using the creator's plan at sale time.
     */
    protected function commissionCents(MarketplaceOrder $order): int
    {
        $percent = $order->product?->creator?->plan()->commissionPercent();

        if ($percent === null) {
            return 0;
        }

        return (int) round($order->price_cents * $percent / 100);
    }
}
