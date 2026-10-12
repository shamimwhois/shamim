<?php

namespace App\Models;

use App\Concerns\HasReadingTime;
use App\Concerns\HasSlug;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $category_id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body
 * @property string|null $body_html
 * @property string|null $body_text
 * @property PostStatus $status
 * @property bool $is_featured
 * @property int $reading_time
 * @property int $word_count
 * @property int $view_count
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Category|null $category
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, Comment> $comments
 */
#[Fillable([
    'user_id',
    'category_id',
    'title',
    'slug',
    'excerpt',
    'body',
    'status',
    'is_featured',
    'published_at',
])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, HasReadingTime, HasSlug, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'is_featured' => 'boolean',
            'reading_time' => 'integer',
            'word_count' => 'integer',
            'view_count' => 'integer',
            'published_at' => 'datetime',
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
     * Resolve a post for route binding.
     *
     * Posts bind by slug on the public site, which is the right identity for a
     * reader-facing URL — stable, human-readable and shareable. The dashboard is
     * a different audience with a different constraint: the slug is editable
     * from the very editor the dashboard links into, so a slug-keyed URL would
     * break every in-flight link the moment the author touched the slug field.
     *
     * An explicit `{post:id}` in the route definition therefore wins and binds
     * on the primary key. Without one the normal slug binding applies.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query->where($field ?? $this->getRouteKeyName(), $value);
    }

    /**
     * Get the post's author.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the post's primary category.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the tags applied to the post.
     *
     * @return BelongsToMany<Tag, $this, PostTag, 'pivot'>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->using(PostTag::class);
    }

    /**
     * Get every comment on the post, including unmoderated ones.
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get the publicly visible comments for the post, oldest first.
     *
     * @return HasMany<Comment, $this>
     */
    public function approvedComments(): HasMany
    {
        return $this->comments()
            ->where('status', CommentStatus::Approved)
            ->oldest();
    }

    /**
     * Scope to posts visible on public surfaces.
     *
     * The single definition of "publicly readable": published status and a
     * publish timestamp. Soft-deleted rows are excluded by Eloquent by default.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', PostStatus::Published)
            ->whereNotNull('published_at');
    }

    /**
     * Scope to draft posts.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function draft(Builder $query): void
    {
        $query->where('status', PostStatus::Draft);
    }

    /**
     * Scope to posts flagged as featured.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function featured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    /**
     * Scope to posts ordered by publish date, newest first.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function recent(Builder $query): void
    {
        $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * Scope to drafts together with any soft-deleted row holding the same slug.
     *
     * A trashed post keeps its row — and therefore its slug, which is covered by
     * a UNIQUE index — so the normal "is this slug still free?" check would miss
     * it and let a new draft collide with the tombstone. Deliberately not a
     * catch-all `withTrashed()`: widening `draft()` would silently change the
     * meaning of every count and listing in the dashboard. This scope is only
     * ever composed onto a single-row slug check.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function draftOrTrashedWithSlug(Builder $query, string $slug): void
    {
        $query->withTrashed()->where(function (Builder $query): void {
            $query->where('status', PostStatus::Draft->value)
                ->orWhereNotNull('deleted_at');
        })->where('slug', $slug);
    }

    /**
     * Scope to posts matching a free-text term.
     *
     * Wildcards in the term are escaped so they match literally.
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

        $pattern = '%'.$this->escapeLikeValue($term).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            $query->where('title', 'like', $pattern)
                ->orWhere('excerpt', 'like', $pattern)
                ->orWhere('body_text', 'like', $pattern);
        });
    }

    /**
     * Scope to posts in the given category slug.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inCategory(Builder $query, string $categorySlug): void
    {
        $query->whereHas('category', fn (Builder $query) => $query->where('slug', $categorySlug));
    }

    /**
     * Scope to posts carrying the given tag slug.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function withTag(Builder $query, string $tagSlug): void
    {
        $query->whereHas('tags', fn (Builder $query) => $query->where('slug', $tagSlug));
    }

    /**
     * Scope to posts authored by the given user.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function authoredBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Escape LIKE wildcards in a user-supplied value.
     */
    public function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Determine whether the post is publicly readable.
     */
    public function isPublished(): bool
    {
        return $this->status === PostStatus::Published && $this->published_at !== null;
    }

    /**
     * Determine whether the post is a draft.
     */
    public function isDraft(): bool
    {
        return $this->status === PostStatus::Draft;
    }

    /**
     * Determine whether the post is visible to the given user.
     */
    public function isVisibleTo(?User $user): bool
    {
        return $this->isPublished() || ($user !== null && $this->user_id === $user->id);
    }

    /**
     * Get the reading-time label, e.g. "6 min read".
     */
    public function readingTimeLabel(): string
    {
        return $this->reading_time.' min read';
    }
}
