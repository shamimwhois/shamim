<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's share of one commit.
 *
 * The row is the state: there is no `shares` column anywhere else, so a
 * commit's share count and a reader's own share can never disagree. The unique
 * index on `feed_post_id` + `user_id` is what makes the action idempotent — a
 * double click, or sharing by two different routes, is the same row.
 *
 * @property int $id
 * @property int $feed_post_id
 * @property int $user_id
 * @property string $channel
 */
#[Fillable(['feed_post_id', 'user_id', 'channel'])]
class FeedShare extends Model
{
    /** The clipboard-copy route. */
    public const CHANNEL_LINK = 'link';

    /** The OS share sheet. */
    public const CHANNEL_SHEET = 'sheet';

    /** @return BelongsTo<FeedPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedPost::class, 'feed_post_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
