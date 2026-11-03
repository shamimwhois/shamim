<?php

namespace Database\Factories;

use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketplaceProductVersion>
 */
class MarketplaceProductVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'marketplace_product_id' => MarketplaceProduct::factory(),
            'version' => fake()->numerify('#.#.#'),
            'release_notes' => fake()->paragraph(),
            'is_current' => false,
            'released_at' => now()->subDays(fake()->numberBetween(1, 120)),
        ];
    }

    /**
     * Indicate that this is the release the product currently advertises.
     */
    public function current(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_current' => true,
        ]);
    }

    /**
     * Indicate that the release carries a package a buyer may download.
     *
     * Only the row is written here. A factory that also put bytes on a disk would
     * make every test that merely lists releases depend on that disk being faked,
     * so a test that streams the file puts its own placeholder at `$release->path`.
     */
    public function withDeliverable(): static
    {
        return $this->state(fn (array $attributes) => [
            'disk' => 'marketplace_media',
            'path' => 'deliverables/fixture-'.Str::uuid()->toString().'.zip',
            'mime' => 'application/zip',
            'size' => 4096,
            'original_name' => 'package.zip',
        ]);
    }
}
