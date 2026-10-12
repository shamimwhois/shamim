<?php

namespace App\Actions\Posts;

use App\Models\Post;

/**
 * Soft-deletes a post.
 *
 * The row is retained so a published URL that is linked externally can be
 * restored. Public queries exclude trashed rows by Eloquent's default.
 */
class DeletePost
{
    public function __construct(protected SyncTaxonomyCounts $syncCounts) {}

    /**
     * Soft-delete the post and reconcile its taxonomy counters.
     */
    public function handle(Post $post): void
    {
        $this->syncCounts->handle($post);

        $post->delete();
    }

    /**
     * Restore a soft-deleted post.
     */
    public function restore(Post $post): Post
    {
        $post->restore();

        $this->syncCounts->handle($post);

        return $post->refresh();
    }
}
