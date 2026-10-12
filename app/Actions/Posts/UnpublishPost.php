<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * Returns a post to draft and removes it from every public surface.
 *
 * Clears `published_at` so the status/timestamp invariant holds: a post that is
 * not published must not carry a publish timestamp.
 */
class UnpublishPost
{
    public function __construct(protected SyncTaxonomyCounts $syncCounts) {}

    /**
     * Unpublish the post, if it is currently published.
     */
    public function handle(Post $post): Post
    {
        if ($post->status !== PostStatus::Published) {
            return $post;
        }

        return DB::transaction(function () use ($post): Post {
            $post->forceFill([
                'status' => PostStatus::Draft,
                'published_at' => null,
            ])->save();

            $this->syncCounts->handle($post);

            return $post->refresh();
        });
    }
}
