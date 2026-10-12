<?php

namespace App\Models;

use App\Concerns\HasSlug;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $posts_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Post> $posts
 */
#[Fillable(['name', 'slug'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory, HasSlug;

    /**
     * Get the attribute used for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the posts carrying this tag.
     *
     * Uses an explicit pivot model so attach/detach keep `posts_count` honest.
     * Eloquent writes pivot rows directly and bypasses model observers, so
     * without this the counter would drift whenever a tag was applied outside
     * SyncPostTags — including from a seeder or a test.
     *
     * @return BelongsToMany<Post, $this, PostTag, 'pivot'>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class)->using(PostTag::class);
    }

    /**
     * Scope to tags used by at least one published post, ordered by popularity.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePopular(Builder $query): void
    {
        $query->where('posts_count', '>', 0)
            ->orderByDesc('posts_count')
            ->orderBy('name');
    }

    /**
     * Reconcile the denormalised published-post counter for this tag.
     *
     * Recomputed rather than incremented, so a redundant call is harmless and
     * drift self-heals on the next write.
     */
    public function syncPostsCount(): void
    {
        $this->forceFill([
            'posts_count' => $this->posts()->published()->count(),
        ])->save();
    }
}
