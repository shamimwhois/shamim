<?php

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRefund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceRefund>
 */
class MarketplaceRefundFactory extends Factory
{
    protected $model = MarketplaceRefund::class;

    /**
     * Define the model's default state.
     *
     * The default refunds the whole price of its order — the shape an
     * operator's "refund" action produces — so a test that only needs "a
     * refunded order" does not have to do arithmetic.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $order = MarketplaceOrder::factory()->paid()->create();

        return [
            'marketplace_order_id' => $order->getKey(),
            'amount_cents' => $order->price_cents,
            'currency' => $order->currency,
            'status' => RefundStatus::Succeeded,
            'reason' => fake()->sentence(8),
            'processed_by' => User::factory()->admin(),
            'processed_at' => now(),
        ];
    }

    /**
     * Refund part of the order's price.
     */
    public function partial(int $cents): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_cents' => $cents,
        ]);
    }
}
