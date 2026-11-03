<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's reaction to one push.
 *
 * The row is the state, exactly like `FeedStar`: there is no counter column, so
 * a push's total and a reader's own reaction can never disagree. The schema's
 * unique index on (message, user, emoji) is what makes the toggle idempotent
 * under a double click, and it is also why a person can hold a 👍 and a 🎉 on
 * the same message but never two 👍.
 *
 * @property int $id
 * @property int $remote_message_id
 * @property int $user_id
 * @property string $emoji
 * @property-read User $user
 */
#[Fillable(['remote_message_id', 'user_id', 'emoji'])]
class RemoteMessageReaction extends Model
{
    /** @return BelongsTo<RemoteMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(RemoteMessage::class, 'remote_message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
