<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One push into a remote.
 *
 * @property int $id
 * @property int $remote_id
 * @property int $user_id
 * @property string $body
 * @property int|null $reply_to_id
 * @property Carbon|null $pinned_at
 * @property Carbon|null $created_at
 * @property-read User $user
 * @property-read RemoteMessage|null $replyTo
 * @property-read Collection<int, RemoteMessageReaction> $reactions
 */
#[Fillable(['remote_id', 'user_id', 'body', 'reply_to_id'])]
class RemoteMessage extends Model
{
    /** @return BelongsTo<Remote, $this> */
    public function remote(): BelongsTo
    {
        return $this->belongsTo(Remote::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The push this one answers.
     *
     * A quote is a pointer, not a copy: the original keeps its own text, so a
     * quoted line cannot drift from what was actually said.
     *
     * @return BelongsTo<RemoteMessage, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /** @return HasMany<RemoteMessage, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_id');
    }

    /** @return HasMany<RemoteMessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(RemoteMessageReaction::class);
    }

    /**
     * `pinned_at` has to be a date, not the string the driver hands back: the
     * strip sorts by it and the pin's `isPinned()` reads it as a moment.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
        ];
    }

    /**
     * The pushes someone pinned, newest pin first.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function pinned(Builder $query): void
    {
        $query->whereNotNull('pinned_at')->latest('pinned_at');
    }

    /**
     * The pushes in a conversation the viewer is part of.
     *
     * Search has to start here, not at the body: a remote is private, so the
     * membership check is the only thing standing between a term and somebody
     * else's messages. It is a scope rather than a `where` in the caller so the
     * guard cannot be forgotten at a second call site.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $viewer): void
    {
        $query->whereHas(
            'remote.participants',
            fn (Builder $query) => $query->where('users.id', $viewer->id),
        );
    }

    /**
     * Scope to pushes whose body matches a free-text term.
     *
     * Wildcards in the term are escaped so they match literally, the same rule
     * every other surface's `search` scope follows.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $query->where('body', 'like', '%'.$this->escapeLikeValue($term).'%');
    }

    /**
     * Escape the wildcards a reader could type into a `like` pattern.
     *
     * `%` and `_` are operators in SQL, so a term containing them would match
     * more than it says. The backslash is escaped first, or it would escape the
     * escapes added after it.
     */
    public function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /** Whether this push is currently pinned. */
    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }
}
