<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketplaceOrder>
 */
class MarketplaceOrderFactory extends Factory
{
    protected $model = MarketplaceOrder::class;

    /**
     * Define the model's default state.
     *
     * The default is a pending order against a priced, published listing:
     * the interesting lifecycle tests start from money not yet captured, and
     * the factory copies the listing's snapshot fields exactly as a real
     * checkout would.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = MarketplaceProduct::factory()->published()->priced()->create();

        return [
            'order_number' => 'ORD-'.fake()->unique()->numberBetween(10000000, 99999999).'-'.Str::upper(Str::random(4)),
            'user_id' => User::factory(),
            'marketplace_product_id' => $product->getKey(),
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'price_cents' => $product->price_cents,
            'currency' => $product->currency,
            'commission_cents' => 0,
            'status' => OrderStatus::Pending,
            'payment_provider' => PaymentProvider::Card,
            'payment_mode' => PaymentMode::Test,
            'paid_at' => null,
            'failure_reason' => null,
        ];
    }

    /**
     * Indicate that the order belongs to the given buyer.
     */
    public function forBuyer(User $buyer): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $buyer->getKey(),
        ]);
    }

    /**
     * Indicate that the order bought the given listing, copying its snapshot.
     */
    public function forProduct(MarketplaceProduct $product): static
    {
        return $this->state(fn (array $attributes) => [
            'marketplace_product_id' => $product->getKey(),
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'price_cents' => $product->price_cents,
            'currency' => $product->currency,
        ]);
    }

    /**
     * Indicate that the payment for the order was captured.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ]);
    }

    /**
     * Indicate that the payment attempt was declined.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Failed,
            'failure_reason' => 'The sandbox gateway declined the attempt.',
        ]);
    }

    /**
     * Indicate that the order was withdrawn before capture.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ]);
    }

    /**
     * Indicate that the whole price was refunded.
     */
    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Refunded,
            'paid_at' => now()->subDays(30),
            'refunded_at' => now()->subDays(fake()->numberBetween(0, 15)),
        ]);
    }

    /**
     * Indicate that part of the price was refunded.
     */
    public function partiallyRefunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::PartiallyRefunded,
            'paid_at' => now()->subDays(30),
        ]);
    }

    /**
     * Indicate that the order was bought for free.
     */
    public function free(): static
    {
        $product = MarketplaceProduct::factory()->published()->free()->create();

        return $this->forProduct($product)->state(fn (array $attributes) => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'payment_provider' => null,
            'payment_mode' => null,
        ]);
    }
}
