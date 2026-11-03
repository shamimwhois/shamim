<?php

namespace Database\Factories;

use App\Enums\ProductLicense;
use App\Enums\ProductStatus;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketplaceProduct>
 */
class MarketplaceProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default is a private draft: a listing is not public until a seller
     * submits it and an operator publishes it, and a factory that defaulted to
     * published would let a test assert catalogue visibility without ever
     * exercising that rule.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word().' '.fake()->word());

        return [
            'marketplace_category_id' => MarketplaceCategory::factory(),
            'user_id' => User::factory()->seller(),
            'name' => $name,
            'slug' => Str::slug($name),
            'tagline' => fake()->sentence(10),
            'description' => fake()->paragraphs(3, true),
            'price_cents' => 0,
            'currency' => 'USD',
            'license' => ProductLicense::Mit,
            'version' => '1.0.0',
            'framework' => 'Laravel',
            'language' => 'PHP',
            'install_command' => 'composer require vendor/package',
            'demo_url' => null,
            'docs_url' => null,
            'repository_url' => null,
            'tags' => [],
            'status' => ProductStatus::Draft,
            'is_featured' => false,
            'download_count' => 0,
            'published_at' => null,
            'last_released_at' => null,
        ];
    }

    /**
     * Indicate that the listing is live in the catalogue.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Published,
            'published_at' => now()->subDays(fake()->numberBetween(1, 90)),
            'last_released_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ]);
    }

    /**
     * Indicate that the listing is waiting on an operator.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::PendingReview,
        ]);
    }

    /**
     * Indicate that the listing has been withdrawn.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Archived,
        ]);
    }

    /**
     * Indicate that the listing is flagged for the front page.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * Indicate that the listing is free.
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_cents' => 0,
            'license' => ProductLicense::Mit,
        ]);
    }

    /**
     * Indicate that the listing has a one-time price, in minor units.
     */
    public function priced(int $cents = 4900, string $currency = 'USD'): static
    {
        return $this->state(fn (array $attributes) => [
            'price_cents' => $cents,
            'currency' => $currency,
            'license' => ProductLicense::Commercial,
        ]);
    }
}
