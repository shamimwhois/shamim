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
new #[Title('Developer Marketplace')] class extends Component
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
    $sortOptions = [
        'featured' => __('Featured'),
        'newest' => __('Newest'),
        'updated' => __('Recently updated'),
        'rating' => __('Highest rated'),
        'downloads' => __('Most downloaded'),
        'price-low' => __('Price: low to high'),
        'price-high' => __('Price: high to low'),
    ];

    $priceOptions = [
        'all' => __('All prices'),
        'free' => __('Free'),
        'paid' => __('Paid'),
    ];

    /* The Vibe96 skin lives in resources/css/marketplace.css, scoped to the
       `.v96` root below; native selects restyle through its `.v96-select`. */
@endphp

<div data-test="marketplace-catalogue" class="v96">
    @assets
        {{-- Scoped type: Manrope carries the reading voice, IBM Plex Mono the
             labels and code. Hoisted to the head by Livewire, loaded only on
             the marketplace surfaces. --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @endassets

    {{-- ================================================================== --
     | 01 — Hero
     --}}
    <section class="v96-hero">
        <div class="shell relative py-14 lg:py-20">
            <p class="v96-eyebrow">{{ __('Developer marketplace') }}</p>

            <div class="mt-6 grid items-start gap-12 lg:grid-cols-[minmax(0,7fr)_minmax(0,4fr)] lg:gap-16">
                <div class="min-w-0">
                    <h1 class="max-w-3xl text-[clamp(2.25rem,5vw,3.75rem)] font-extrabold leading-[1.05] tracking-tight text-ink-900 dark:text-ink-50">
                        {{ __('Tools worth building on.') }}
                    </h1>

                    <p class="mt-5 max-w-2xl text-[15px] leading-relaxed text-ink-600 dark:text-ink-300">
                        {{ __('Packages, components and starters from developers who ship. Every listing states what it costs, what it runs on and what its licence allows — before you install anything.') }}
                    </p>

                    {{-- The primary interaction. Live and debounced: a search box
                         that needs a button is a search box people do not use. --}}
                    <div class="mt-8 max-w-xl">
                        <label for="marketplace-search" class="sr-only">{{ __('Search the marketplace') }}</label>
                        <div class="relative">
                            <span aria-hidden="true" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-400 dark:text-ink-500">
                                <flux:icon name="magnifying-glass" class="size-4" />
                            </span>

                            <input
                                id="marketplace-search"
                                type="search"
                                wire:model.live.debounce.400ms="search"
                                placeholder="{{ __('Search packages, tools and templates') }}"
                                class="v96-input !rounded-full py-3 pl-11 pr-4 shadow-[0_10px_30px_-18px_rgba(40,20,80,0.4)]"
                            >
                        </div>

                        <dl class="mt-6 flex flex-wrap items-center gap-x-8 gap-y-3">
                            @foreach ([
                                ['label' => __('Listings'), 'value' => $this->stats['products']],
                                ['label' => __('Creators'), 'value' => $this->stats['creators']],
                                ['label' => __('Free'), 'value' => $this->stats['free']],
                            ] as $stat)
                                <div>
                                    <dt class="v96-mono text-2xs uppercase tracking-[0.13em] text-ink-400 dark:text-ink-500">{{ $stat['label'] }}</dt>
                                    <dd class="mt-0.5 text-lg font-extrabold tabular-nums tracking-tight text-ink-900 dark:text-ink-50">{{ number_format($stat['value']) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </div>

                {{-- Spotlight: the one place the page inverts, so the featured
                     listing reads as a poster rather than another card. --}}
                @if ($this->spotlight)
                    @php $spotlight = $this->spotlight; @endphp

                    <aside class="v96-night relative overflow-hidden rounded-[14px]" aria-label="{{ __('Featured listing') }}">
                        <div aria-hidden="true" class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(118,36,244,0.45),transparent_62%)]"></div>

                        <div class="relative p-6 sm:p-8">
                            <div class="flex items-center justify-between gap-3">
                                <p class="v96-mono text-2xs uppercase tracking-[0.13em] text-white/50">{{ __('Featured listing') }}</p>

                                @if ($spotlight->is_featured)
                                    <span class="v96-mono rounded-full bg-[rgba(118,36,244,0.28)] px-2.5 py-1 text-2xs uppercase tracking-[0.13em] text-[#D6BBFE]">
                                        {{ __('Editor’s pick') }}
                                    </span>
                                @endif
                            </div>

                            <h2 class="mt-6 text-xl font-bold tracking-tight text-white sm:text-2xl">
                                {{ $spotlight->name }}
                            </h2>

                            <p class="mt-3 text-sm leading-relaxed text-white/70">
                                {{ $spotlight->tagline }}
                            </p>

                            <div class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-2">
                                <span class="text-lg font-extrabold tracking-tight text-white">{{ $spotlight->formattedPrice() }}</span>
                                <span class="text-2xs text-white/60">{{ $spotlight->creator->name }}</span>
                                <span class="text-2xs text-white/45">{{ $spotlight->license->label() }}</span>
                            </div>

                            @if ($spotlight->install_command)
                                <div class="mt-6 border-t border-white/10 pt-5">
                                    <p class="v96-mono text-2xs uppercase tracking-[0.13em] text-white/50">{{ __('Install command') }}</p>
                                    <code class="v96-mono mt-3 block break-all rounded-lg bg-black/40 px-3 py-3 text-xs leading-relaxed text-[#D6BBFE]">{{ $spotlight->install_command }}</code>
                                </div>
                            @endif

                            <a
                                href="{{ route('marketplace.show', ['product' => $spotlight->slug]) }}"
                                wire:navigate
                                class="focus-ring mt-6 inline-flex min-h-10 items-center gap-2 rounded-lg bg-white px-5 py-2 text-sm font-bold text-[#211A31] transition-colors hover:bg-[#F4EEFE] hover:text-[#5B14CF]"
                            >
                                {{ __('Open listing') }}
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        </div>
                    </aside>
                @endif
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Categories
     --}}
    @if ($this->categories->isNotEmpty())
        <section class="border-b border-ink-200 py-7 dark:border-ink-800" aria-labelledby="categories-heading">
            <div class="shell">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <h2 id="categories-heading" class="v96-eyebrow">
                        {{ __('Browse by category') }}
                    </h2>

                    @if ($this->categorySlug !== '')
                        <button
                            type="button"
                            wire:click="toggleCategory('{{ $categorySlug }}')"
                            class="focus-ring v96-mono inline-flex min-h-8 items-center gap-1.5 rounded-full px-3 text-2xs uppercase tracking-[0.13em] text-(--v96-eyebrow) transition-colors hover:bg-(--v96-soft)"
                        >
                            {{ __('Clear category') }}
                            <span aria-hidden="true">&times;</span>
                        </button>
                    @endif
                </div>

                <ul class="mt-4 flex snap-x gap-1.5 overflow-x-auto pb-1" role="list">
                    <li class="shrink-0">
                        <button
                            type="button"
                            wire:click="clearFilters"
                            class="v96-chip"
                            @if (! $this->hasActiveFilters) aria-pressed="true" @endif
                        >
                            {{ __('Everything') }}
                        </button>
                    </li>

                    @foreach ($this->categories as $category)
                        <li class="shrink-0">
                            <button
                                type="button"
                                wire:click="toggleCategory('{{ $category->slug }}')"
                                class="v96-chip"
                                @if ($categorySlug === $category->slug) aria-pressed="true" @endif
                            >
                                <flux:icon :name="$category->icon" class="size-4" />
                                <span>{{ $category->name }}</span>
                                <span class="v96-mono text-2xs tabular-nums opacity-70">{{ $category->published_products_count }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 03 — Catalogue
     --}}
    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="catalogue-heading">
        <div class="mt-0 flex flex-wrap items-end justify-between gap-6">
            <div class="min-w-0">
                <p class="v96-eyebrow">{{ __('Catalogue') }}</p>

                <h2 id="catalogue-heading" class="mt-4 text-[clamp(1.75rem,2.4vw,2.75rem)] font-extrabold tracking-tight text-ink-900 dark:text-ink-50">
                    {{ $this->activeCategory?->name ?? __('Every listing') }}
                </h2>

                <p class="mt-3 max-w-xl text-[14.5px] leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ $this->activeCategory?->description ?? __('Published listings, newest first unless you choose otherwise. Reviews are written by people who have used the product.') }}
                </p>
            </div>

            <p class="v96-mono shrink-0 text-2xs uppercase tracking-[0.13em] text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count listing|:count listings', $this->products->total()) }}
            </p>
        </div>

        {{-- Toolbar. Native selects, styled once: a custom listbox is more
             JavaScript than a filter needs, and native selects are already
             keyboard- and screen-reader-native. --}}
        <div class="mt-8 flex flex-wrap items-end gap-3 border-y border-ink-200 py-4 dark:border-ink-800">
            <div>
                <label for="marketplace-price" class="v96-eyebrow mb-1.5 block">
                    {{ __('Price') }}
                </label>
                <div class="relative">
                    <select id="marketplace-price" wire:model.live="price" class="v96-select min-w-40 appearance-none pr-9">
                        @foreach ($priceOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                </div>
            </div>

            <div>
                <label for="marketplace-sort" class="v96-eyebrow mb-1.5 block">
                    {{ __('Sort') }}
                </label>
                <div class="relative">
                    <select id="marketplace-sort" wire:model.live="sort" class="v96-select min-w-44 appearance-none pr-9">
                        @foreach ($sortOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                </div>
            </div>

            @if ($search !== '')
                <p class="v96-chip v96-chip-sm min-h-10">
                    <span class="v96-mono text-2xs uppercase tracking-[0.13em] text-ink-400 dark:text-ink-500">{{ __('Query') }}</span>
                    <span class="max-w-40 truncate">{{ $search }}</span>
                    <button type="button" wire:click="$set('search', '')" class="focus-ring rounded p-0.5 text-ink-400 hover:text-(--v96-purple-lift)" aria-label="{{ __('Clear the search term') }}">
                        <flux:icon name="x-mark" class="size-3.5" />
                    </button>
                </p>
            @endif

            @if ($this->hasActiveFilters)
                <button
                    type="button"
                    wire:click="clearFilters"
                    class="focus-ring v96-mono ml-auto inline-flex min-h-10 items-center rounded-full px-3 text-2xs uppercase tracking-[0.13em] text-ink-500 transition-colors hover:text-(--v96-purple-lift) dark:text-ink-400"
                >
                    {{ __('Reset filters') }}
                </button>
            @endif
        </div>

        @if ($this->products->isNotEmpty())
            <div class="mt-8 grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->products as $product)
                    <x-app.marketplace.product-card :product="$product" wire:key="product-{{ $product->id }}" />
                @endforeach
            </div>

            <x-app.ui.pagination :paginator="$this->products" label="{{ __('Marketplace pagination') }}" class="mt-10" />
        @else
            <x-app.ui.empty-state
                class="mt-8"
                icon="magnifying-glass"
                :title="__('No listings match this view')"
                :description="__('Try a broader search, a different category, or clear the filters to see everything.')"
            >
                <flux:button wire:click="clearFilters" icon="arrow-path">
                    {{ __('Clear filters') }}
                </flux:button>
            </x-app.ui.empty-state>
        @endif
    </section>

    {{-- ================================================================== --
     | 04 — Selling
     --}}
    <section class="v96-band border-t py-14 lg:py-16" aria-labelledby="sell-heading">
        <div class="shell grid gap-8 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center lg:gap-16">
            <div>
                <p class="v96-eyebrow">{{ __('Selling') }}</p>

                <h2 id="sell-heading" class="mt-4 max-w-2xl text-[clamp(1.5rem,2.2vw,2rem)] font-extrabold tracking-tight text-ink-900 dark:text-ink-50">
                    {{ __('Publish your own work') }}
                </h2>

                <p class="mt-3 max-w-xl text-[14.5px] leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ __('Listings are reviewed by a person before they appear here, and every listing states its licence and its price. Sellers manage drafts and submissions from the seller workspace.') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @auth
                    @if ($this->canSell)
                        <a href="{{ route('seller.dashboard') }}" wire:navigate class="focus-ring v96-btn">
                            {{ __('Open seller workspace') }}
                        </a>
                    @endif
                @else
                    {{-- Guest-intercepted: `wire:navigate` stays off on purpose —
                         Livewire's capture-phase listener would walk the click
                         through to /login before the dialog can open. --}}
                    <a
                        href="{{ route('login') }}"
                        x-on:click="$flux.modal('login').show(); $event.preventDefault()"
                        class="focus-ring v96-btn"
                    >
                        {{ __('Sign in to sell') }}
                    </a>
                @endauth

                <a
                    href="{{ route('about') }}"
                    wire:navigate
                    class="focus-ring inline-flex min-h-10 items-center gap-1.5 rounded-lg px-3 text-sm font-semibold text-(--v96-body) transition-colors hover:text-(--v96-purple-lift) dark:text-ink-300"
                >
                    {{ __('About the platform') }}
                    <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        </div>
    </section>
</div>
