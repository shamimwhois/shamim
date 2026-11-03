<?php

use App\Enums\AccountRole;
use App\Enums\ProductLicense;
use App\Enums\ProductStatus;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| MarketplaceProduct value behaviour
|--------------------------------------------------------------------------
|
| Everything here is a pure method on an unsaved model: price formatting,
| download abbreviation, the publication rule and the visibility rule. No
| database, so a failure points at the method rather than at a fixture.
|
*/

test('a listing with no price is free', function () {
    expect(marketplaceProduct()->isFree())->toBeTrue()
        ->and(marketplaceProduct(['price_cents' => 1])->isFree())->toBeFalse();
});

test('a free listing is labelled free rather than zero', function () {
    expect(marketplaceProduct(['price_cents' => 0])->formattedPrice())->toBe(__('Free'));
});

test('prices are formatted from minor units with the listing currency', function () {
    expect(marketplaceProduct(['price_cents' => 4900])->formattedPrice())->toBe('$49.00')
        ->and(marketplaceProduct(['price_cents' => 4900, 'currency' => 'EUR'])->formattedPrice())->toBe('€49.00')
        ->and(marketplaceProduct(['price_cents' => 4900, 'currency' => 'GBP'])->formattedPrice())->toBe('£49.00')
        ->and(marketplaceProduct(['price_cents' => 150000, 'currency' => 'BDT'])->formattedPrice())->toBe('৳1,500.00')
        ->and(marketplaceProduct(['price_cents' => 4900, 'currency' => 'INR'])->formattedPrice())->toBe('₹49.00');
});

test('an unknown currency falls back to its code rather than guessing a symbol', function () {
    expect(marketplaceProduct(['price_cents' => 100, 'currency' => 'CHF'])->formattedPrice())->toBe('CHF 1.00');
});

test('the price in major units is the stored minor amount divided by a hundred', function () {
    expect(marketplaceProduct(['price_cents' => 12900])->priceInMajorUnits())->toBe(129.0);
});

test('download counts are abbreviated only once they reach a thousand', function () {
    expect(marketplaceProduct(['download_count' => 0])->downloadLabel())->toBe('0')
        ->and(marketplaceProduct(['download_count' => 999])->downloadLabel())->toBe('999')
        ->and(marketplaceProduct(['download_count' => 1000])->downloadLabel())->toBe('1k')
        ->and(marketplaceProduct(['download_count' => 12400])->downloadLabel())->toBe('12.4k');
});

test('publication requires both the published status and a timestamp', function () {
    expect(marketplaceProduct(['status' => ProductStatus::Published])->isPublished())->toBeTrue()
        ->and(marketplaceProduct(['status' => ProductStatus::Published, 'published_at' => null])->isPublished())->toBeFalse()
        ->and(marketplaceProduct(['status' => ProductStatus::Draft])->isPublished())->toBeFalse()
        ->and(marketplaceProduct(['status' => ProductStatus::PendingReview])->isPublished())->toBeFalse();
});

test('a published listing is visible to a guest', function () {
    expect(marketplaceProduct()->isVisibleTo(null))->toBeTrue();
});

test('a draft is visible only to its creator and to accounts that review products', function () {
    $draft = marketplaceProduct([
        'status' => ProductStatus::Draft,
        'published_at' => null,
        'user_id' => 7,
    ]);

    $owner = new User(['role' => AccountRole::Seller]);
    $owner->id = 7;

    $stranger = new User(['role' => AccountRole::Seller]);
    $stranger->id = 8;

    $admin = new User(['role' => AccountRole::Admin]);

    expect($draft->isVisibleTo(null))->toBeFalse()
        ->and($draft->isVisibleTo($owner))->toBeTrue()
        ->and($draft->isVisibleTo($stranger))->toBeFalse()
        ->and($draft->isVisibleTo($admin))->toBeTrue();
});

test('a listing casts its licence and status to their enums', function () {
    $product = marketplaceProduct([
        'license' => ProductLicense::Extended,
        'status' => ProductStatus::Archived,
    ]);

    expect($product->license)->toBe(ProductLicense::Extended)
        ->and($product->status)->toBe(ProductStatus::Archived)
        ->and($product->tags)->toBeNull();
});

test('the route key is the slug, not the primary key', function () {
    expect(marketplaceProduct(['slug' => 'queue-lens'])->getRouteKeyName())->toBe('slug')
        ->and(marketplaceProduct(['slug' => 'queue-lens'])->getRouteKey())->toBe('queue-lens');
});
