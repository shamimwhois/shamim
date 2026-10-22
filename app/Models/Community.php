<?php

namespace App\Models;

use App\Enums\CommunityKind;
use App\Enums\CommunityRole;
use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A topic-based community — the directory entry that groups feed posts.
 *
 * A community owns a single {@see $topic}: every visible feed post carrying
 * that topic renders on the community page. Membership is the pivot in
 * {@see CommunityMembership}; member counts are derived with `withCount`, so
 * a counter column can never drift from the rows it summarises.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $topic
 * @property string $glyph
 * @property CommunityKind $kind
 * @property string|null $rules
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'slug',
    'name',
    'description',
    'topic',
    'glyph',
    'kind',
    'rules',
])]
class Community extends Model
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CommunityKind::class,
        ];
    }

    /**
     * Get the attribute used for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The join rows themselves, when the role on the pivot is what matters.
     *
     * @return HasMany<CommunityMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CommunityMembership::class);
    }

    /**
     * The visible feed posts that belong to this community's topic.
     *
     * @return HasMany<FeedPost, $this>
     */
    public function feedPosts(): HasMany
    {
        return $this->hasMany(FeedPost::class, 'topic', 'topic')->visible();
    }

    /**
     * Scope the directory to communities matching a term in name or copy.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function matching(Builder $query, string $term): void
    {
        $query->where(function (Builder $inner) use ($term): void {
            $word = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $inner->where('name', 'like', $word)->orWhere('description', 'like', $word);
        });
    }

    /**
     * Determine whether the given account holds a membership here.
     */
    public function isMember(?User $user): bool
    {
        return $user !== null
            && $this->memberships()->where('user_id', $user->id)->exists();
    }

    /**
     * The membership row for the given account, if any.
     */
    public function membershipOf(?User $user): ?CommunityMembership
    {
        if ($user === null) {
            return null;
        }

        /** @var CommunityMembership|null $membership */
        $membership = $this->memberships()->where('user_id', $user->id)->first();

        return $membership;
    }

    /**
     * The owner's membership row, when the community has one.
     */
    public function ownerMembership(): ?CommunityMembership
    {
        /** @var CommunityMembership|null $owner */
        $owner = $this->memberships()->where('role', CommunityRole::Owner->value)->first();

        return $owner;
    }
}
