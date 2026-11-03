<?php

namespace App\Models;

use App\Concerns\HasSlug;
use App\Support\FluxIcon;
use Database\Factories\AiTopicFactory;
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
 * A subject area in the AI Knowledge Hub.
 *
 * Topics are rows rather than a hardcoded list because the spec is explicit that
 * the initial categories are a starting point: an operator must be able to add,
 * rename, reorder and deactivate them without a deploy. `slug` is the public
 * identity used in `/ai-hub/topics/{topic}`, so renaming a topic keeps its URL
 * unless the slug itself is edited.
 *
 * `parent_id` allows one level of nesting, which is as deep as the navigation is
 * designed to render. A deeper tree would need the presenter to recurse, and
 * that is a deliberate future change rather than an accident of data.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiTopic|null $parent
 * @property-read Collection<int, AiTopic> $children
 * @property-read Collection<int, AiArticle> $articles
 * @property-read Collection<int, AiLearningPath> $learningPaths
 * @property-read Collection<int, AiResource> $resources
 */
#[Fillable(['parent_id', 'name', 'slug', 'description', 'icon', 'sort_order', 'is_active'])]
class AiTopic extends Model
{
    /** @use HasFactory<AiTopicFactory> */
    use HasFactory, HasSlug;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'icon' => FluxIcon::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
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
     * Get the topic one level above, if any.
     *
     * @return BelongsTo<AiTopic, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Get the sub-topics belonging to this topic.
     *
     * @return HasMany<AiTopic, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * Get the articles filed under this topic.
     *
     * @return BelongsToMany<AiArticle, $this>
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(AiArticle::class, 'ai_article_topics')
            ->withPivot('sort_order');
    }

    /**
     * Get the learning paths anchored to this topic.
     *
     * @return HasMany<AiLearningPath, $this>
     */
    public function learningPaths(): HasMany
    {
        return $this->hasMany(AiLearningPath::class);
    }

    /**
     * Get the resources tagged with this topic.
     *
     * @return HasMany<AiResource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(AiResource::class);
    }

    /**
     * Scope to topics operators have not switched off.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to topics in the order the operator set.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Scope to top-level topics only.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function rootLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Determine whether the topic has any active sub-topics.
     */
    public function hasChildren(): bool
    {
        return $this->children()->where('is_active', true)->exists();
    }
}
