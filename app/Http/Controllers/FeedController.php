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
    public function __invoke(): Response
    {
        $xml = Cache::remember('feed.xml', now()->addHour(), function (): string {
            $posts = Post::query()
                ->published()
                ->recent()
                ->with('tags')
                ->limit(30)
                ->get();

            return view('feed', ['posts' => $posts])->render();
        });

        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
