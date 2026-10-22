<?php

use App\Enums\AccountPermission;
use App\Enums\ProductStatus;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The seller listing pipeline
|--------------------------------------------------------------------------
|
| Three screens and four writers make the pipeline: the shelf, the editor, and
| the create / update / submit / delete actions between them. The rules that
| decide whether a listing may move are deliberately not in the screens —
| completeness and plan quota belong to `SubmitProduct`, ownership to the
| policy — so the tests that matter here are that the screens ask those
| authorities the right questions and report the answers honestly.
|
| Two failure modes are worth more than a green path: a submission that should
| have been refused and was not, and one seller reaching another's draft. The
| second is asserted from both directions, at the route and at the component,
| because a Livewire POST never passes through the route middleware.
|
*/

/**
 * A seller's draft that carries everything a reviewer needs.
 *
 * @param  array<string, mixed>  $attributes
 */
function pipelineDraft(?User $seller = null, array $attributes = []): MarketplaceProduct
{
    return MarketplaceProduct::factory()->create(
        ['user_id' => ($seller ?? User::factory()->seller()->create())->id] + $attributes
    );
}

/**
 * A seller whose every plan slot is already taken by a submitted listing.
 *
 * @return array{User, MarketplaceProduct}
 */
function sellerAtPlanCap(): array
{
    $seller = User::factory()->seller()->create();

    MarketplaceProduct::factory()
        ->count(25)
        ->pending()
        ->create(['user_id' => $seller->id]);

    return [$seller, pipelineDraft($seller)];
}

test('the listing screens are offered to the accounts that may open them', function () {
    $this->actingAs(User::factory()->seller()->create())
        ->get('/seller/products')
        ->assertOk();

    $this->actingAs(User::factory()->seller()->create())
        ->get('/seller/products/new')
        ->assertOk();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/seller/products')
        ->assertOk();

    $this->actingAs(User::factory()->create())
        ->get('/seller/products')
        ->assertForbidden();
});

test('a guest is bounced from the shelf before the permission is asked', function () {
    $this->get('/seller/products')
        ->assertRedirect(route('home', ['signin' => 'required']));

    $this->get('/seller/products/new')
        ->assertRedirect(route('home', ['signin' => 'required']));
});

test('both listing components restate the guard the route carries', function () {
    $plain = User::factory()->create();

    expect($plain->hasPermission(AccountPermission::CreateProduct))->toBeFalse();

    // The endpoint is the one that matters: a Livewire POST never passes
    // through the route middleware, so the boot guard has to hold alone.
    Livewire::actingAs($plain)->test('pages::seller.products')->assertForbidden();
    Livewire::actingAs($plain)->test('pages::seller.product-edit')->assertForbidden();
})->group('security');

test('a draft saved from the blank editor lands on the shelf as a draft', function () {
    $seller = User::factory()->seller()->create();
    $category = MarketplaceCategory::factory()->create();

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit')
        ->set('name', 'Shelf Report Builder')
        ->set('tagline', 'Turn a table of rows into a page somebody can read.')
        ->set('description', 'A long description of the listing, written for a reviewer to judge.')
        ->set('priceCents', 1999)
        ->set('currency', 'USD')
        ->set('categoryId', $category->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('seller.products'));

    $product = MarketplaceProduct::query()->sole();

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->user_id)->toBe($seller->id)
        ->and($product->marketplace_category_id)->toBe($category->id)
        ->and($product->price_cents)->toBe(1999)
        // The form never insists on a slug, so the writer derives one from the name.
        ->and($product->slug)->toBe('shelf-report-builder');

    Livewire::actingAs($seller)
        ->test('pages::seller.products')
        ->assertSee('Shelf Report Builder');
});

test('a listing cannot be saved under a slug another listing already holds', function () {
    $seller = User::factory()->seller()->create();
    pipelineDraft($seller, ['name' => 'Taken Name', 'slug' => 'taken-name']);

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit')
        ->set('name', 'Something Else')
        ->set('slug', 'taken-name')
        ->set('tagline', 'A tagline long enough to describe the listing.')
        ->set('description', 'A description long enough to satisfy the length rule.')
        ->call('save')
        ->assertHasErrors(['slug' => 'unique']);

    expect(MarketplaceProduct::query()->count())->toBe(1);
});

test('saving a draft under its own unchanged slug is not a collision', function () {
    $seller = User::factory()->seller()->create();
    $product = pipelineDraft($seller, ['slug' => 'my-own-slug', 'tagline' => 'The first tagline.']);

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->set('tagline', 'The tagline as the seller revised it.')
        ->call('save')
        ->assertHasNoErrors(['slug']);

    expect($product->refresh()->tagline)->toBe('The tagline as the seller revised it.')
        ->and($product->slug)->toBe('my-own-slug');
});

test('a half-written draft is refused and the screen says what is missing', function () {
    $seller = User::factory()->seller()->create();
    $product = pipelineDraft($seller, ['tagline' => '', 'marketplace_category_id' => null]);

    $component = Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->call('submit');

    expect($component->instance()->submissionGaps)->toBe(['tagline', 'category'])
        ->and($product->refresh()->status)->toBe(ProductStatus::Draft);

    // The same rule read from the shelf, where the row action refuses too.
    Livewire::actingAs($seller)
        ->test('pages::seller.products')
        ->call('submit', $product->id);

    expect($product->refresh()->status)->toBe(ProductStatus::Draft);
});

test('a complete draft moves to review and leaves the seller hands', function () {
    $seller = User::factory()->seller()->create();
    $product = pipelineDraft($seller);

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->call('submit')
        ->assertRedirect(route('seller.products'));

    expect($product->refresh()->status)->toBe(ProductStatus::PendingReview);
});

test('a submitted listing is readable but no longer writable', function () {
    $seller = User::factory()->seller()->create();
    $product = pipelineDraft($seller, ['status' => ProductStatus::PendingReview]);

    // Each move gets its own instance: an aborted action ends the request, so
    // the snapshot cannot be carried into a second call.
    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->assertOk()
        ->call('save')
        ->assertForbidden();

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->call('submit')
        ->assertForbidden();

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->call('delete')
        ->assertForbidden();

    expect($product->refresh()->status)->toBe(ProductStatus::PendingReview);
});

test('a listing at the plan cap is not admitted to the queue', function () {
    [$seller, $draft] = sellerAtPlanCap();

    Livewire::actingAs($seller)
        ->test('pages::seller.products')
        ->call('submit', $draft->id);

    expect($draft->refresh()->status)->toBe(ProductStatus::Draft)
        ->and(MarketplaceProduct::query()->occupyingQuota()->count())->toBe(25);
});

test('a plan without a cap never refuses a submission', function () {
    $platform = User::factory()->admin()->create();

    MarketplaceProduct::factory()
        ->count(30)
        ->pending()
        ->create(['user_id' => $platform->id]);

    $draft = pipelineDraft($platform);

    Livewire::actingAs($platform)
        ->test('pages::seller.product-edit', ['product' => $draft])
        ->call('submit');

    expect($draft->refresh()->status)->toBe(ProductStatus::PendingReview);
});

test('another account cannot reach a draft through the shelf or the editor', function () {
    $product = pipelineDraft(User::factory()->seller()->create(), ['name' => 'Private Underwriting Tool']);

    $stranger = User::factory()->seller()->create();

    expect($stranger->hasPermission(AccountPermission::CreateProduct))->toBeTrue();

    Livewire::actingAs($stranger)
        ->test('pages::seller.products')
        ->assertDontSee('Private Underwriting Tool');

    // 404 rather than 403: a stranger is not told the listing exists.
    $this->actingAs($stranger)
        ->get('/seller/products/'.$product->slug)
        ->assertNotFound();

    Livewire::actingAs($stranger)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->assertNotFound();

    expect($product->refresh()->status)->toBe(ProductStatus::Draft);
})->group('security');

test('a seller deletes their own draft and it leaves the shelf', function () {
    $seller = User::factory()->seller()->create();
    $product = pipelineDraft($seller, ['name' => 'Retired Draft']);

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->call('delete')
        ->assertRedirect(route('seller.products'));

    expect(MarketplaceProduct::withTrashed()->findOrFail($product->id)->trashed())->toBeTrue();

    Livewire::actingAs($seller)
        ->test('pages::seller.products')
        ->assertDontSee('Retired Draft');
});

test('the shelf submits and deletes a listing from the row', function () {
    $seller = User::factory()->seller()->create();
    $submitted = pipelineDraft($seller, ['name' => 'Row Submit Target']);
    $deleted = pipelineDraft($seller, ['name' => 'Row Delete Target']);

    Livewire::actingAs($seller)
        ->test('pages::seller.products')
        ->call('submit', $submitted->id)
        ->call('delete', $deleted->id);

    expect($submitted->refresh()->status)->toBe(ProductStatus::PendingReview)
        // `refresh()` reads a soft-deleted row back, so ask the shelf itself.
        ->and(MarketplaceProduct::query()->whereKey($deleted->id)->exists())->toBeFalse()
        ->and(MarketplaceProduct::withTrashed()->findOrFail($deleted->id)->trashed())->toBeTrue();
});

test('the shelf narrows to one status and back without leaving the account', function () {
    $seller = User::factory()->seller()->create();
    pipelineDraft($seller, ['name' => 'Drafts Only Here']);
    pipelineDraft($seller, ['name' => 'Published Elsewhere', 'status' => ProductStatus::Published]);

    Livewire::actingAs($seller)
        ->test('pages::seller.products')
        ->assertSee('Published Elsewhere')
        ->assertSee('Drafts Only Here')
        ->set('statusFilter', ProductStatus::Published->value)
        ->assertSee('Published Elsewhere')
        ->assertDontSee('Drafts Only Here')
        ->set('statusFilter', 'all')
        ->set('search', 'Drafts Only Here')
        ->assertSee('Drafts Only Here')
        ->assertDontSee('Published Elsewhere');
});

test('the tags a seller commits are the tags that save', function () {
    $seller = User::factory()->seller()->create();
    $product = pipelineDraft($seller);

    $component = Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->set('tagInput', 'Reports')
        ->call('addTag')
        ->set('tagInput', '  reports  ')
        ->call('addTag')
        ->set('tagInput', '')
        ->call('addTag')
        ->set('tagInput', 'Invoicing')
        ->call('addTag');

    // Case-insensitive dedupe, no empty chip, and the typed word is cleared.
    expect($component->get('tags'))->toBe(['Reports', 'Invoicing'])
        ->and($component->get('tagInput'))->toBe('');

    $component->call('removeTag', 1)->call('save')->assertHasNoErrors();

    expect($component->get('tags'))->toBe(['Reports'])
        ->and($product->refresh()->tags)->toBe(['Reports']);
});

test('a price cleared from the field is a free listing rather than a broken save', function () {
    $seller = User::factory()->seller()->create();
    $product = pipelineDraft($seller, ['price_cents' => 1999]);

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->set('priceCents', null)
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->price_cents)->toBe(0);
});

test('the category a listing is filed under survives the editor', function () {
    $seller = User::factory()->seller()->create();
    $category = MarketplaceCategory::factory()->create(['name' => 'Developer Tools']);
    $product = pipelineDraft($seller, ['marketplace_category_id' => $category->id]);

    $component = Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $product])
        ->assertSee('Developer Tools')
        ->set('currency', 'BDT');

    expect($component->instance()->currencySymbol)->toBe('৳');

    $component->call('save')->assertHasNoErrors();

    expect($product->refresh()->marketplace_category_id)->toBe($category->id)
        ->and($product->currency)->toBe('BDT')
        ->and($product->slug)->toBe(Str::slug($product->name));
});

test('the dashboard counts the seller own listings and the quota behind them', function () {
    $seller = User::factory()->seller()->create();
    pipelineDraft($seller);
    pipelineDraft($seller, ['status' => ProductStatus::PendingReview]);
    pipelineDraft($seller, ['status' => ProductStatus::Published]);
    pipelineDraft($seller, ['status' => ProductStatus::Archived]);

    // A neighbour in the same statuses must not move these numbers.
    pipelineDraft(User::factory()->seller()->create());

    $listings = Livewire::actingAs($seller)
        ->test('pages::seller.dashboard')
        ->assertSee('Drafts')
        ->instance()
        ->listings;

    expect($listings)->toBe([
        'draft' => 1,
        'pending' => 1,
        'published' => 1,
        'archived' => 1,
        'limit' => 25,
        'used' => 2,
    ]);
});

test('the dashboard opens onto the pipeline rather than describing it as unbuilt', function () {
    Livewire::actingAs(User::factory()->seller()->create())
        ->test('pages::seller.dashboard')
        ->assertSee('New product')
        ->assertSee('Manage products')
        ->assertDontSee('Product listings are not available yet');
});
