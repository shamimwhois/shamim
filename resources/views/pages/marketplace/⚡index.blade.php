<?php

use App\Enums\AccountPermission;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The marketplace catalogue.
 *
 * Five independent filters, all reflected in the URL so any view of the list is
 * shareable (the same state model as the blog index):
 *
 *   ?q=         free-text search over name, tagline, description, framework
 *   ?category=  category slug
 *   ?price=     all | free | paid
 *   ?sort=      featured | newest | updated | price-low | price-high | rating | downloads
 *   ?page=      pagination
 *
 * Only `Published` listings are read here. That rule lives in the model's
 * `published()` scope, not in this component, so the catalogue, the category
 * page and the related-products rail cannot disagree about what is public.
 *
 * `/marketplace/categories/{slug}` renders this same component with the filter
 * pre-seeded rather than a second page: one component means one definition of
 * the catalogue and one place the query is assembled.
 */
new #[Title('Developer Marketplace')] #[Layout('layouts::feed')] class extends Component
{
    use WithPagination;

    /**
     * Free-text query, debounced so typing does not fire a request per keystroke.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'category', except: '')]
    public string $categorySlug = '';

    #[Url(except: 'all')]
    public string $price = 'all';

    #[Url(except: 'featured')]
    public string $sort = 'featured';

    /**
     * Seed the filter from the category route, then publish the head metadata.
     */
    public function mount(?MarketplaceCategory $category = null): void
    {
        if ($category instanceof MarketplaceCategory) {
            $this->categorySlug = $category->slug;
        }

        $this->syncMetadata();
    }

    /**
     * Reset to page 1 whenever a filter changes, and refresh the metadata so a
     * shared link previews the filter it actually points at.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'categorySlug', 'price', 'sort'], true)) {
            $this->resetPage();
            $this->syncMetadata();
        }
    }

    /**
     * Clear every filter at once.
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'categorySlug', 'price', 'sort');
        $this->resetPage();
        $this->syncMetadata();
    }

    /**
     * Apply a category filter, toggling it off when it is already active.
     */
    public function toggleCategory(string $slug): void
    {
        $this->categorySlug = $this->categorySlug === $slug ? '' : $slug;
        $this->resetPage();
        $this->syncMetadata();
    }

    /**
     * Whether any filter is currently narrowing the catalogue.
     */
    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->categorySlug !== ''
            || $this->price !== 'all'
            || $this->sort !== 'featured';
    }

    /**
     * The active categories, with a count of their published listings.
     *
     * Counted through `publishedProducts()` so a category cannot advertise
     * listings the catalogue will not show.
     *
     * @return Collection<int, MarketplaceCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return MarketplaceCategory::query()
            ->active()
            ->ordered()
            ->withCount('publishedProducts')
            ->get();
    }

    /**
     * The filtered catalogue page.
     *
     * @return LengthAwarePaginator<int, MarketplaceProduct>
     */
    #[Computed]
    public function products(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with(['category', 'creator', 'media'])
            ->withAvg('approvedReviews', 'rating')
            ->withCount('approvedReviews')
            ->sortedBy($this->sort)
            ->paginate(9)
            ->withQueryString();
    }

    /**
     * The listing the hero spotlights.
     *
     * A featured listing when one exists, otherwise the most downloaded. It is
     * drawn from the *filtered* set so the poster above the grid never advertises
     * something the grid below is currently excluding.
     */
    #[Computed]
    public function spotlight(): ?MarketplaceProduct
    {
        return $this->filteredQuery()
            ->with(['category', 'creator', 'media'])
            ->withAvg('approvedReviews', 'rating')
            ->withCount('approvedReviews')
            ->orderByDesc('is_featured')
            ->orderByDesc('download_count')
            ->first();
    }

    /**
     * The catalogue query with every active filter applied.
     *
     * One definition, used by both the grid and the spotlight, so the two cannot
     * disagree about what the current view contains.
     *
     * @return Builder<MarketplaceProduct>
     */
    protected function filteredQuery(): Builder
    {
        return MarketplaceProduct::query()
            ->published()
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when($this->categorySlug !== '', fn (Builder $query) => $query->inCategory($this->categorySlug))
            ->when($this->price === 'free', fn (Builder $query) => $query->free())
            ->when($this->price === 'paid', fn (Builder $query) => $query->paid());
    }

    /**
     * Catalogue totals for the hero band. Every figure is a count of rows.
     *
     * @return array{products: int, creators: int, free: int}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'products' => MarketplaceProduct::query()->published()->count(),
            'creators' => MarketplaceProduct::query()->published()->distinct()->count('user_id'),
            'free' => MarketplaceProduct::query()->published()->free()->count(),
        ];
    }

    /**
     * The heading the catalogue section should carry for the current filter.
     */
    #[Computed]
    public function activeCategory(): ?MarketplaceCategory
    {
        if ($this->categorySlug === '') {
            return null;
        }

        return $this->categories->firstWhere('slug', $this->categorySlug);
    }

    /**
     * Whether the signed-in account may open the seller workspace.
     */
    #[Computed]
    public function canSell(): bool
    {
        return auth()->user()?->hasPermission(AccountPermission::CreateProduct) ?? false;
    }

    /**
     * Write the page's head metadata for the current filter state.
     */
    protected function syncMetadata(): void
    {
        $category = $this->categorySlug === ''
            ? null
            : MarketplaceCategory::query()->where('slug', $this->categorySlug)->first();

        app(Seo::class)->set([
            'title' => $category instanceof MarketplaceCategory
                ? $category->name
                : 'Developer Marketplace',
            'description' => $category instanceof MarketplaceCategory
                ? ($category->description ?? __('Developer packages and tools on the DevFolio marketplace.'))
                : __('Browse developer packages, tools and templates. Check compatibility, read real reviews and find clear installation guidance.'),
        ]);
    }
}; ?>

@php
    $priceOptions = [
        'all' => __('All prices'),
        'free' => __('Free'),
        'paid' => __('Paid'),
    ];
@endphp

<x-log.frame active="marketplace" data-test="marketplace-catalogue">
    <x-slot:header>
        <div class="dn-header">
            <div class="dn-page-head">
                <h1 class="dn-page-title">{{ __('Marketplace') }}</h1>
                <p class="dn-page-meta">
                    {{ trans_choice(':count listing|:count listings', $this->products->total(), ['count' => $this->products->total()]) }}
                </p>
            </div>

            <nav class="dn-filters" aria-label="{{ __('Marketplace filters') }}">
                @foreach ($priceOptions as $key => $label)
                    <button
                        type="button"
                        class="dn-filter {{ $price === $key ? 'is-active' : '' }}"
                        wire:click="$set('price', '{{ $key }}')"
                        @if ($price === $key) aria-current="true" @endif
                    >{{ $label }}</button>
                @endforeach

                @foreach ($this->categories as $category)
                    <button
                        type="button"
                        class="dn-filter {{ $categorySlug === $category->slug ? 'is-active' : '' }}"
                        wire:click="toggleCategory('{{ $category->slug }}')"
                        @if ($categorySlug === $category->slug) aria-current="true" @endif
                    >{{ $category->name }} <span class="dn-filter-count">{{ $category->published_products_count }}</span></button>
                @endforeach
            </nav>
        </div>
    </x-slot:header>

    <div class="dn-market">
        <div class="dn-market-search">
            <label for="marketplace-search" class="sr-only">{{ __('Search the marketplace') }}</label>
            <div class="dn-search-field">
                <x-feed.icon name="search" :size="16" />
                <input
                    id="marketplace-search"
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="{{ __('Search packages, tools and templates') }}"
                />
                @if ($this->hasActiveFilters)
                    <button type="button" class="dn-btn dn-btn-ghost dn-btn-sm" wire:click="clearFilters">
                        {{ __('Clear') }}
                    </button>
                @endif
            </div>

            <div class="dn-market-sort">
                <label for="marketplace-sort" class="dn-field-key">{{ __('sort') }}</label>
                <select id="marketplace-sort" wire:model.live="sort" class="dn-input" style="width: auto;">
                    @foreach ([
                        'featured' => __('Featured'),
                        'newest' => __('Newest'),
                        'updated' => __('Recently updated'),
                        'rating' => __('Highest rated'),
                        'downloads' => __('Most downloaded'),
                        'price-low' => __('Price: low to high'),
                        'price-high' => __('Price: high to low'),
                    ] as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($this->products->isEmpty())
            <div class="dn-empty" data-test="marketplace-empty">
                <p class="dn-empty-command">fatal: no listings match</p>
                <p class="dn-empty-text">
                    {{ __('No listings match this view.') }}
                    <a href="{{ route('marketplace.index') }}" wire:navigate>{{ __('Show everything') }}</a>.
                </p>
            </div>
        @else
            <div class="dn-market-list">
                @foreach ($this->products as $product)
                    @php
                        $rating = (float) ($product->approved_reviews_avg_rating ?? 0);
                        $reviews = (int) ($product->approved_reviews_count ?? 0);
                    @endphp

                    <a
                        class="dn-product"
                        href="{{ route('marketplace.show', ['product' => $product->slug]) }}"
                        wire:navigate
                        data-test="marketplace-listing"
                    >
                        @php $cover = $product->media->first(); @endphp

                        @if ($cover !== null)
                            <img
                                class="dn-product-thumb"
                                src="{{ $cover->url() }}"
                                alt="{{ $cover->alt() }}"
                                width="48"
                                height="48"
                                loading="lazy"
                            />
                        @else
                            <span class="dn-product-thumb is-empty" aria-hidden="true">
                                {{ mb_substr($product->name, 0, 1) }}
                            </span>
                        @endif

                        <span class="dn-product-main">
                            <span class="dn-product-head">
                                <span class="dn-product-name">{{ $product->name }}</span>
                                @if ($product->is_featured)
                                    <span class="dn-status-chip">{{ __('featured') }}</span>
                                @endif
                                <span class="dn-product-version">{{ $product->version }}</span>
                            </span>

                            <span class="dn-product-tagline">{{ $product->tagline }}</span>

                            <span class="dn-product-meta">
                                <span>{{ $product->creator?->name }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ $product->framework }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ $product->license }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ trans_choice(':count download|:count downloads', (int) $product->download_count, ['count' => (int) $product->download_count]) }}</span>
                            </span>
                        </span>

                        <span class="dn-product-side">
                            <span class="dn-price">
                                {{ (int) $product->price_cents === 0 ? __('Free') : '$'.number_format($product->price_cents / 100, 2) }}
                            </span>
                            @if ($reviews > 0)
                                <span class="dn-rating" title="{{ trans_choice(':count review|:count reviews', $reviews, ['count' => $reviews]) }}">
                                    <x-feed.icon name="star" :size="13" />
                                    {{ number_format($rating, 1) }}
                                </span>
                            @else
                                <span class="dn-null">{{ __('no reviews') }}</span>
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>

            <x-app.ui.pagination :paginator="$this->products" label="{{ __('Marketplace pagination') }}" class="dn-pagination" />
        @endif
    </div>
</x-log.frame>
