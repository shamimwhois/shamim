<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Observers\FeedObserver;
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

        /*
         * The feed renders category names, tag names and author names next to
         * each post, so a rename in any of those tables also has to drop the
         * cached XML or readers see labels the site no longer uses.
         */
        Category::observe(FeedObserver::class);
        Tag::observe(FeedObserver::class);
        User::observe(FeedObserver::class);

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        /*
         * Debug mode must never leak stack traces, query bindings or
         * environment values in production. The environment default is
         * already `false`, but an operator (or a copied `.env`) can flip
         * APP_DEBUG by mistake — this guard wins either way.
         */
        if (app()->isProduction()) {
            config(['app.debug' => false]);
        }

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
