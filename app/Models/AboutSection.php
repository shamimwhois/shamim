<?php

namespace App\Models;

use App\Enums\AboutSectionType;
use App\Support\FluxIcon;
use Database\Factories\AboutSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A manageable content block on the public About page.
 *
 * Sections are rows rather than hardcoded markup so operators can reorder,
 * retitle, hide, or extend the page without a deploy. The `type` decides how
 * the presenter renders the block and `key` is the stable DOM anchor the
 * scrollspy navigation links to — both stay fixed once published so existing
 * in-page links keep resolving.
 *
 * @property int $id
 * @property string $key
 * @property AboutSectionType $type
 * @property int $ordinal
 * @property string|null $label
 * @property string|null $heading
 * @property string|null $lead
 * @property string|null $body
 * @property string|null $icon
 * @property int $sort_order
 * @property bool $is_visible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AboutItem> $items
 */
#[Fillable(['key', 'type', 'ordinal', 'label', 'heading', 'lead', 'body', 'icon', 'sort_order', 'is_visible'])]
class AboutSection extends Model
{
    /** @use HasFactory<AboutSectionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AboutSectionType::class,
            'icon' => FluxIcon::class,
            'ordinal' => 'integer',
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    /**
     * Get every content row belonging to the section.
     *
     * @return HasMany<AboutItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AboutItem::class);
    }

    /**
     * Scope to sections the public page may render.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where('is_visible', true);
    }

    /**
     * Scope to the page order operators control.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('ordinal')->orderBy('sort_order');
    }
}
