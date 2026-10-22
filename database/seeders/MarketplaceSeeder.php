<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds the marketplace as one unit.
 *
 * Exists so the module can be seeded on its own — `php artisan db:seed
 * --class=MarketplaceSeeder` — without dragging in the blog, the comment corpus
 * or the feed. It still requires accounts to exist, because every listing has a
 * creator; `MarketplaceProductSeeder` resolves them by email and falls back to
 * the first account rather than creating one.
 *
 * Order matters: products resolve their category by slug, so the taxonomy has
 * to be in place first.
 */
class MarketplaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            MarketplaceCategorySeeder::class,
            MarketplaceProductSeeder::class,
        ]);
    }
}
