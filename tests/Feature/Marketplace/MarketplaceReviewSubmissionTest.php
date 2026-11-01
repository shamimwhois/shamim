<?php

use App\Enums\ReviewStatus;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceReview;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Review submission
|--------------------------------------------------------------------------
|
| Reviews are user-generated content on a public page, so the validation and
| the moderation default are part of the security surface, not just the form.
| Every test here asserts what ends up in the database, not what the page
| says afterwards.
|
*/

test('a review requires a rating between one and five', function () {
    $product = publishedMarketplaceProduct();
    $reader = User::factory()->create();

    foreach ([0, 6] as $rating) {
        Livewire::actingAs($reader)
            ->test('pages::marketplace.show', ['product' => $product])
            ->set('reviewRating', $rating)
            ->set('reviewBody', 'A body long enough to pass the length rule.')
            ->call('submitReview')
            ->assertHasErrors(['reviewRating' => 'between']);
    }

    expect(MarketplaceReview::query()->count())->toBe(0);
});

test('a review body must be at least twenty characters', function () {
    $product = publishedMarketplaceProduct();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::marketplace.show', ['product' => $product])
        ->set('reviewBody', 'Too short.')
        ->call('submitReview')
        ->assertHasErrors(['reviewBody' => 'min']);

    expect(MarketplaceReview::query()->count())->toBe(0);
});

test('a review title may not exceed the column length', function () {
    $product = publishedMarketplaceProduct();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::marketplace.show', ['product' => $product])
        ->set('reviewBody', 'A body long enough to pass the length rule.')
        ->set('reviewTitle', str_repeat('a', 121))
        ->call('submitReview')
        ->assertHasErrors(['reviewTitle' => 'max']);

    expect(MarketplaceReview::query()->count())->toBe(0);
});

test('a second submission updates the existing review instead of adding another', function () {
    $product = publishedMarketplaceProduct();
    $reader = User::factory()->create();

    $component = Livewire::actingAs($reader)
        ->test('pages::marketplace.show', ['product' => $product])
        ->set('reviewRating', 4)
        ->set('reviewBody', 'The first version of this review, long enough to pass.')
        ->call('submitReview');

    $component
        ->set('reviewRating', 5)
        ->set('reviewBody', 'An edited version of the same review, also long enough.')
        ->call('submitReview');

    $review = MarketplaceReview::query()
        ->where('marketplace_product_id', $product->id)
        ->where('user_id', $reader->id)
        ->sole();

    expect($review->body)->toBe('An edited version of the same review, also long enough.')
        ->and($review->rating)->toBe(5)
        ->and(MarketplaceReview::query()->count())->toBe(1);
});

test('an edited review returns to the moderation queue', function () {
    $product = publishedMarketplaceProduct();
    $reader = User::factory()->create();

    MarketplaceReview::factory()->approved()->create([
        'marketplace_product_id' => $product->id,
        'user_id' => $reader->id,
        'body' => 'An approved review that is about to be edited by its author.',
    ]);

    Livewire::actingAs($reader)
        ->test('pages::marketplace.show', ['product' => $product])
        ->set('reviewBody', 'An edited review that has to be looked at again.')
        ->call('submitReview');

    expect(MarketplaceReview::query()->where('user_id', $reader->id)->sole()->status)
        ->toBe(ReviewStatus::Pending);
});

test('a review cannot be posted against a listing that is not published', function () {
    $creator = User::factory()->seller()->create();
    $product = MarketplaceProduct::factory()->create(['user_id' => $creator->id]);

    Livewire::actingAs($creator)
        ->test('pages::marketplace.show', ['product' => $product])
        ->set('reviewBody', 'A review of my own unpublished draft listing.')
        ->call('submitReview')
        ->assertNotFound();

    expect(MarketplaceReview::query()->count())->toBe(0);
});

test('the review form is only offered to accounts that have not reviewed yet', function () {
    $product = publishedMarketplaceProduct();
    $reader = User::factory()->create();

    $component = Livewire::actingAs($reader)
        ->test('pages::marketplace.show', ['product' => $product])
        ->call('showTab', 'reviews')
        ->assertSee(__('Write a review'));

    $component
        ->set('reviewBody', 'A first review that is long enough to be accepted.')
        ->call('submitReview')
        ->assertDontSee(__('Write a review'))
        ->assertSee(__('Your review'));
});
