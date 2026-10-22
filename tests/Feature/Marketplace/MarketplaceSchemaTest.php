<?php

use App\Enums\OrderStatus;
use App\Models\BillingProfile;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductMedia;
use App\Models\MarketplaceProductVersion;
use App\Models\MarketplaceRefund;
use App\Models\MarketplaceReview;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
|
| Migrations are the part of the module that is hardest to change later, so
| they get their own tests: the tables exist, the columns the code reads are
| present, the unique indexes that make the data meaningful are enforced, and
| the delete rules do what the module assumes.
|
| These run through `RefreshDatabase`, which is the real proof that
| `php artisan migrate` succeeds from an empty database.
|
*/

test('the marketplace migrations create their tables', function () {
    foreach ([
        'marketplace_categories',
        'marketplace_products',
        'marketplace_product_versions',
        'marketplace_product_media',
        'marketplace_reviews',
        'billing_profiles',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table [{$table}].");
    }
});

test('marketplace_products carries every column the module reads', function () {
    $columns = [
        'id',
        'marketplace_category_id',
        'user_id',
        'name',
        'slug',
        'tagline',
        'description',
        'price_cents',
        'currency',
        'license',
        'version',
        'framework',
        'language',
        'install_command',
        'demo_url',
        'docs_url',
        'repository_url',
        'tags',
        'status',
        'is_featured',
        'download_count',
        'published_at',
        'last_released_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('marketplace_products', $column))
            ->toBeTrue("marketplace_products is missing [{$column}].");
    }
});

test('the catalogue filter and sort have an index behind them', function () {
    $indexes = collect(Schema::getIndexes('marketplace_products'));

    $covered = $indexes->contains(
        fn (array $index): bool => $index['columns'] === ['status', 'published_at'],
    );

    expect($covered)->toBeTrue('No index covers (status, published_at).');
});

test('two listings cannot share a slug', function () {
    MarketplaceProduct::factory()->create(['slug' => 'shared-slug']);

    expect(fn () => MarketplaceProduct::factory()->create(['slug' => 'shared-slug']))
        ->toThrow(QueryException::class);
});

test('a product can only hold one release per version number', function () {
    $product = MarketplaceProduct::factory()->create();

    MarketplaceProductVersion::factory()->for($product, 'product')->create(['version' => '1.0.0']);

    expect(fn () => MarketplaceProductVersion::factory()->for($product, 'product')->create(['version' => '1.0.0']))
        ->toThrow(QueryException::class);
});

test('an account can review a listing only once', function () {
    $product = MarketplaceProduct::factory()->create();
    $review = MarketplaceReview::factory()->create(['marketplace_product_id' => $product->id]);

    expect(fn () => MarketplaceReview::factory()->create([
        'marketplace_product_id' => $product->id,
        'user_id' => $review->user_id,
    ]))->toThrow(QueryException::class);
});

test('deleting a listing removes its releases and reviews', function () {
    $product = MarketplaceProduct::factory()->create();

    MarketplaceProductVersion::factory()->count(2)->for($product, 'product')->create();
    MarketplaceReview::factory()->count(2)->create(['marketplace_product_id' => $product->id]);

    $product->forceDelete();

    expect(MarketplaceProductVersion::query()->count())->toBe(0)
        ->and(MarketplaceReview::query()->count())->toBe(0);
});

test('a release stores the deliverable it carries, and may carry none', function () {
    // Every deliverable column is nullable: the release whose install command is
    // `composer require …` is a complete thing, not a half-written row.
    foreach (['disk', 'path', 'mime', 'size', 'original_name'] as $column) {
        expect(Schema::hasColumn('marketplace_product_versions', $column))
            ->toBeTrue("marketplace_product_versions is missing [{$column}].");
    }

    $product = MarketplaceProduct::factory()->create();
    $release = MarketplaceProductVersion::factory()->for($product, 'product')->create();

    $fillable = $release->forceFill([
        'disk' => 'marketplace_media',
        'path' => 'deliverables/fixture.zip',
        'mime' => 'application/zip',
        'size' => 4096,
        'original_name' => 'fixture.zip',
    ])->save();

    expect($fillable)->toBeTrue()
        ->and($release->refresh()->path)->toBe('deliverables/fixture.zip')
        ->and($release->size)->toBe(4096);
});

test('deleting a listing takes its screenshots, and the gallery is indexed', function () {
    $product = MarketplaceProduct::factory()->create();
    MarketplaceProductMedia::factory()->count(2)->create(['marketplace_product_id' => $product->id]);

    $covered = array_map(
        fn (array $index): array => $index['columns'],
        Schema::getIndexes('marketplace_product_media'),
    );

    // The gallery is always read as one listing's images in the seller's order.
    expect($covered)->toContain(['marketplace_product_id', 'sort_order']);

    $product->forceDelete();

    expect(MarketplaceProductMedia::query()->count())->toBe(0);
});

test('an account has one billing identity, and it goes when the account does', function () {
    $buyer = User::factory()->create();

    BillingProfile::factory()->create(['user_id' => $buyer->id]);

    expect(fn () => BillingProfile::factory()->create(['user_id' => $buyer->id]))
        ->toThrow(QueryException::class);

    $buyer->delete();

    expect(BillingProfile::query()->count())->toBe(0);
});

test('an order keeps the billing details it was issued with', function () {
    foreach (['billing_name', 'billing_company', 'billing_tax_id', 'billing_email', 'billing_address'] as $column) {
        expect(Schema::hasColumn('marketplace_orders', $column))
            ->toBeTrue("marketplace_orders is missing [{$column}].");
    }

    // Nullable, because the columns were added to a table that already had
    // orders in it, and an invoice is written from them rather than from the
    // buyer's live profile.
    $order = MarketplaceOrder::factory()->create();

    $filled = $order->forceFill([
        'billing_name' => 'Northwind Traders',
        'billing_company' => 'Northwind Traders Ltd',
        'billing_tax_id' => 'GB123456789',
        'billing_email' => 'accounts@northwind.example',
        'billing_address' => '1 Redmond Way, Redmont BX1 1AA, GB',
    ])->save();

    expect($filled)->toBeTrue()
        ->and($order->refresh()->billing_tax_id)->toBe('GB123456789');
});

test('deleting a category leaves its listings in place and uncategorised', function () {
    $category = MarketplaceCategory::factory()->create();
    $product = MarketplaceProduct::factory()->create(['marketplace_category_id' => $category->id]);

    $category->delete();

    expect($product->refresh()->marketplace_category_id)->toBeNull();
});

test('a soft-deleted listing keeps its row but leaves the catalogue', function () {
    $product = MarketplaceProduct::factory()->published()->create();

    $product->delete();

    expect(MarketplaceProduct::query()->count())->toBe(0)
        ->and(MarketplaceProduct::withTrashed()->count())->toBe(1);
});

test('the money column stores minor units as an integer', function () {
    $product = MarketplaceProduct::factory()->create(['price_cents' => 12900]);

    expect($product->refresh()->price_cents)->toBeInt()->toBe(12900)
        ->and($product->priceInMajorUnits())->toBe(129.0);
});

/*
|--------------------------------------------------------------------------
| The purchase ledger
|--------------------------------------------------------------------------
*/

test('the order, payment and refund migrations create their tables', function () {
    foreach (['marketplace_orders', 'marketplace_payments', 'marketplace_refunds'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table [{$table}].");
    }
});

test('marketplace_orders stores the snapshot a receipt is printed from', function () {
    foreach ([
        'order_number',
        'user_id',
        'marketplace_product_id',
        'product_name',
        'product_slug',
        'price_cents',
        'currency',
        'commission_cents',
        'status',
        'payment_provider',
        'payment_mode',
        'paid_at',
        'cancelled_at',
        'refunded_at',
        'failure_reason',
    ] as $column) {
        expect(Schema::hasColumn('marketplace_orders', $column))
            ->toBeTrue("marketplace_orders is missing [{$column}].");
    }
});

test('the money columns are unsigned integers and the platform take starts at nothing', function () {
    $order = MarketplaceOrder::factory()->create();

    expect($order->fresh()->price_cents)->toBeInt()
        ->and($order->commission_cents)->toBe(0)
        ->and($order->status)->toBe(OrderStatus::Pending);
});

test('two orders cannot share an order number', function () {
    $order = MarketplaceOrder::factory()->create();

    expect(fn () => MarketplaceOrder::factory()->create(['order_number' => $order->order_number]))
        ->toThrow(QueryException::class);
});

test('a deleted buyer takes their orders, attempts and refunds with them', function () {
    $buyer = User::factory()->create();
    $order = MarketplaceOrder::factory()->forBuyer($buyer)->create();
    MarketplacePayment::factory()->create(['marketplace_order_id' => $order->getKey()]);
    MarketplaceRefund::factory()->create(['marketplace_order_id' => $order->getKey()]);

    $buyer->delete();

    // Scoped to this order: each factory builds a row of its own to hang a
    // default off, and those belong to other accounts.
    expect(MarketplaceOrder::query()->whereKey($order->getKey())->count())->toBe(0)
        ->and(MarketplacePayment::query()->where('marketplace_order_id', $order->getKey())->count())->toBe(0)
        ->and(MarketplaceRefund::query()->where('marketplace_order_id', $order->getKey())->count())->toBe(0);
});

test('a force-deleted listing leaves the order readable from its snapshot', function () {
    /*
     * The rule the consoles depend on: deleting the thing that was sold must
     * never take the receipt with it, and must never leave an order pointing at
     * a missing row in a way that breaks the list. The FK goes null; the
     * snapshot columns are what the screens render.
     */
    $product = publishedMarketplaceProduct();
    $order = MarketplaceOrder::factory()->forProduct($product)->paid()->create();

    $product->forceDelete();

    $order->refresh();

    expect($order->marketplace_product_id)->toBeNull()
        ->and($order->product_name)->toBe($product->name)
        ->and($order->product_slug)->toBe($product->slug)
        ->and($order->price_cents)->toBe($product->price_cents)
        ->and(MarketplaceOrder::with('product')->count())->toBe(1);
});

test('the order list is indexed for the two queries that run on every page', function () {
    $covered = array_map(
        fn (array $index): array => $index['columns'],
        Schema::getIndexes('marketplace_orders'),
    );

    // A buyer's own history, newest first, and the same list filtered by state.
    expect($covered)->toContain(['user_id', 'created_at'])
        ->and($covered)->toContain(['user_id', 'status']);
});
