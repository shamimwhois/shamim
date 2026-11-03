<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account not reaching another: a block, or a mute.
 *
 * The direction is always `user_id` → `blocked_id`. A mute hides someone's
 * commits; a block also hides their replies and remotes. Both are the same row
 * because both answer "does this person reach me?".
 *
 * @property int $id
 * @property int $user_id
 * @property int $blocked_id
 * @property bool $muted
 * @property-read User $blocked
 */
#[Fillable(['user_id', 'blocked_id', 'muted'])]
class UserBlock extends Model
{
    protected function casts(): array
    {
        return [
            'muted' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }
}
