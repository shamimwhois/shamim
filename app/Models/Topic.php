<?php

namespace App\Models;

use App\Concerns\HasSlug;
use App\Services\PublicTaxonomy;
use App\Support\FluxIcon;
use Database\Factories\TopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A subject a stranger can browse by, shared by Ideas and the Developer Lab.
 *
 * Topics are the public half of a two-layer taxonomy, and the layering is the
 * point. {@see IdeaCategory} is one account's private filing: its names describe how
 * a stranger's notes are organised, which is not information the site has a right to
 * publish. A topic here is platform-owned, seeded by an operator, chosen by the
 * author, and safe to advertise because it says something about the work rather than
 * about the wallet it was filed in.
 *
 * It is also not {@see AiTopic}, which is the hub's own one-level tree with a
 * `parent_id`. One flat list serves two modules because "what this is about" means
 * the same thing to an idea and to a snippet; the hub's hierarchy is a navigation
 * object for a single module, and the two tables are not interchangeable.
 *
 * A row here is invisible until a published idea or snippet carries it. The gate is
 * {@see PublicTaxonomy::topicChips()}, not the `is_active` flag alone,
 * so switching a topic off and having nothing published under it both remove it from
 * the public facet row for the same reason: nothing opens behind the chip.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Idea> $ideas
 * @property-read Collection<int, LabSnippet> $labSnippets
 */
#[Fillable(['name', 'slug', 'description', 'icon', 'sort_order', 'is_active'])]
class Topic extends Model
{
    /**
     * @var int The most topics one idea or snippet may carry. A facet row is
     *          navigation, and a wall of twelve chips is not.
     */
    public const MAX_PER_ROW = 8;

    /** @use HasFactory<TopicFactory> */
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
     * Get the ideas filed under this topic, in the author's chosen order.
     *
     * @return BelongsToMany<Idea, $this>
     */
    public function ideas(): BelongsToMany
    {
        return $this->belongsToMany(Idea::class, 'idea_topic')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * Get the snippets filed under this topic, in the author's chosen order.
     *
     * @return BelongsToMany<LabSnippet, $this>
     */
    public function labSnippets(): BelongsToMany
    {
        return $this->belongsToMany(LabSnippet::class, 'lab_snippet_topic')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
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
     * Turn a submitted list of topic ids into pivot rows, primary first.
     *
     * Both sync actions call this instead of writing the rule twice, because the
     * rule is a property of the taxonomy rather than of the module filing into it:
     * an id that is not an integer, does not exist, or has been switched off by an
     * operator is dropped, and the list is cut at {@see self::MAX_PER_ROW}. Dropping
     * rather than refusing is the house rule from `SyncAiArticleTopics`, and it is
     * right here: a checkbox the browser sent by accident should not fail the save
     * of an idea somebody spent twenty minutes writing.
     *
     * @param  array<int, int|string>  $topicIds
     * @return array<int, array{sort_order: int}>
     */
    public static function syncRows(array $topicIds): array
    {
        $ids = collect($topicIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter()
            ->unique()
            ->take(self::MAX_PER_ROW)
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $valid = static::query()->active()->whereIn('id', $ids)->pluck('id')->all();

        $rows = [];
        $order = 0;

        foreach ($ids as $id) {
            if (! in_array($id, $valid, true)) {
                continue;
            }

            $rows[$id] = ['sort_order' => $order++];
        }

        return $rows;
    }
}
