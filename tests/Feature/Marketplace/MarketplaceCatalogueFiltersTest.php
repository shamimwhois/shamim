<?php

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceReview;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Catalogue filters, sorting and pagination
|--------------------------------------------------------------------------
|
| The catalogue is a query, and these tests pin the query rather than the
| markup: which order a sort produces, how many listings a page holds, and
| that a wildcard typed into the search box cannot turn into "match
| everything".
|
*/

test('a catalogue page holds nine listings and paginates the rest', function () {
    foreach (range('A', 'K') as $index => $letter) {
        publishedMarketplaceProduct([
            'name' => "Listing {$letter}",
            'published_at' => now()->subMinutes($index + 1),
        ]);
    }

    $component = Livewire::test('pages::marketplace.index');

    expect($component->instance()->products->count())->toBe(9)
        ->and($component->instance()->products->total())->toBe(11)
        ->and($component->instance()->products->lastPage())->toBe(2);

    $this->get(route('marketplace.index', ['page' => 2]))
        ->assertOk()
        ->assertSee('Listing J')
        ->assertSee('Listing K')
        ->assertDontSee('Listing A');
});

test('the default order puts featured listings first', function () {
    $ordinary = publishedMarketplaceProduct([
        'name' => 'Ordinary Kit',
        'is_featured' => false,
        'published_at' => now(),
    ]);

    $featured = publishedMarketplaceProduct([
        'name' => 'Featured Kit',
        'is_featured' => true,
        'published_at' => now()->subDay(),
    ]);

    $component = Livewire::test('pages::marketplace.index');

    expect($component->instance()->products->pluck('id')->all())
        ->toBe([$featured->id, $ordinary->id]);
});

test('sorting by downloads ranks the most downloaded first', function () {
    $quiet = publishedMarketplaceProduct(['name' => 'Quiet Kit', 'download_count' => 5]);
    $popular = publishedMarketplaceProduct(['name' => 'Popular Kit', 'download_count' => 5000]);

    $component = Livewire::test('pages::marketplace.index')->set('sort', 'downloads');

    expect($component->instance()->products->pluck('id')->all())
        ->toBe([$popular->id, $quiet->id]);
});

test('sorting by rating uses approved reviews only', function () {
    $wellRated = publishedMarketplaceProduct(['name' => 'Well Rated']);
    $poorlyRated = publishedMarketplaceProduct(['name' => 'Poorly Rated']);
    $unrated = publishedMarketplaceProduct(['name' => 'Unrated']);

    MarketplaceReview::factory()->approved()->rating(5)->create(['marketplace_product_id' => $wellRated->id]);
    MarketplaceReview::factory()->approved()->rating(2)->create(['marketplace_product_id' => $poorlyRated->id]);

    // A pending five-star review must not lift a listing above a genuinely
    // better-rated one.
    MarketplaceReview::factory()->rating(5)->create(['marketplace_product_id' => $unrated->id]);

    $component = Livewire::test('pages::marketplace.index')->set('sort', 'rating');

    expect($component->instance()->products->pluck('id')->all())
        ->toBe([$wellRated->id, $poorlyRated->id, $unrated->id]);
});

test('a wildcard in the search term does not match everything', function () {
    publishedMarketplaceProduct(['name' => 'Alpha Kit']);
    publishedMarketplaceProduct(['name' => 'Beta Kit']);

    $component = Livewire::test('pages::marketplace.index')->set('search', '%');

    expect($component->instance()->products->total())->toBe(0);
});

test('an inactive category is hidden from the category rail', function () {
    MarketplaceCategory::factory()->create(['name' => 'Active Category']);
    MarketplaceCategory::factory()->inactive()->create(['name' => 'Retired Category']);

    $this->get(route('marketplace.index'))
        ->assertOk()
        ->assertSee('Active Category')
        ->assertDontSee('Retired Category');
});

test('an unknown category slug is not found', function () {
    $this->get(route('marketplace.category', ['category' => 'does-not-exist']))
        ->assertNotFound();
});

test('the category route filters the grid to that category', function () {
    $category = MarketplaceCategory::factory()->create(['slug' => 'developer-tools']);
    $other = MarketplaceCategory::factory()->create(['slug' => 'ai-tools']);

    publishedMarketplaceProduct(['name' => 'Lens Kit', 'marketplace_category_id' => $category->id]);
    publishedMarketplaceProduct(['name' => 'Prompt Kit', 'marketplace_category_id' => $other->id]);

    $this->get(route('marketplace.category', ['category' => 'developer-tools']))
        ->assertOk()
        ->assertSee('Lens Kit')
        ->assertDontSee('Prompt Kit');
});

test('sorting state survives a page reload through the URL', function () {
    publishedMarketplaceProduct(['name' => 'Cheap Kit', 'price_cents' => 1000]);
    publishedMarketplaceProduct(['name' => 'Pricey Kit', 'price_cents' => 9000]);

    $component = Livewire::withQueryParams(['sort' => 'price-low'])->test('pages::marketplace.index');

    expect($component->get('sort'))->toBe('price-low')
        ->and($component->instance()->products->first()->name)->toBe('Cheap Kit');
});
