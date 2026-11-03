<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceReview>
 */
class MarketplaceReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Pending, matching the write path: a review is not public until it has
     * been approved, so a test that wants a visible one asks for it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'marketplace_product_id' => MarketplaceProduct::factory(),
            'user_id' => User::factory(),
            'rating' => fake()->numberBetween(3, 5),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(3),
            'status' => ReviewStatus::Pending,
        ];
    }

    /**
     * Indicate that the review has been approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReviewStatus::Approved,
        ]);
    }

    /**
     * Indicate that the review has been rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReviewStatus::Rejected,
        ]);
    }

    /**
     * Indicate that the review carries a specific star rating.
     */
    public function rating(int $rating): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $rating,
        ]);
    }
}
