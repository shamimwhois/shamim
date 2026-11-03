<?php

namespace App\Actions\Feed;

use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Take a post away from the community.
 *
 * This flips the `deleted` column and leaves the row in place. That is not
 * conservatism — `deleted` is already this application's single definition of
 * "moderation removed this", it is what the admin console reads and writes, and
 * it is excluded by both `visible()` and `isVisibleTo()`. Destroying the row
 * instead would create a second kind of gone: one that the console cannot see,
 * that orphans the attachment rows, and that makes a moderation decision
 * unrecoverable. Setting one column hides the post everywhere it is rendered,
 * which is the whole requirement.
 *
 * Because it is a column rather than a row, deleting is idempotent: a second
 * delete on an already-removed post reports the same state instead of raising an
 * error over something that is already true.
 */
class DeleteFeedPost
{
    /**
     * Mark the post deleted and return it.
     */
    public function handle(User $editor, FeedPost $post): FeedPost
    {
        Gate::forUser($editor)->authorize('delete', $post);

        if ($post->deleted) {
            return $post;
        }

        // `forceFill` because `deleted` is a moderation flag, not composer
        // content: an author deleting their own post has not edited its body, and
        // routing this through SaveFeedDraft would re-derive tags and reading time
        // for a row that is about to stop being rendered.
        $post->forceFill(['deleted' => true])->save();

        return $post;
    }
}
