<?php

namespace App\Models;

use App\Actions\AiHub\SyncAiPublicationStatus;
use App\Concerns\HasSlug;
use App\Enums\AiDifficulty;
use App\Enums\AiPublicationStatus;
use Database\Factories\AiLearningPathFactory;
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
 * An ordered route through a subject.
 *
 * A path is a promise about *sequence*: the lessons are meant to be read in the
 * order stored in `ai_learning_path_items.sort_order`, and the public page says
 * which number each lesson is. That is the whole difference between a path and
 * a topic listing, which is unordered by design.
 *
 * Paths publish independently of their lessons. A path may only be published
 * once every lesson in it is published — the check lives in
 * {@see SyncAiPublicationStatus} rather than in the
 * model, because it is a workflow rule about a transition, not a fact about a
 * row.
 *
 * @property int $id
 * @property int|null $ai_topic_id
 * @property int|null $user_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property AiDifficulty $difficulty
 * @property AiPublicationStatus $status
 * @property bool $is_featured
 * @property string|null $prerequisites
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiTopic|null $topic
 * @property-read User|null $user
 * @property-read Collection<int, AiLearningPathItem> $items
 * @property-read Collection<int, AiArticle> $articles
 */
#[Fillable([
    'ai_topic_id',
    'user_id',
    'title',
    'slug',
    'description',
    'difficulty',
    'status',
    'is_featured',
    'prerequisites',
    'meta_title',
    'meta_description',
    'published_at',
])]
class AiLearningPath extends Model
{
    /** @use HasFactory<AiLearningPathFactory> */
    use HasFactory, HasSlug;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'difficulty' => AiDifficulty::class,
            'status' => AiPublicationStatus::class,
            'is_featured' => 'boolean',
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
     * Get the topic the path belongs to.
     *
     * @return BelongsTo<AiTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(AiTopic::class, 'ai_topic_id');
    }

    /**
     * Get the operator who assembled the path.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the ordered lesson slots.
     *
     * @return HasMany<AiLearningPathItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AiLearningPathItem::class)->orderBy('sort_order');
    }

    /**
     * Get the lessons as articles, in path order.
     *
     * @return BelongsToMany<AiArticle, $this>
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(AiArticle::class, 'ai_learning_path_items')
            ->withPivot('sort_order', 'title', 'note')
            ->orderByPivot('sort_order');
    }

    /**
     * Scope to paths visible on public surfaces.
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
     * Scope to featured paths, for the landing spotlight.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function featured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    /**
     * Scope to paths in the order a reader should meet them.
     *
     * Featured first, then newest. Difficulty is deliberately not part of the
     * sort: it is stored as a string, so ordering the column would rank the
     * ladder alphabetically. The public index groups by level in PHP instead.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inReadingOrder(Builder $query): void
    {
        $query->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->orderBy('title');
    }

    /**
     * Scope to paths belonging to the given topic slug.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inTopic(Builder $query, string $topicSlug): void
    {
        $query->whereHas('topic', fn (Builder $query) => $query->where('slug', $topicSlug));
    }

    /**
     * Determine whether the path is publicly readable.
     */
    public function isPublished(): bool
    {
        return $this->status === AiPublicationStatus::Published && $this->published_at !== null;
    }

    /**
     * Count the lessons in the path.
     */
    public function lessonCount(): int
    {
        return $this->items_count ?? $this->items()->count();
    }

    /**
     * Get the total study time in minutes, summed from the lessons.
     *
     * Uses a database aggregate rather than a PHP sum over the loaded relation so
     * the figure is correct even when the lessons have not been fetched, and does
     * not depend on the size of the array being spread.
     */
    public function estimatedMinutes(): int
    {
        return (int) AiArticle::query()
            ->whereIn('id', $this->items()->pluck('ai_article_id'))
            ->sum('reading_time');
    }

    /**
     * Get the study-time label, e.g. "45 min total".
     */
    public function estimatedMinutesLabel(): string
    {
        return $this->estimatedMinutes().' min total';
    }
}
