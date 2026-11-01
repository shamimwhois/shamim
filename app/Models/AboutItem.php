<?php

namespace App\Models;

use App\Enums\AboutItemType;
use App\Support\FluxIcon;
use Database\Factories\AboutItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single content row inside an About section.
 *
 * One table serves every section shape: flat lists (reasons, principles,
 * projects, stats) use {@see AboutItemType::Entry} rows, while the Experience
 * section builds its employer → role → highlight tree through the self-referential
 * `parent_id`. `tags` and `stack` values are stored as comma-delimited text —
 * never JSON — per the project schema convention.
 *
 * @property int $id
 * @property int $about_section_id
 * @property int|null $parent_id
 * @property AboutItemType $type
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $body
 * @property string|null $value
 * @property string|null $tags
 * @property string|null $icon
 * @property string|null $url
 * @property string|null $url_label
 * @property string|null $period
 * @property int $sort_order
 * @property bool $is_visible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AboutSection $section
 * @property-read AboutItem|null $parent
 * @property-read Collection<int, AboutItem> $children
 */
#[Fillable(['about_section_id', 'parent_id', 'type', 'title', 'subtitle', 'body', 'value', 'tags', 'icon', 'url', 'url_label', 'period', 'sort_order', 'is_visible'])]
class AboutItem extends Model
{
    /** @use HasFactory<AboutItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AboutItemType::class,
            'icon' => FluxIcon::class,
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    /**
     * Split the delimited tags column into trimmed values.
     *
     * @return array<int, string>
     */
    public function tagList(): array
    {
        if (blank($this->tags)) {
            return [];
        }

        return collect(explode(',', $this->tags))
            ->map(fn (string $tag): string => trim($tag))
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Get the section this row belongs to.
     *
     * @return BelongsTo<AboutSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(AboutSection::class, 'about_section_id');
    }

    /**
     * Get the row one level above this one in a section tree.
     *
     * @return BelongsTo<AboutItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Get the rows nested directly below this one.
     *
     * @return HasMany<AboutItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Determine whether this row sits anywhere below the given ancestor.
     */
    public function isDescendantOf(AboutItem $ancestor): bool
    {
        $current = $this;

        // The depth cap guards against a hand-edited cycle in the parent chain
        // turning the walk into an infinite loop.
        for ($depth = 0; $current->parent_id !== null && $depth < 32; $depth++) {
            if ($current->parent_id === $ancestor->id) {
                return true;
            }

            $current = $current->parent;

            if ($current === null) {
                return false;
            }
        }

        return false;
    }

    /**
     * Scope to rows the public page may render.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where('is_visible', true);
    }

    /**
     * Scope to the operator-defined row order.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Scope to rows of one tree level.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function ofType(Builder $query, AboutItemType $type): void
    {
        $query->where('type', $type->value);
    }
}
