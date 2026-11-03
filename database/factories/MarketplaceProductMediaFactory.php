<?php

namespace Database\Factories;

use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketplaceProductMedia>
 */
class MarketplaceProductMediaFactory extends Factory
{
    protected $model = MarketplaceProductMedia::class;

    /**
     * Define the model's default state.
     *
     * A media row is always bound to a listing — there is no staged state to
     * describe — so the factory's job is to look like the output of
     * `AttachProductScreenshot`: a private-disk path with a UUID name, and the
     * mime, size and dimensions the disk reported for a real image.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $width = fake()->numberBetween(320, 1600);
        $height = (int) round($width * fake()->randomFloat(2, 0.5, 1));

        return [
            'marketplace_product_id' => MarketplaceProduct::factory(),
            'disk' => 'marketplace_media',
            'path' => 'products/'.Str::random(8).'/'.Str::random(16).'.jpg',
            'mime' => 'image/jpeg',
            'size' => $width * $height,
            'width' => $width,
            'height' => max(1, $height),
            'alt_text' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * A PNG screenshot, for the code paths that branch on the mime.
     */
    public function png(): static
    {
        return $this->state(fn (array $attributes) => [
            'mime' => 'image/png',
            'path' => preg_replace('/\.jpg$/', '.png', (string) $attributes['path']),
        ]);
    }

    /**
     * A row with no recorded dimensions, which is how an image the decoder could
     * not read lands in the table.
     */
    public function withoutDimensions(): static
    {
        return $this->state(fn (array $attributes) => [
            'width' => null,
            'height' => null,
        ]);
    }
}
