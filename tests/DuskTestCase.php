<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Extra flags for the Chrome instance this test's browser uses.
     *
     * A test sets these before calling `browse()` when it needs a browser that
     * reports something other than the default — reduced motion is the only
     * current caller, and it is the only honest way to test a
     * `prefers-reduced-motion` stylesheet without changing the OS.
     *
     * Public rather than protected because Pest runs a test file's closures
     * outside the class scope, so a `protected` write from one is illegal even
     * though the closure is bound to this object at runtime.
     *
     * @var list<string>
     */
    public array $extraChromeFlags = [];

    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    /**
     * Rebuild and re-seed the shared database before every test.
     *
     * This is the one place where Dusk cannot borrow Feature-suite habits. The
     * browser is driven against a separate `php artisan serve` process, which
     * opens the same SQLite file from its own connection. Everything the test
     * needs must therefore be *committed*: RefreshDatabase's usual trick —
     * migrate once per process, then wrap each test in a transaction that is
     * rolled back — would leave the browser staring at an empty database, and
     * `migrate:fresh` would have wiped the seeded corpus along with it. So the
     * per-process memoisation is dropped in favour of a fresh, seeded database
     * per test, and `beginDatabaseTransaction()` is disabled below.
     *
     * The server has to be started as `php artisan serve --host=127.0.0.1
     * --no-reload`. On Windows `serve` hands the child only an allowlist of
     * environment variables when it can hot-reload, and `TMP`/`TEMP` are not on
     * it, so PHP's multipart handler falls back to `C:\Windows`, cannot create
     * its temp file, and every upload arrives as an empty `$_FILES` — a 422
     * that looks exactly like an application bug and is none of its making.
     */
    protected function refreshTestDatabase(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Wrap the test in a database transaction.
     *
     * Deliberately empty. A transaction here is invisible to the served page:
     * it would be held on this process's PDO connection, and the browser's
     * requests are handled over there. See `refreshTestDatabase()`.
     */
    public function beginDatabaseTransaction(): void
    {
        //
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->merge($this->extraChromeFlags)->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }
}
