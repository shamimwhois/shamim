<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's vote in one poll.
 *
 * The row is the vote. The poll's own tally stays where the corpus put it, as
 * an immutable baseline, and the number a reader sees is the baseline plus the
 * rows behind this table — so the count can never drift from the votes.
 *
 * The option is stored by its label rather than by an index: the poll lives in
 * a JSON column with no option table of its own, and a label is what the
 * reader chose. `feed_post_id` + `user_id` is unique, which is what makes
 * voting once per poll true under a double click.
 *
 * @property int $id
 * @property int $feed_post_id
 * @property int $user_id
 * @property string $option
 */
#[Fillable(['feed_post_id', 'user_id', 'option'])]
class FeedPollVote extends Model
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
