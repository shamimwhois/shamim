<?php

namespace App\Observers;

use App\Actions\Posts\SyncTaxonomyCounts;
use App\Http\Controllers\FeedController;
use App\Models\Post;

/**
 * Keeps the denormalised taxonomy counters honest.
 *
 * The publish, unpublish, archive and delete actions already reconcile counters
 * through SyncTaxonomyCounts. Those actions own the status transitions, but they
 * are not the only way a post row can change: an import, a console command, a
 * factory in a test, or a future code path can all write a post directly. Since
 * `posts_count` is rendered in public navigation, a stale figure is a visible
 * defect rather than an internal inconsistency.
 *
 * Reconcile on save and delete so the invariant holds regardless of entry point.
 * Counts are recomputed, never incremented, so a redundant reconciliation is
 * harmless.
 */
class PostObserver
{
    public function __construct(protected SyncTaxonomyCounts $syncCounts) {}

    /**
     * Reconcile counters and drop the cached feed after a create or update.
     *
     * `getOriginal()` still holds the pre-save category inside `saved`, which is
     * what lets a post move between categories without leaving the old one's
     * counter behind. The feed is cached for an hour, so publishing a post has
     * to evict it or the post is missing from the feed until the cache expires.
     */
    public function saved(Post $post): void
    {
        $this->syncCounts->handle($post, $post->getOriginal('category_id'));
        $this->forgetFeed();
    }

    /**
     * Reconcile counters after a post is soft-deleted.
     */
    public function deleted(Post $post): void
    {
        $this->syncCounts->handle($post);
        $this->forgetFeed();
    }

    /**
     * Reconcile counters after a post is restored.
     */
    public function restored(Post $post): void
    {
        $this->syncCounts->handle($post);
        $this->forgetFeed();
    }

    /**
     * Evict the rendered RSS feed so the next request rebuilds it.
     */
    protected function forgetFeed(): void
    {
        FeedController::forget();
    }
}
