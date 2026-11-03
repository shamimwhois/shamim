<?php

namespace App\Models;

use App\Enums\CommunityRole;
use Database\Factories\CommunityMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A community membership — the join row between an account and a community.
 *
 * The (community_id, user_id) pair is unique in the schema, so duplicates are
 * refused by the database; the toggle action treats the constraint as the
 * race-condition backstop, not the primary rule.
 *
 * @property int $id
 * @property int $community_id
 * @property int $user_id
 * @property CommunityRole $role
 * @property Carbon $joined_at
 * @property-read Community $community
 * @property-read User $user
 */
#[Fillable([
    'community_id',
    'user_id',
    'role',
    'joined_at',
])]
class CommunityMembership extends Model
{
    /** @use HasFactory<CommunityMembershipFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CommunityRole::class,
            'joined_at' => 'datetime',
        ];
    }

    /**
     * The pivot table owns only `joined_at`, so Laravel's `created_at` /
     * `updated_at` maintenance has no columns to write into and is off
     * entirely. Every caller that matters passes `joined_at` explicitly;
     * the table's `useCurrent()` default covers the rest.
     */
    public $timestamps = false;

    /**
     * Get the community the membership belongs to.
     *
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * Get the member account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to memberships of a given role.
     *
     * @param  Builder<self>  $query
     */
    protected function ofRole(Builder $query, CommunityRole $role): void
    {
        $query->where('role', $role->value);
    }
}
