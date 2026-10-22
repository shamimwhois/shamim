<?php

namespace App\Models;

use App\Concerns\HasAccountRole;
use App\Enums\AccountRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
