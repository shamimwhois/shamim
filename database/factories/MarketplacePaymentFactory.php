<?php

namespace Database\Factories;

use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplacePayment>
 */
class MarketplacePaymentFactory extends Factory
{
    protected $model = MarketplacePayment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $order = MarketplaceOrder::factory()->create();

        return [
            'marketplace_order_id' => $order->getKey(),
            'provider' => $order->payment_provider ?? PaymentProvider::Card,
            'mode' => $order->payment_mode ?? PaymentMode::Test,
            'amount_cents' => $order->price_cents,
            'currency' => $order->currency,
            'status' => PaymentStatus::Pending,
            'reference' => null,
            'failure_reason' => null,
            'processed_at' => null,
        ];
    }

    /**
     * Indicate that the attempt captured the money.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Succeeded,
            'reference' => 'SANDBOX-'.fake()->unique()->numerify('############'),
            'processed_at' => now(),
        ]);
    }

    /**
     * Indicate that the attempt was declined.
     */
    public function declined(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Failed,
            'failure_reason' => 'The sandbox gateway declined the attempt.',
            'processed_at' => now(),
        ]);
    }
}
