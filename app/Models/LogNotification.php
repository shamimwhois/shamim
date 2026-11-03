<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing that happened to an account.
 *
 * The inbox is a log, so a notification is a row with a type, an actor and the
 * commit it is about — never a rendered sentence. The page turns the type into
 * prose, which is what lets the same row read correctly in a filter, a group,
 * and an accessible summary.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $actor_id
 * @property string $type
 * @property int|null $feed_post_id
 * @property int|null $feed_reply_id
 * @property Carbon|null $read_at
 * @property-read User|null $actor
 * @property-read FeedPost|null $post
 * @property-read FeedReply|null $reply
 */
#[Fillable(['user_id', 'actor_id', 'type', 'feed_post_id', 'feed_reply_id', 'read_at'])]
class LogNotification extends Model
{
    /** Someone starred a commit of yours. */
    public const Starred = 'starred';

    /** Someone replied to a commit of yours. */
    public const Replied = 'replied';

    /** Someone mentioned you in a commit. */
    public const Mentioned = 'mentioned';

    /** Someone followed you. */
    public const Followed = 'followed';

    /** A moderator approved or rejected a commit of yours. */
    public const Moderated = 'moderated';

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<FeedPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedPost::class, 'feed_post_id');
    }

    /** @return BelongsTo<FeedReply, $this> */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(FeedReply::class, 'feed_reply_id');
    }

    /**
     * Only the notifications that still point at something readable.
     *
     * A commit can be deleted or moderated away after the fact; a row that
     * quotes it would then link to a 404, so the inbox reads through the
     * commit's own visibility rather than trusting the row.
     *
     * @param  Builder<LogNotification>  $query
     */
    #[Scope]
    protected function deliverable(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('feed_post_id')
                ->orWhereHas('post', fn (Builder $post) => $post->visible());
        });
    }

    /** Whether this row is still unread. */
    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
