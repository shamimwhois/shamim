<?php

namespace App\Observers;

use App\Actions\Posts\SyncTaxonomyCounts;
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
     * Reconcile counters after a post is created or updated.
     */
    public function saved(Post $post): void
    {
        $this->syncCounts->handle($post);
    }

    /**
     * Reconcile counters after a post is soft-deleted.
     */
    public function deleted(Post $post): void
    {
        $this->syncCounts->handle($post);
    }

    /**
     * Reconcile counters after a post is restored.
     */
    public function restored(Post $post): void
    {
        $this->syncCounts->handle($post);
    }
}
