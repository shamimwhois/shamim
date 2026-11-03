<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's star on one commit.
 *
 * The row is the state: there is no `starred` flag anywhere else, so a commit's
 * count and a reader's own stars can never disagree. `feed_post_id` +
 * `user_id` is unique in the schema, which is what makes toggling idempotent
 * under a double click.
 *
 * @property int $id
 * @property int $feed_post_id
 * @property int $user_id
 */
#[Fillable(['feed_post_id', 'user_id'])]
class FeedStar extends Model
{
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
