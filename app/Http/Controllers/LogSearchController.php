<?php

namespace App\Http\Controllers;

use App\Models\FeedPost;
use App\Support\LogIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The palette's only endpoint: developers, by name or handle.
 *
 * Search is a "who", not a "what" — the log answers what was shipped, this
 * answers who shipped it. One row per author, busiest first, which is the same
 * ordering the aside's suggestions use, so the two never disagree.
 *
 * It is public because every profile it can return is public: the rows are
 * derived from visible commits, so a handle that only appears in a private
 * commit is not discoverable here either.
 */
class LogSearchController extends Controller
{
    /**
     * Handle the request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $query = mb_strtolower(trim((string) $request->query('q', '')));

        $authors = FeedPost::query()
            ->visible()
            ->selectRaw('handle, author, avatar, count(*) as commits')
            ->groupBy('handle', 'author', 'avatar')
            ->orderByDesc('commits')
            ->get()
            ->map(fn (FeedPost $post): array => [
                'name' => $post->author,
                'handle' => LogIdentity::handle($post->handle),
                'initials' => $post->avatar,
                'lane' => LogIdentity::lane($post->handle),
                'commits' => (int) $post->getAttribute('commits'),
            ])
            ->filter(fn (array $author): bool => $query === ''
                || str_contains(mb_strtolower($author['name'].' '.$author['handle']), $query))
            ->values();

        return response()->json($authors);
    }
}
