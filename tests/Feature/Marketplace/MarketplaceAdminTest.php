<?php

use App\Enums\ProductStatus;
use App\Enums\ReviewStatus;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceReview;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Admin moderation
|--------------------------------------------------------------------------
|
| The console behind `product:review`. Two things are being pinned here: the
| route guard (a seller cannot open it) and the action guard (a seller cannot
| call a queue action even if they somehow render the page).
|
*/

test('guests are bounced to the sign-in dialog from the admin marketplace', function () {
    $this->get('/admin/marketplace')->assertRedirect(route('home', ['signin' => 'required']));
});

test('accounts without product:review are refused', function () {
    $this->actingAs(User::factory()->create())->get('/admin/marketplace')->assertForbidden();
    $this->actingAs(User::factory()->seller()->create())->get('/admin/marketplace')->assertForbidden();
});

test('an admin sees both moderation queues', function () {
    MarketplaceProduct::factory()->pending()->create(['name' => 'Waiting Kit']);
    MarketplaceReview::factory()->create(['body' => 'A review waiting for a moderator to read.']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/marketplace')
        ->assertOk()
        ->assertSee('Waiting Kit')
        ->assertSee('Awaiting review');

    $this->get('/admin/marketplace?tab=reviews')
        ->assertOk()
        ->assertSee('A review waiting for a moderator to read.');
});

test('an admin can publish a pending listing', function () {
    $product = MarketplaceProduct::factory()->pending()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->call('approveProduct', $product->id)
        ->assertHasNoErrors();

    $product->refresh();

    expect($product->status)->toBe(ProductStatus::Published)
        ->and($product->published_at)->not->toBeNull();
});

test('withdrawing and re-publishing keeps the original release date', function () {
    $product = MarketplaceProduct::factory()->published()->create();
    $original = $product->published_at;

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->call('withdrawProduct', $product->id)
        ->call('approveProduct', $product->id);

    $product->refresh();

    expect($product->status)->toBe(ProductStatus::Published)
        ->and($product->published_at->timestamp)->toBe($original->timestamp);
});

test('an admin can feature and unfeature a listing', function () {
    $product = MarketplaceProduct::factory()->published()->create(['is_featured' => false]);

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->call('toggleFeatured', $product->id);

    expect($product->refresh()->is_featured)->toBeTrue();

    $component->call('toggleFeatured', $product->id);

    expect($product->refresh()->is_featured)->toBeFalse();
});

test('approving a review makes it count toward the rating', function () {
    $product = MarketplaceProduct::factory()->published()->create();
    $review = MarketplaceReview::factory()->rating(5)->create([
        'marketplace_product_id' => $product->id,
    ]);

    expect($product->refresh()->averageRating())->toBeNull();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->call('approveReview', $review->id);

    expect($review->refresh()->status)->toBe(ReviewStatus::Approved)
        ->and($product->refresh()->averageRating())->toBe(5.0);
});

test('rejecting a review keeps the row and keeps it out of the rating', function () {
    $product = MarketplaceProduct::factory()->published()->create();
    $review = MarketplaceReview::factory()->rating(1)->create([
        'marketplace_product_id' => $product->id,
    ]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->call('rejectReview', $review->id);

    expect($review->refresh()->status)->toBe(ReviewStatus::Rejected)
        ->and($product->refresh()->averageRating())->toBeNull();
});

test('the listing queue can be filtered by status', function () {
    MarketplaceProduct::factory()->pending()->create(['name' => 'Waiting Kit']);
    MarketplaceProduct::factory()->published()->create(['name' => 'Live Kit']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->set('status', 'pending')
        ->assertSee('Waiting Kit')
        ->assertDontSee('Live Kit');
});

test('the listing queue can be searched', function () {
    MarketplaceProduct::factory()->pending()->create(['name' => 'Waiting Kit']);
    MarketplaceProduct::factory()->pending()->create(['name' => 'Other Kit']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace')
        ->set('search', 'Waiting')
        ->assertSee('Waiting Kit')
        ->assertDontSee('Other Kit');
});

test('queue actions re-check the permission on the server', function () {
    $product = MarketplaceProduct::factory()->pending()->create();
    $seller = User::factory()->seller()->create();

    // Open the console as an admin, then swap the authenticated account before
    // the action runs. The page guard has already passed; only the per-action
    // check can stop this call.
    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.marketplace');

    $this->actingAs($seller);

    $component->call('approveProduct', $product->id)->assertForbidden();

    expect($product->refresh()->status)->toBe(ProductStatus::PendingReview);
});

test('an unknown queue tab returns not found', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/marketplace?tab=payouts')
        ->assertNotFound();
});
