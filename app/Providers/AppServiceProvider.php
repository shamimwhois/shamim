<?php

namespace App\Providers;

use App\Actions\Feed\ComposeFeedPost;
use App\Models\Post;
use App\Observers\PostObserver;
use App\Services\AiHubContent;
use App\Services\PublicTaxonomy;
use App\Support\Seo;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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

        /*
         * The hub's shelves are assembled from the same handful of aggregates,
         * and the service memoises them so a page that renders a topic grid, a
         * topic rail and a filter chip row asks the database once. Scoped rather
         * than singleton because the counts have to be re-read for every
         * request; a process-wide cache would serve stale shelves forever.
         */
        $this->app->scoped(AiHubContent::class);

        /*
         * Same reasoning as the hub's shelves, one family over: the ideas landing,
         * the gallery, and /topics all read the topic chips in a single request,
         * and all three have to agree about which topics are worth showing. Memoised
         * per request because a published idea changes the answer immediately.
         */
        $this->app->scoped(PublicTaxonomy::class);
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

        $this->configureFeedPublishingLimit();

        $this->configureDefaults();
    }

    /**
     * Name the rate limit that guards putting a post in front of the community.
     *
     * Publishing is the one write every member may make, so it is the one place a
     * script has something to gain from clicking repeatedly. Five posts a minute is
     * well above what a person can type, and administrators are exempt because the
     * moderation console legitimately works through a queue in one sitting.
     *
     * Registered as a named limiter rather than counted inline, because the
     * exemption then has exactly one home: {@see ComposeFeedPost()}
     * reads this back and cannot forget to honour it.
     */
    protected function configureFeedPublishingLimit(): void
    {
        RateLimiter::for('feed-publish', function (Request $request): Limit {
            $user = $request->user();

            if ($user === null || $user->isAdmin()) {
                return Limit::none();
            }

            return new Limit(
                'feed-publish:'.$user->getKey(),
                (int) config('feed.publishing.rate_limit_attempts', 5),
                (int) config('feed.publishing.rate_limit_decay_seconds', 60),
            );
        });
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
