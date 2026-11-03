<?php

namespace App\Models;

use App\Concerns\HasSlug;
use App\Support\FluxIcon;
use Database\Factories\MarketplaceCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A catalogue category in the developer marketplace.
 *
 * Categories are rows rather than an enum because operators add, reorder and
 * deactivate them without a deploy. A product may be uncategorised — the
 * column is nullable and the category delete rule is `nullOnDelete` — so
 * removing a category never deletes the listings inside it.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $icon
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MarketplaceProduct> $products
 */
#[Fillable(['name', 'slug', 'description', 'icon', 'sort_order', 'is_active'])]
class MarketplaceCategory extends Model
{
    /** @use HasFactory<MarketplaceCategoryFactory> */
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
     * Get every product in the category, drafts included.
     *
     * @return HasMany<MarketplaceProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(MarketplaceProduct::class);
    }

    /**
     * Get the products in the category that the public may read.
     *
     * @return HasMany<MarketplaceProduct, $this>
     */
    public function publishedProducts(): HasMany
    {
        return $this->products()->published();
    }

    /**
     * Scope to active categories, in the operator-defined order.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to the catalogue order operators control.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
