<?php

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Catalogue
|--------------------------------------------------------------------------
|
| The public storefront. What matters here is that the catalogue is a *view* of
| published listings and nothing else: a draft must never leak into a search
| result, a category count or the hero spotlight.
|
*/

test('the catalogue lists published listings and nothing else', function () {
    MarketplaceProduct::factory()->published()->create(['name' => 'Visible Toolkit']);
    MarketplaceProduct::factory()->pending()->create(['name' => 'Awaiting Toolkit']);
    MarketplaceProduct::factory()->archived()->create(['name' => 'Retired Toolkit']);
    MarketplaceProduct::factory()->create(['name' => 'Draft Toolkit']);

    $this->get(route('marketplace.index'))
        ->assertOk()
        ->assertSee('Visible Toolkit')
        ->assertDontSee('Awaiting Toolkit')
        ->assertDontSee('Retired Toolkit')
        ->assertDontSee('Draft Toolkit');
});

test('search matches the name, the tagline and the description', function () {
    MarketplaceProduct::factory()->published()->create([
        'name' => 'Ledger Kit',
        'tagline' => 'An append-only audit trail.',
        'description' => 'Records every change to a model.',
    ]);

    MarketplaceProduct::factory()->published()->create([
        'name' => 'Queue Kit',
        'tagline' => 'A queue inspector.',
        'description' => 'Reads throughput and failures.',
    ]);

    Livewire::test('pages::marketplace.index')
        ->set('search', 'append-only')
        ->assertSee('Ledger Kit')
        ->assertDontSee('Queue Kit');

    Livewire::test('pages::marketplace.index')
        ->set('search', 'throughput')
        ->assertSee('Queue Kit')
        ->assertDontSee('Ledger Kit');
});

test('the price filter separates free listings from paid ones', function () {
    MarketplaceProduct::factory()->published()->free()->create(['name' => 'Free Kit']);
    MarketplaceProduct::factory()->published()->priced(4900)->create(['name' => 'Paid Kit']);

    Livewire::test('pages::marketplace.index')
        ->set('price', 'free')
        ->assertSee('Free Kit')
        ->assertDontSee('Paid Kit')
        ->set('price', 'paid')
        ->assertSee('Paid Kit')
        ->assertDontSee('Free Kit');
});

test('sorting reorders the catalogue by price in both directions', function () {
    $cheap = MarketplaceProduct::factory()->published()->priced(1000)->create(['name' => 'Cheap Kit']);
    $expensive = MarketplaceProduct::factory()->published()->priced(9000)->create(['name' => 'Expensive Kit']);

    $component = Livewire::test('pages::marketplace.index')->set('sort', 'price-low');

    expect($component->instance()->products->pluck('id')->all())
        ->toBe([$cheap->id, $expensive->id]);

    $component->set('sort', 'price-high');

    expect($component->instance()->products->pluck('id')->all())
        ->toBe([$expensive->id, $cheap->id]);
});

test('the category route seeds the category filter', function () {
    $category = MarketplaceCategory::factory()->create([
        'name' => 'Developer Tools',
        'slug' => 'developer-tools',
    ]);

    MarketplaceProduct::factory()->published()->create([
        'marketplace_category_id' => $category->id,
        'name' => 'Lens Kit',
    ]);

    MarketplaceProduct::factory()->published()->create(['name' => 'Other Kit']);

    $this->get(route('marketplace.category', ['category' => 'developer-tools']))
        ->assertOk()
        ->assertSee('Lens Kit')
        ->assertDontSee('Other Kit');
});

test('a category count only includes published listings', function () {
    $category = MarketplaceCategory::factory()->create(['slug' => 'ui-components']);

    MarketplaceProduct::factory()->published()->create(['marketplace_category_id' => $category->id]);
    MarketplaceProduct::factory()->create(['marketplace_category_id' => $category->id]);

    $component = Livewire::test('pages::marketplace.index');

    $listed = $component->instance()->categories->firstWhere('slug', 'ui-components');

    expect($listed->published_products_count)->toBe(1);
});

test('an empty result set shows the empty state rather than an empty grid', function () {
    MarketplaceProduct::factory()->published()->create(['name' => 'Only Kit']);

    $this->get(route('marketplace.index', ['q' => 'nothing-matches-this-term']))
        ->assertOk()
        ->assertSee('No listings match this view')
        ->assertDontSee('Only Kit');
});

test('clearing the filters restores the full catalogue', function () {
    MarketplaceProduct::factory()->published()->create(['name' => 'First Kit']);
    MarketplaceProduct::factory()->published()->priced(2900)->create(['name' => 'Second Kit']);

    Livewire::test('pages::marketplace.index')
        ->set('search', 'nothing-matches-this-term')
        ->set('price', 'paid')
        ->assertDontSee('First Kit')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('price', 'all')
        ->assertSee('First Kit')
        ->assertSee('Second Kit');
});

test('a guest can read the catalogue', function () {
    MarketplaceProduct::factory()->published()->create(['name' => 'Guest Kit']);

    $this->get(route('marketplace.index'))
        ->assertOk()
        ->assertSee('Guest Kit');
});
