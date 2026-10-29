<?php

namespace App\Models;

use App\Concerns\HasAccountRole;
use App\Enums\AccountRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $handle
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property AccountRole $role
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'handle', 'email', 'password', 'role'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasAccountRole, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The attributes that should have default values.
     *
     * Declared here as well as on the migration column so the model is correct
     * before any insert round-trips to the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => AccountRole::User->value,
        'remote_pull_receipts' => true,
        'remote_requests' => true,
        'remotes_enabled' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => AccountRole::class,
            'two_factor_confirmed_at' => 'datetime',
            'remote_pull_receipts' => 'boolean',
            'remote_requests' => 'boolean',
            'remotes_enabled' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Memoised {@see joinedCommunityIds()} result for this instance.
     *
     * @var array<int, int>|null
     */
    private ?array $joinedCommunityIdCache = null;

    /**
     * The ids of the communities this account has joined.
     *
     * The membership set a community-audience post is checked against. Held on
     * the instance because one request asks for it many times — every post in
     * the feed, and the composer's audience picker.
     *
     * @return array<int, int>
     */
    public function joinedCommunityIds(): array
    {
        if ($this->joinedCommunityIdCache !== null) {
            return $this->joinedCommunityIdCache;
        }

        /** @var array<int, int> $ids */
        $ids = CommunityMembership::query()
            ->where('user_id', $this->id)
            ->pluck('community_id')
            ->all();

        return $this->joinedCommunityIdCache = $ids;
    }

    /**
     * The commits this account wrote.
     *
     * @return HasMany<FeedPost, $this>
     */
    public function feedPosts(): HasMany
    {
        return $this->hasMany(FeedPost::class);
    }

    /**
     * The commits this account starred.
     *
     * @return HasMany<FeedStar, $this>
     */
    public function feedStars(): HasMany
    {
        return $this->hasMany(FeedStar::class);
    }

    /**
     * The inbox rows addressed to this account.
     *
     * @return HasMany<LogNotification, $this>
     */
    public function logNotifications(): HasMany
    {
        return $this->hasMany(LogNotification::class);
    }

    /**
     * The conversations this account is part of.
     *
     * @return BelongsToMany<Remote, $this>
     */
    public function remotes(): BelongsToMany
    {
        return $this->belongsToMany(Remote::class, 'remote_participants')
            ->withPivot(['last_read_at', 'muted_until_at', 'pinned_at', 'archived_at'])
            ->withTimestamps();
    }

    /**
     * The keys that may push as this account.
     *
     * @return HasMany<SshKey, $this>
     */
    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class)->latest();
    }

    /**
     * The accounts this one has blocked or muted.
     *
     * @return HasMany<UserBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(UserBlock::class);
    }

    /**
     * Whether this account has blocked the other one.
     *
     * A block is one direction of a pair, so this answers "do I stop them
     * reaching me" — the reverse direction is a different row and a different
     * question. A mute is the same row with `muted` set and is deliberately not
     * counted here: a mute quiets the log, it does not close a remote.
     */
    public function hasBlocked(User $other): bool
    {
        return $this->blocks()
            ->where('blocked_id', $other->id)
            ->where('muted', false)
            ->exists();
    }

    /**
     * The ids of the accounts this one has blocked.
     *
     * The inbox asks this about every row it draws, so it is one query for the
     * whole page rather than one per conversation.
     *
     * @return list<int>
     */
    public function blockedIds(): array
    {
        /** @var list<int> $ids */
        $ids = $this->blocks()
            ->where('muted', false)
            ->pluck('blocked_id')
            ->all();

        return $ids;
    }
}
