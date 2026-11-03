<?php

namespace App\Actions\Marketplace;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\BillingProfile;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceProduct;
use App\Models\PaymentSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens an order for a listing — the checkout's only write.
 *
 * The guards here are the checkout's contract, repeated no matter which
 * surface calls it: only published listings can be bought, nobody can buy
 * their own listing, and a provider the payment console has disabled is not
 * offered in the first place. Re-purchasing a listing the buyer already
 * holds returns the existing order instead of charging twice — entitlement is
 * derived from orders, so a second entitling order would be a second sale of
 * something already owned.
 *
 * A free listing skips the payment ledger entirely: the order is written
 * `Paid` with no provider and no payment row, because nothing is being
 * captured and a fake attempt would misreport the money.
 *
 * Both paths copy the buyer's billing identity onto the order at this moment.
 * That is the only time the live profile is read; from here on the invoice is
 * issued against the snapshot, so a later rename or address change cannot
 * restate a sale that already happened.
 */
class PlaceOrder
{
    /**
     * Place (or reuse) the buyer's order for the given listing.
     */
    public function handle(MarketplaceProduct $product, User $buyer, ?PaymentProvider $provider = null): MarketplaceOrder
    {
        return DB::transaction(function () use ($product, $buyer, $provider): MarketplaceOrder {
            abort_unless($product->isPublished(), 404);
            abort_if($product->isOwnedBy($buyer), 403);

            $existing = MarketplaceOrder::query()
                ->where('user_id', $buyer->id)
                ->where('marketplace_product_id', $product->getKey())
                ->entitling()
                ->latest()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $billing = BillingProfile::snapshotFor($buyer);

            if ($product->isFree()) {
                $order = MarketplaceOrder::create([
                    'order_number' => MarketplaceOrder::generateOrderNumber(),
                    'user_id' => $buyer->id,
                    'marketplace_product_id' => $product->getKey(),
                    'product_name' => $product->name,
                    'product_slug' => $product->slug,
                    'price_cents' => 0,
                    'currency' => $product->currency,
                    ...$billing,
                    'commission_cents' => 0,
                    'status' => OrderStatus::Paid,
                    'payment_provider' => null,
                    'payment_mode' => null,
                    'paid_at' => now(),
                ]);

                $product->increment('download_count');

                return $order;
            }

            if ($provider === null) {
                throw ValidationException::withMessages([
                    'provider' => __('Choose a payment method to continue.'),
                ]);
            }

            $setting = PaymentSetting::enabled()->where('provider', $provider)->first();

            if ($setting === null) {
                throw ValidationException::withMessages([
                    'provider' => __(':provider is not accepting payments right now.', ['provider' => $provider->label()]),
                ]);
            }

            $order = MarketplaceOrder::create([
                'order_number' => MarketplaceOrder::generateOrderNumber(),
                'user_id' => $buyer->id,
                'marketplace_product_id' => $product->getKey(),
                'product_name' => $product->name,
                'product_slug' => $product->slug,
                'price_cents' => $product->price_cents,
                'currency' => $product->currency,
                ...$billing,
                'status' => OrderStatus::Pending,
                'payment_provider' => $provider,
                'payment_mode' => $setting->mode,
            ]);

            MarketplacePayment::create([
                'marketplace_order_id' => $order->getKey(),
                'provider' => $provider,
                'mode' => $setting->mode,
                'amount_cents' => $order->price_cents,
                'currency' => $order->currency,
                'status' => PaymentStatus::Pending,
            ]);

            return $order;
        });
    }
}
