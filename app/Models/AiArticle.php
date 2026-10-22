<?php

namespace App\Models;

use App\Concerns\HasReadingTime;
use App\Concerns\HasSlug;
use App\Enums\AiArticleFormat;
use App\Enums\AiDifficulty;
use App\Enums\AiPublicationStatus;
use Database\Factories\AiArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single piece of teaching content in the AI Knowledge Hub.
 *
 * The article is the hub's centre of gravity: learning paths are ordered lists
 * of these, topics are groupings of these, and the resources shelf is the
 * external reading that accompanies them.
 *
 * Three body columns are stored rather than derived at read time. `body` is the
 * markdown the author edits; `body_html` is the sanitised projection the public
 * page renders; `body_text` is the plain projection the search scope reads. All
 * three are written together by SaveAiArticle, which is what lets the search
 * index stay honest without a full-text engine.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property string $body
 * @property string|null $body_html
 * @property string|null $body_text
 * @property AiArticleFormat $format
 * @property AiDifficulty $difficulty
 * @property AiPublicationStatus $status
 * @property bool $is_featured
 * @property int $reading_time
 * @property int $word_count
 * @property int $view_count
 * @property string|null $cover_image
 * @property string|null $prerequisites
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, AiTopic> $topics
 * @property-read Collection<int, AiArticleLink> $links
 * @property-read Collection<int, AiArticle> $relatedArticles
 * @property-read Collection<int, AiLearningPathItem> $learningPathItems
 */
#[Fillable([
    'user_id',
    'title',
    'slug',
    'summary',
    'body',
    'format',
    'difficulty',
    'status',
    'is_featured',
    'cover_image',
    'prerequisites',
    'meta_title',
    'meta_description',
    'published_at',
])]
class AiArticle extends Model
{
    /** @use HasFactory<AiArticleFactory> */
    use HasFactory, HasReadingTime, HasSlug;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => AiArticleFormat::class,
            'difficulty' => AiDifficulty::class,
            'status' => AiPublicationStatus::class,
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
     * Get the article's author.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the topics the article is filed under, operator order first.
     *
     * The first entry is treated as the primary topic for breadcrumbs, which is
     * why the pivot carries its own sort order rather than relying on the topic
     * rows' global ordering.
     *
     * @return BelongsToMany<AiTopic, $this>
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(AiTopic::class, 'ai_article_topics')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * Get the external references attached to the article.
     *
     * @return HasMany<AiArticleLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(AiArticleLink::class)->orderBy('sort_order');
    }

    /**
     * Get the curated related articles, in the order the author set.
     *
     * @return BelongsToMany<AiArticle, $this>
     */
    public function relatedArticles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'ai_article_related', 'ai_article_id', 'related_article_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * Get the learning-path slots this article occupies.
     *
     * @return HasMany<AiLearningPathItem, $this>
     */
    public function learningPathItems(): HasMany
    {
        return $this->hasMany(AiLearningPathItem::class);
    }

    /**
     * Scope to articles visible on public surfaces.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', AiPublicationStatus::Published)
            ->whereNotNull('published_at');
    }

    /**
     * Scope to articles waiting for work or review.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function draft(Builder $query): void
    {
        $query->where('status', AiPublicationStatus::Draft);
    }

    /**
     * Scope to articles flagged as featured.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function featured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    /**
     * Scope to articles ordered by publish date, newest first.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function recent(Builder $query): void
    {
        $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * Scope to articles ordered for reading: shortest first, then newest.
     *
     * A hub index is a place people choose what to learn next, and an eight
     * minute note is a cheaper next step than an hour-long architecture piece.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function readableOrder(Builder $query): void
    {
        $query->orderBy('reading_time')->orderByDesc('published_at');
    }

    /**
     * Scope to articles matching a free-text term.
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
                ->orWhere('summary', 'like', $pattern)
                ->orWhere('body_text', 'like', $pattern);
        });
    }

    /**
     * Scope to articles filed under the given topic slug.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inTopic(Builder $query, string $topicSlug): void
    {
        $query->whereHas('topics', fn (Builder $query) => $query->where('slug', $topicSlug));
    }

    /**
     * Scope to articles of the given difficulty.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function atDifficulty(Builder $query, AiDifficulty $difficulty): void
    {
        $query->where('difficulty', $difficulty);
    }

    /**
     * Scope to articles of the given format.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inFormat(Builder $query, AiArticleFormat $format): void
    {
        $query->where('format', $format);
    }

    /**
     * Scope to articles authored by the given user.
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
     * Determine whether the article is publicly readable.
     */
    public function isPublished(): bool
    {
        return $this->status === AiPublicationStatus::Published && $this->published_at !== null;
    }

    /**
     * Determine whether the article is visible to the given user.
     *
     * The hub is written by whoever holds the manage permission, so "its
     * author" is the only non-public escape hatch: a co-author does not get to
     * read someone else's draft.
     */
    public function isVisibleTo(?User $user): bool
    {
        return $this->isPublished() || ($user !== null && $this->user_id === $user->id);
    }

    /**
     * Get the primary topic, or null when the article is unfiled.
     */
    public function primaryTopic(): ?AiTopic
    {
        return $this->topics->first();
    }

    /**
     * Get the reading-time label, e.g. "6 min read".
     */
    public function readingTimeLabel(): string
    {
        return $this->reading_time.' min read';
    }

    /**
     * Get the one-line description for cards and meta tags.
     *
     * Falls back to the opening of the plain-text body so a card never renders
     * an empty teaser, which is the common failure of a manually written summary
     * column.
     */
    public function excerpt(int $limit = 200): string
    {
        if (filled($this->summary)) {
            return $this->summary;
        }

        return str($this->body_text ?? '')->limit($limit)->value();
    }
}
