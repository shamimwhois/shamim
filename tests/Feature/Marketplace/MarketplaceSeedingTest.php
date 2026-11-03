<?php

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceReview;
use Database\Seeders\MarketplaceCategorySeeder;
use Database\Seeders\MarketplaceProductSeeder;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\UserSeeder;

/*
|--------------------------------------------------------------------------
| Seed data
|--------------------------------------------------------------------------
|
| The seeders are part of the feature: `composer setup` runs migrations without
| `--seed`, so a developer who seeds has to get a catalogue that actually
| renders — categories, published listings, releases and reviews.
|
*/

test('the seeded catalogue is browsable', function () {
    $this->seed();

    expect(MarketplaceCategory::query()->count())->toBeGreaterThan(0)
        ->and(MarketplaceProduct::query()->published()->count())->toBeGreaterThan(0)
        ->and(MarketplaceReview::query()->approved()->count())->toBeGreaterThan(0);

    $this->get(route('marketplace.index'))->assertOk();
    $this->get(route('marketplace.show', ['product' => 'activity-ledger']))->assertOk();
});

test('the seeders cover every product status the console filters on', function () {
    $this->seed();

    foreach (['draft', 'pending', 'published', 'archived'] as $status) {
        expect(MarketplaceProduct::query()->where('status', $status)->exists())
            ->toBeTrue("The seeded corpus has no [{$status}] listing.");
    }
});

test('re-running the marketplace seeders does not duplicate rows', function () {
    $this->seed(UserSeeder::class);
    $this->seed(MarketplaceCategorySeeder::class);
    $this->seed(MarketplaceProductSeeder::class);

    $counts = [
        'categories' => MarketplaceCategory::query()->count(),
        'products' => MarketplaceProduct::query()->count(),
        'reviews' => MarketplaceReview::query()->count(),
    ];

    $this->seed(MarketplaceCategorySeeder::class);
    $this->seed(MarketplaceProductSeeder::class);

    expect(MarketplaceCategory::query()->count())->toBe($counts['categories'])
        ->and(MarketplaceProduct::query()->count())->toBe($counts['products'])
        ->and(MarketplaceReview::query()->count())->toBe($counts['reviews']);
});

test('the marketplace seeder runs on its own, without the blog or the feed', function () {
    $this->seed(UserSeeder::class);
    $this->seed(MarketplaceSeeder::class);

    expect(MarketplaceCategory::query()->count())->toBeGreaterThan(0)
        ->and(MarketplaceProduct::query()->published()->count())->toBeGreaterThan(0);

    $this->get(route('marketplace.index'))->assertOk();
});

test('seeded ratings agree with the approved reviews behind them', function () {
    $this->seed();

    MarketplaceProduct::query()->each(function (MarketplaceProduct $product): void {
        $approved = $product->approvedReviews()->get();

        if ($approved->isEmpty()) {
            expect($product->averageRating())->toBeNull("[{$product->slug}] has a rating without reviews.");

            return;
        }

        expect($product->reviewCount())->toBe($approved->count())
            ->and($product->averageRating())->toBe(round((float) $approved->avg('rating'), 1));
    });
});
