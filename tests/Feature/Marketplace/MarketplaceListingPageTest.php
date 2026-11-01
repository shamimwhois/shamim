<?php

use App\Enums\ReviewStatus;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductVersion;
use App\Models\MarketplaceReview;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Listing page
|--------------------------------------------------------------------------
|
| Visibility, documentation and reviews. The rule the page must never break is
| that a rating is a claim about approved reviews — a pending review cannot move
| a number, and a listing with no reviews has no rating at all.
|
*/

test('a published listing is readable by a guest', function () {
    $product = MarketplaceProduct::factory()->published()->create(['name' => 'Lens Kit']);

    $this->get(route('marketplace.show', ['product' => $product->slug]))
        ->assertOk()
        ->assertSee('Lens Kit')
        ->assertSee($product->tagline);
});

test('a draft listing is not found for a guest', function () {
    $product = MarketplaceProduct::factory()->create(['name' => 'Unreleased Kit']);

    $this->get(route('marketplace.show', ['product' => $product->slug]))
        ->assertNotFound();
});

test('a draft listing is readable by its creator and by a reviewer', function () {
    $creator = User::factory()->seller()->create();
    $product = MarketplaceProduct::factory()->create([
        'user_id' => $creator->id,
        'name' => 'Work In Progress',
    ]);

    $this->actingAs($creator)
        ->get(route('marketplace.show', ['product' => $product->slug]))
        ->assertOk()
        ->assertSee('Work In Progress');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('marketplace.show', ['product' => $product->slug]))
        ->assertOk();
});

test('an unknown tab returns not found', function () {
    $product = MarketplaceProduct::factory()->published()->create();

    $this->get(route('marketplace.show', ['product' => $product->slug, 'tab' => 'invoices']))
        ->assertNotFound();
});

test('the listing page states the price, the licence and the install command', function () {
    $product = MarketplaceProduct::factory()->published()->priced(4900)->create([
        'name' => 'Lens Kit',
        'install_command' => 'composer require devfolio/queue-lens',
    ]);

    $this->get(route('marketplace.show', ['product' => $product->slug, 'tab' => 'installation']))
        ->assertOk()
        ->assertSee('$49.00')
        ->assertSee('Commercial')
        ->assertSee('composer require devfolio/queue-lens');
});

test('the release history lists every version with its notes', function () {
    $product = MarketplaceProduct::factory()->published()->create(['name' => 'Lens Kit']);

    MarketplaceProductVersion::factory()->for($product, 'product')->current()->create([
        'version' => '2.1.0',
        'release_notes' => 'Adds failure clustering.',
        'released_at' => now()->subDays(3),
    ]);

    MarketplaceProductVersion::factory()->for($product, 'product')->create([
        'version' => '1.0.0',
        'release_notes' => 'First stable release.',
        'released_at' => now()->subDays(200),
    ]);

    Livewire::test('pages::marketplace.show', ['product' => $product])
        ->call('showTab', 'versions')
        ->assertSee('v2.1.0')
        ->assertSee('Adds failure clustering.')
        ->assertSee('v1.0.0');
});

test('only approved reviews are shown on the listing', function () {
    $product = MarketplaceProduct::factory()->published()->create();

    MarketplaceReview::factory()->approved()->create([
        'marketplace_product_id' => $product->id,
        'body' => 'Approved review text that a buyer should read.',
    ]);

    MarketplaceReview::factory()->create([
        'marketplace_product_id' => $product->id,
        'body' => 'Pending review text that must stay hidden.',
    ]);

    $this->get(route('marketplace.show', ['product' => $product->slug, 'tab' => 'reviews']))
        ->assertOk()
        ->assertSee('Approved review text that a buyer should read.')
        ->assertDontSee('Pending review text that must stay hidden.');
});

test('the rating is derived from approved reviews only', function () {
    $product = MarketplaceProduct::factory()->published()->create();

    MarketplaceReview::factory()->approved()->rating(5)->create(['marketplace_product_id' => $product->id]);
    MarketplaceReview::factory()->approved()->rating(4)->create(['marketplace_product_id' => $product->id]);
    MarketplaceReview::factory()->rating(1)->create(['marketplace_product_id' => $product->id]);

    $product->refresh();

    expect($product->averageRating())->toBe(4.5)
        ->and($product->reviewCount())->toBe(2);
});

test('a listing with no approved reviews has no rating', function () {
    $product = MarketplaceProduct::factory()->published()->create();

    expect($product->averageRating())->toBeNull()
        ->and($product->reviewCount())->toBe(0);

    $this->get(route('marketplace.show', ['product' => $product->slug, 'tab' => 'reviews']))
        ->assertOk()
        ->assertSee('No reviews yet');
});

test('a signed-in reader can submit a review that awaits moderation', function () {
    $product = MarketplaceProduct::factory()->published()->create();
    $reader = User::factory()->create();

    Livewire::actingAs($reader)
        ->test('pages::marketplace.show', ['product' => $product])
        ->set('reviewRating', 4)
        ->set('reviewTitle', 'Replaced a hand-rolled table')
        ->set('reviewBody', 'It replaced three years of ad-hoc audit tables in an afternoon.')
        ->call('submitReview')
        ->assertHasNoErrors();

    $review = MarketplaceReview::query()->where('user_id', $reader->id)->first();

    expect($review)->not->toBeNull()
        ->and($review->status)->toBe(ReviewStatus::Pending)
        ->and($review->rating)->toBe(4)
        ->and($review->title)->toBe('Replaced a hand-rolled table');
});

test('a submitted review is not published until it is approved', function () {
    $product = MarketplaceProduct::factory()->published()->create();
    $reader = User::factory()->create();

    Livewire::actingAs($reader)
        ->test('pages::marketplace.show', ['product' => $product])
        ->set('reviewRating', 5)
        ->set('reviewBody', 'A review that is waiting for a moderator to read it.')
        ->call('submitReview');

    $this->get(route('marketplace.show', ['product' => $product->slug, 'tab' => 'reviews']))
        ->assertOk()
        ->assertDontSee('A review that is waiting for a moderator to read it.');
});

test('a guest cannot submit a review', function () {
    $product = MarketplaceProduct::factory()->published()->create();

    Livewire::test('pages::marketplace.show', ['product' => $product])
        ->set('reviewRating', 5)
        ->set('reviewBody', 'A guest should not be able to write this review.')
        ->call('submitReview')
        ->assertForbidden();

    expect(MarketplaceReview::query()->count())->toBe(0);
});

test('a creator cannot review their own listing', function () {
    $creator = User::factory()->seller()->create();
    $product = MarketplaceProduct::factory()->published()->create(['user_id' => $creator->id]);

    Livewire::actingAs($creator)
        ->test('pages::marketplace.show', ['product' => $product])
        ->set('reviewRating', 5)
        ->set('reviewBody', 'Reviewing my own product would not mean very much.')
        ->call('submitReview')
        ->assertForbidden();

    expect(MarketplaceReview::query()->count())->toBe(0);
});

test('an account cannot leave two reviews for the same listing', function () {
    $product = MarketplaceProduct::factory()->published()->create();
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

    expect(MarketplaceReview::query()->where('marketplace_product_id', $product->id)->count())->toBe(1);
});

test('the listing page shows related published listings from the same category', function () {
    $product = MarketplaceProduct::factory()->published()->create(['name' => 'Main Kit']);
    MarketplaceProduct::factory()->published()->create([
        'marketplace_category_id' => $product->marketplace_category_id,
        'name' => 'Sibling Kit',
    ]);
    MarketplaceProduct::factory()->create([
        'marketplace_category_id' => $product->marketplace_category_id,
        'name' => 'Unpublished Sibling',
    ]);

    $this->get(route('marketplace.show', ['product' => $product->slug]))
        ->assertOk()
        ->assertSee('Sibling Kit')
        ->assertDontSee('Unpublished Sibling');
});
