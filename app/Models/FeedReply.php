<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reply under a commit.
 *
 * The thread is flat by design: a reply belongs to the commit, never to another
 * reply, so the log can draw one guide line and stop. `user_id` is the author;
 * the commit's own byline is denormalised, but a reply is always read with its
 * author eager-loaded, because a thread is short and identity must be exact.
 *
 * @property int $id
 * @property int $feed_post_id
 * @property int $user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property-read FeedPost $post
 * @property-read User $user
 */
#[Fillable(['feed_post_id', 'user_id', 'body'])]
class FeedReply extends Model
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
