<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A conversation — direct or a group.
 *
 * `kind` only changes how the header reads (a name, or the other person's), not
 * who may see it: membership is the participants table in both cases.
 *
 * @property int $id
 * @property string $kind
 * @property string|null $title
 * @property int|null $created_by
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $participants
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RemoteMessage> $messages
 */
#[Fillable(['kind', 'title', 'created_by'])]
class Remote extends Model
{
    /** @return BelongsToMany<User, $this> */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'remote_participants')
            ->withPivot(['last_read_at', 'muted_until_at', 'pinned_at', 'archived_at'])
            ->withTimestamps();
    }

    /** @return HasMany<RemoteMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(RemoteMessage::class)->oldest();
    }

    /** @return HasOne<RemoteMessage, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(RemoteMessage::class)->latestOfMany();
    }

    /**
     * The other people in the conversation, for a direct remote's header.
     *
     * @return Collection<int, User>
     */
    public function others(User $viewer): Collection
    {
        return $this->participants->reject(fn (User $user): bool => $user->id === $viewer->id)->values();
    }

    /** The conversation's name as this viewer reads it. */
    public function displayTitle(User $viewer): string
    {
        if ($this->kind === 'group' && filled($this->title)) {
            return (string) $this->title;
        }

        return $this->others($viewer)
            ->map(fn (User $user): string => $user->name)
            ->implode(', ') ?: __('Just you');
    }

    /**
     * When this viewer last pulled.
     *
     * The pivot is read through the relation's own accessor rather than the
     * model's magic `$pivot` property, which only exists on rows loaded through
     * a `belongsToMany` query.
     */
    public function readAtFor(User $viewer): ?Carbon
    {
        return $this->pivotMomentFor($viewer, 'last_read_at');
    }

    /**
     * Whether this viewer silenced the conversation.
     *
     * Muting is per reader, not per remote: it stops the nav badge counting
     * pushes here without hiding them from anyone else in the thread. A mute
     * carries an end, so "for one hour" releases itself; forever is the far end
     * of the calendar rather than a flag somebody has to remember to clear.
     */
    public function mutedFor(User $viewer): bool
    {
        $until = $this->pivotMomentFor($viewer, 'muted_until_at');

        return $until !== null && $until->isFuture();
    }

    /** Whether this viewer pinned the conversation to the top of their list. */
    public function pinnedFor(User $viewer): bool
    {
        return $this->pivotMomentFor($viewer, 'pinned_at') !== null;
    }

    /** Whether this viewer filed the conversation away. */
    public function archivedFor(User $viewer): bool
    {
        return $this->pivotMomentFor($viewer, 'archived_at') !== null;
    }

    /** One timestamp off this viewer's participant row. */
    private function pivotMomentFor(User $viewer, string $column): ?Carbon
    {
        $participant = $this->participants->firstWhere('id', $viewer->id);

        if ($participant === null) {
            return null;
        }

        /** @var mixed $value */
        $value = $participant->getRelationValue('pivot')?->getAttribute($column);

        return $value !== null ? Carbon::parse($value) : null;
    }
}
