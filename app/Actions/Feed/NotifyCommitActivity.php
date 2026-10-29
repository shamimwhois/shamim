<?php

namespace App\Actions\Feed;

use App\Models\FeedPost;
use App\Models\FeedReply;
use App\Models\LogNotification;
use App\Models\User;

/**
 * Tell the author that something happened to their commit.
 *
 * The writer, not the reader: the inbox page composes sentences, this decides
 * what deserves a row. Two rules keep it from becoming noise:
 *
 * - Never notify someone about their own action.
 * - A star is one row per (actor, commit), so unstarring and starring again
 *   moves the row rather than stacking a second one. A reply is always a new
 *   row, because each reply is its own event.
 */
class NotifyCommitActivity
{
    /** Someone starred a commit. */
    public function starred(FeedPost $post, User $actor): void
    {
        if ($post->user_id === null || $post->user_id === $actor->id) {
            return;
        }

        LogNotification::query()->updateOrCreate(
            [
                'user_id' => $post->user_id,
                'actor_id' => $actor->id,
                'type' => LogNotification::Starred,
                'feed_post_id' => $post->id,
            ],
            ['read_at' => null, 'created_at' => now()],
        );
    }

    /** Someone replied to a commit. */
    public function replied(FeedPost $post, FeedReply $reply, User $actor): void
    {
        if ($post->user_id === null || $post->user_id === $actor->id) {
            return;
        }

        LogNotification::query()->create([
            'user_id' => $post->user_id,
            'actor_id' => $actor->id,
            'type' => LogNotification::Replied,
            'feed_post_id' => $post->id,
            'feed_reply_id' => $reply->id,
        ]);
    }
}
