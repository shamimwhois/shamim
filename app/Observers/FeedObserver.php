<?php

namespace App\Observers;

use App\Http\Controllers\FeedController;
use Illuminate\Database\Eloquent\Model;

/**
 * Evicts the cached RSS feed when a row the feed renders changes.
 *
 * The feed embeds each post's title and body, but also the post's category
 * name, its tag names and its author's name. Those live in other tables, so
 * renaming a category or fixing a typo in an author's name would otherwise
 * keep the old label in every reader for up to an hour while the site itself
 * showed the corrected one.
 *
 * Post writes are handled by PostObserver, which evicts the feed alongside
 * reconciling the taxonomy counters.
 */
class FeedObserver
{
    /**
     * Emit the feed again on the next request after a create or update.
     */
    public function saved(Model $model): void
    {
        FeedController::forget();
    }

    /**
     * Emit the feed again on the next request after a delete.
     */
    public function deleted(Model $model): void
    {
        FeedController::forget();
    }
}
