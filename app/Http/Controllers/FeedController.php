<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * RSS 2.0 feed for the writing.
 *
 * Kept as a plain controller rather than a Livewire component: feeds are
 * fetched by machines, need to be cheap, and gain nothing from reactivity.
 * Cached for an hour because the underlying content changes at human speed.
 */
class FeedController extends Controller
{
    /**
     * Cache key for the rendered feed.
     *
     * Public so observers can reference it: publishing, editing, deleting or
     * restoring a post all change the feed's contents.
     */
    public const CACHE_KEY = 'feed.xml';

    /**
     * Evict the rendered feed.
     *
     * Every row the template reads — a post, its category, its tags, or its
     * author's name — changes the output, so the write paths for those models
     * call this rather than each knowing the key.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function __invoke(): Response
    {
        $xml = Cache::remember(self::CACHE_KEY, now()->addHour(), function (): string {
            $posts = Post::query()
                ->published()
                ->recent()
                ->with(['tags', 'category', 'user'])
                ->limit(30)
                ->get();

            /*
             * Prefix the XML declaration in PHP rather than Blade: a literal
             * `<?xml` in a Blade file is tokenised as a PHP open tag whenever
             * short_open_tag is enabled, which breaks compilation.
             */
            return '<?xml version="1.0" encoding="UTF-8"?>'
                ."\n"
                .view('feed', ['posts' => $posts])->render();
        });

        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
