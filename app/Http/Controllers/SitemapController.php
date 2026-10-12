<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\Response;

/**
 * XML sitemap covering every publicly reachable URL.
 *
 * Post entries expose `lastmod` from `updated_at` so crawlers re-fetch posts
 * that were corrected rather than only those newly published.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $posts = Post::query()
            ->published()
            ->recent()
            ->select(['id', 'slug', 'updated_at', 'published_at'])
            ->get();

        $categories = Category::query()
            ->ordered()
            ->whereHas('publishedPosts')
            ->get();

        $tags = Tag::query()
            ->popular()
            ->whereHas('posts', fn ($query) => $query->published())
            ->get();

        return response()
            ->view('sitemap', [
                'posts' => $posts,
                'categories' => $categories,
                'tags' => $tags,
            ])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
