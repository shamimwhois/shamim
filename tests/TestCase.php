<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application from a fresh read of the config files.
     *
     * A config cache written by `php artisan optimize` is loaded in preference
     * to the environment, so phpunit.xml's overrides would be ignored and the
     * suite would load the real database, cache and mail transport.
     */
    public function createApplication(): Application
    {
        $cachedConfig = dirname(__DIR__).'/bootstrap/cache/config.php';

        if (is_file($cachedConfig)) {
            @unlink($cachedConfig);
        }

        return parent::createApplication();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
