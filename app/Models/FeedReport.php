<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's report on one commit.
 *
 * The row is the state: there is no "reported" flag anywhere else, so a
 * commit's report count and a reader's own reports can never disagree.
 * `feed_post_id` + `user_id` is unique in the schema, which is what makes
 * reporting idempotent under a double click.
 *
 * The reason is part of the record, not a detail thrown away on the way in:
 * a moderator triaging a queue needs to know whether it is spam or
 * harassment, and "reported" on its own is not a triage.
 *
 * @property int $id
 * @property int $feed_post_id
 * @property int $user_id
 * @property string $reason
 */
#[Fillable(['feed_post_id', 'user_id', 'reason'])]
class FeedReport extends Model
{
    /** The reasons a commit can be reported for. */
    public const REASONS = [
        'spam' => 'Spam or misleading links',
        'harassment' => 'Harassment or hate',
        'unsafe' => 'Harmful or private information',
        'other' => 'Something else',
    ];

    /**
     * The reasons a commit can be reported for, as a plain list.
     *
     * @return array<int, string>
     */
    public static function reasons(): array
    {
        return array_keys(self::REASONS);
    }

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
