<?php

namespace App\Providers;

use App\Models\Post;
use App\Observers\PostObserver;
use App\Support\Seo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * SEO metadata is scoped, not shared: every Livewire request gets a
         * fresh instance, so a page can never inherit the previous page's
         * share card. The head partial resolves it lazily, so pages that do not
         * declare metadata still render correctly from the fallbacks.
         */
        $this->app->scoped(Seo::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Category and tag counters are denormalised and rendered in public
         * navigation. Reconciling them on every post write — rather than only
         * inside the publish/unpublish actions — keeps the invariant true for
         * imports, console commands and tests as well.
         */
        Post::observe(PostObserver::class);

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
