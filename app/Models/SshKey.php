<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A key that can push to an account.
 *
 * Only the name and the fingerprint are stored: the private half never reaches
 * the server, which is what makes the list safe to print in full.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $fingerprint
 * @property Carbon|null $last_used_at
 */
#[Fillable(['user_id', 'name', 'fingerprint', 'last_used_at'])]
class SshKey extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
