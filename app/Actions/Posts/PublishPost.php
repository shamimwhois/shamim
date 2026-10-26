<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * Makes a post publicly readable.
 *
 * This is one of only two writers of `published_at`, and the sole place where
 * the status/timestamp invariant for publishing is enforced.
 */
class PublishPost
{
    public function __construct(protected SyncTaxonomyCounts $syncCounts) {}

    /**
     * Publish the post, if it is not already published.
     */
    public function handle(Post $post): Post
    {
        if ($post->status === PostStatus::Published && $post->published_at !== null) {
            return $post;
        }

        return DB::transaction(function () use ($post): Post {
            $post->forceFill([
                'status' => PostStatus::Published,
                'published_at' => $post->published_at ?? now(),
            ])->save();

            $this->syncCounts->handle($post);

            return $post->refresh();
        });
    }

    /**
     * Determine whether the post is in a state that can be published.
     *
     * A post needs a title and a body, and nothing else. Taxonomy is optional
     * everywhere else in the app (the editor lets a post be saved without a
     * category or tag), so requiring a tag here would block publishing from the
     * posts table while the editor published the same post without complaint.
     */
    public function canPublish(Post $post): bool
    {
        return trim($post->title) !== '' && trim($post->body) !== '';
    }
}
