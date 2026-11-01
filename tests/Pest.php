<?php

use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Enums\ProductLicense;
use App\Enums\ProductStatus;
use App\Models\FeedPost;
use App\Models\MarketplaceProduct;
use App\Models\PaymentSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\DuskTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Two suites, two bindings:
|
|   Feature — boots the application *and* migrates a fresh in-memory SQLite
|             database for every test. Anything that touches Eloquent, a
|             route, a Livewire component or a seeder belongs here.
|
|   Unit    — boots the application but does **not** migrate. These tests
|             exercise value objects, enums and pure model methods, so a
|             query in a unit test is a mistake and should fail loudly
|             rather than quietly hitting an empty schema.
|
| Booting the app in Unit (rather than plain PHPUnit) is deliberate: the
| models call `__()` for labels and read `config()`, both of which need the
| container. A unit test that cannot translate a label is not testing the
| product.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Security group
|--------------------------------------------------------------------------
|
| Everything that proves a boundary holds — the guard sweeps, mass assignment,
| markup injection, throttling, auth configuration — carries the `security`
| group, so `composer test:security` runs the authorization surface alone
| without a path list that goes stale the day a suite is renamed.
|
*/

pest()->group('security')->in('Feature/Security', 'Feature/Routing', 'Feature/Admin', 'Feature/AdminFeedTest.php');

/*
|--------------------------------------------------------------------------
| Browser suite
|--------------------------------------------------------------------------
|
| The Dusk tests in `tests/Browser` are Pest files too, and their `$this`
| must be a `DuskTestCase` — the class that starts Chrome, rebuilds the
| shared SQLite database and seeds the accounts a real browser can log into.
| They are not in `phpunit.xml`'s two suites, so `php artisan test` never
| collects them; only `php artisan dusk` does.
|
| The binding has to sit below the `use` statements: Pest extracts these
| calls with its own parser, and above the imports the class name it reads
| is the unaliased `DuskTestCase`, which resolves to nothing.
|
*/

pest()->extend(DuskTestCase::class)->in('Browser');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Only what earns its keep. A helper that hides a factory call behind a
| different name is worse than the factory call itself.
|
*/

/**
 * Skip the calling test when the GD extension is missing.
 *
 * `TemporaryUploadedFile::fake()->image()` draws its sample bitmap with GD, so
 * on a PHP without the extension the fixture itself cannot be built. That is an
 * absent module, not a defect in the code under test, and reporting it as a
 * failure trains everyone to read the suite as noisy. It is a global helper
 * rather than a method on `Tests\TestCase` because the image fixtures are also
 * built by the plain functions above, which have no `$this`.
 */
function requiresGd(): void
{
    if (! function_exists('imagecreatetruecolor')) {
        test()->markTestSkipped('The GD extension is not installed, so no test image can be generated.');
    }
}

/**
 * Create a published marketplace listing.
 *
 * Publication is the precondition of almost every catalogue test, and the
 * factory's `published()` state also stamps `published_at` — the one field a
 * test is most likely to forget and then spend ten minutes debugging.
 *
 * @param  array<string, mixed>  $attributes
 */
function publishedMarketplaceProduct(array $attributes = []): MarketplaceProduct
{
    return MarketplaceProduct::factory()->published()->create($attributes);
}

/**
 * Switch a provider on in the payment console, as an operator would.
 *
 * `payment_settings.provider` is unique — the table holds one row per provider
 * — and a fixture that buys two listings must not fail because it configured
 * the same gateway twice, so the row is written with `updateOrCreate`.
 */
function enablePaymentProvider(PaymentProvider $provider, array $attributes = []): PaymentSetting
{
    return PaymentSetting::query()->updateOrCreate(
        ['provider' => $provider],
        $attributes + ['enabled' => true, 'mode' => PaymentMode::Test, 'currency' => $provider->defaultCurrency()],
    );
}

/**
 * Build an unsaved marketplace listing for unit-level assertions.
 *
 * No database, no factory: a unit test that reaches for the factory also
 * reaches for its related models, which quietly turns a value-object test
 * into an integration test. Every default here is chosen so the model is in a
 * realistic published state without a single query.
 *
 * @param  array<string, mixed>  $attributes
 */
function marketplaceProduct(array $attributes = []): MarketplaceProduct
{
    /*
     * `forceFill`, not the constructor: `download_count` is deliberately not
     * mass-assignable (a seller must not be able to set their own download
     * count), so a guarded fill would silently drop it and every assertion
     * about the counter would compare against null.
     */
    return (new MarketplaceProduct)->forceFill($attributes + [
        'name' => 'Test Listing',
        'slug' => 'test-listing',
        'tagline' => 'A listing built for a unit test.',
        'description' => 'Body copy for the listing.',
        'price_cents' => 0,
        'currency' => 'USD',
        'license' => ProductLicense::Mit,
        'status' => ProductStatus::Published,
        'published_at' => now(),
        'download_count' => 0,
    ]);
}

/**
 * Build an unsaved feed post for unit-level visibility assertions.
 *
 * The feed's visibility rule is a predicate over columns the row already carries,
 * so it can be tested without a schema — and it must be, because the Unit suite
 * never migrates. Defaults mirror a row fresh from the database: published,
 * public, unscheduled, not hidden, not removed.
 *
 * @param  array<string, mixed>  $attributes
 */
function feedPost(array $attributes = []): FeedPost
{
    return (new FeedPost)->forceFill($attributes + [
        'slug' => 'unit-built-post',
        'author' => 'Feed Tester',
        'handle' => '@feedtester',
        'avatar' => 'FT',
        'topic' => 'community',
        'type' => 'discussion',
        'body' => 'A post assembled in memory, never written to a table.',
        'tags' => ['Testing'],
        'reactions' => ['like' => 0, 'love' => 0, 'insight' => 0],
        'posted_at' => now(),
    ]);
}
