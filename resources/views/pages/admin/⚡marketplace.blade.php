<?php

use App\Actions\Marketplace\ApproveProduct;
use App\Actions\Marketplace\ApproveReview;
use App\Actions\Marketplace\RejectProduct;
use App\Actions\Marketplace\RejectReview;
use App\Enums\AccountPermission;
use App\Enums\ProductStatus;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceReview;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Marketplace moderation console.
 *
 * Two queues behind one page, because they are two halves of the same job: a
 * listing is not trustworthy until its product has been reviewed, and it is not
 * credible until its reviews have. Splitting them across two routes would mean
 * an operator checks one and forgets the other.
 *
 * ## Authorisation
 *
 * The route requires `product:review`. Every mutating method re-checks that
 * permission rather than trusting the page guard, because `wire:click` is
 * client input — the guard on the route proves the operator could open the
 * console, not that they may act on a particular row.
 *
 * ## Nothing here deletes
 *
 * Withdrawing a listing sets it back to draft; rejecting a review records the
 * decision. Both keep the row so the seller and the reviewer can see what
 * happened and the platform keeps its history.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Marketplace moderation')] class extends Component
{
    use WithPagination;

    /** The two queues this page owns. */
    private const array TABS = ['listings', 'reviews'];

    /** Free-text search over listing name, tagline and description. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Status filter: `all` or a {@see ProductStatus} value. */
    #[Url(as: 'status', except: 'all')]
    public string $status = 'all';

    #[Url(as: 'tab', except: 'listings')]
    public string $tab = 'listings';

    public function mount(): void
    {
        $this->authorizeReview();

        abort_unless(in_array($this->tab, self::TABS, true), 404);

        app(Seo::class)->set([
            'title' => 'Marketplace moderation',
            'description' => 'Review marketplace listings and product reviews.',
            'noindex' => true,
        ]);
    }

    /**
     * Reset both queues to their first page when a filter changes.
     *
     * Each queue paginates under its own page name, so they cannot fight over
     * `?page=` — but both still need resetting when the result set changes.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage('listingsPage');
            $this->resetPage('reviewsPage');
        }
    }

    /**
     * Switch queue. Re-checks the whitelist because `wire:click` is client input.
     */
    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, self::TABS, true), 404);

        $this->tab = $tab;
    }

    /**
     * Publish a listing.
     */
    public function approveProduct(int $productId): void
    {
        $this->authorizeReview();

        $product = MarketplaceProduct::query()->findOrFail($productId);

        app(ApproveProduct::class)->handle($product);

        Flux::toast(variant: 'success', text: __(':name is now published.', ['name' => $product->name]));
    }

    /**
     * Return a listing to its creator as a draft.
     */
    public function withdrawProduct(int $productId): void
    {
        $this->authorizeReview();

        $product = MarketplaceProduct::query()->findOrFail($productId);

        app(RejectProduct::class)->handle($product);

        Flux::toast(variant: 'success', text: __(':name was returned to draft.', ['name' => $product->name]));
    }

    /**
     * Toggle the front-page flag on a listing.
     */
    public function toggleFeatured(int $productId): void
    {
        $this->authorizeReview();

        $product = MarketplaceProduct::query()->findOrFail($productId);

        $product->forceFill(['is_featured' => ! $product->is_featured])->save();

        Flux::toast(
            variant: 'success',
            text: $product->is_featured
                ? __(':name is featured.', ['name' => $product->name])
                : __(':name is no longer featured.', ['name' => $product->name]),
        );
    }

    /**
     * Publish a product review.
     */
    public function approveReview(int $reviewId): void
    {
        $this->authorizeReview();

        app(ApproveReview::class)->handle(
            MarketplaceReview::query()->findOrFail($reviewId),
        );

        Flux::toast(variant: 'success', text: __('Review published.'));
    }

    /**
     * Reject a product review.
     */
    public function rejectReview(int $reviewId): void
    {
        $this->authorizeReview();

        app(RejectReview::class)->handle(
            MarketplaceReview::query()->findOrFail($reviewId),
        );

        Flux::toast(variant: 'success', text: __('Review rejected.'));
    }

    /**
     * Clear the listing filters.
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage('listingsPage');
    }

    /**
     * Whether any listing filter is narrowing the queue.
     */
    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== 'all';
    }

    /**
     * Queue totals. Every figure is a count or a sum of stored columns.
     *
     * @return array{total: int, published: int, pending: int, reviews: int, downloads: int}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'total' => MarketplaceProduct::query()->count(),
            'published' => MarketplaceProduct::query()->published()->count(),
            'pending' => MarketplaceProduct::query()->pendingReview()->count(),
            'reviews' => MarketplaceReview::query()->pending()->count(),
            'downloads' => (int) MarketplaceProduct::query()->sum('download_count'),
        ];
    }

    /**
     * The filtered listing queue.
     *
     * @return LengthAwarePaginator<int, MarketplaceProduct>
     */
    #[Computed]
    public function products(): LengthAwarePaginator
    {
        return MarketplaceProduct::query()
            ->with(['category', 'creator'])
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when(
                ProductStatus::tryFrom($this->status) !== null,
                fn (Builder $query) => $query->where('status', ProductStatus::from($this->status)),
            )
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('updated_at')
            ->paginate(10, pageName: 'listingsPage')
            ->withQueryString();
    }

    /**
     * The review queue, oldest first so nothing is left behind.
     *
     * @return LengthAwarePaginator<int, MarketplaceReview>
     */
    #[Computed]
    public function reviews(): LengthAwarePaginator
    {
        return MarketplaceReview::query()
            ->pending()
            ->with(['product', 'author'])
            ->oldest()
            ->paginate(10, pageName: 'reviewsPage')
            ->withQueryString();
    }

    /**
     * Re-check the permission every mutating method relies on.
     */
    private function authorizeReview(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(AccountPermission::ReviewProducts) ?? false,
            403,
        );
    }
}; ?>

@php
    $statusOptions = [
        'all' => __('All statuses'),
        ...collect(ProductStatus::cases())
            ->mapWithKeys(fn (ProductStatus $status) => [$status->value => $status->label()])
            ->all(),
    ];
@endphp

<div data-test="admin-marketplace">
    <x-app.ui.header
        eyebrow="Administration"
        title="Marketplace"
        description="Review listings before they go live, and moderate the reviews attached to them."
        :breadcrumbs="[['label' => __('Administration')]]"
    >
        <x-slot:actions>
            <flux:button :href="route('marketplace.index')" icon="arrow-top-right-on-square" wire:navigate>
                {{ __('View the marketplace') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.body>
        {{-- ============================================================= --
         | Queue metrics
         --}}
        <section aria-labelledby="metrics-heading">
            <h2 id="metrics-heading" class="sr-only">{{ __('Marketplace metrics') }}</h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-app.ui.stat
                    label="Listings"
                    :value="$this->stats['total']"
                    icon="shopping-bag"
                    tone="primary"
                    :hint="__(':count published', ['count' => number_format($this->stats['published'])])"
                />

                <x-app.ui.stat
                    label="Awaiting review"
                    :value="$this->stats['pending']"
                    icon="clipboard-document-check"
                    :tone="$this->stats['pending'] > 0 ? 'attention' : 'primary'"
                    :hint="$this->stats['pending'] > 0 ? __('Needs an operator') : __('Queue is clear')"
                />

                <x-app.ui.stat
                    label="Reviews to moderate"
                    :value="$this->stats['reviews']"
                    icon="chat-bubble-left-right"
                    :tone="$this->stats['reviews'] > 0 ? 'warning' : 'primary'"
                    :hint="$this->stats['reviews'] > 0 ? __('Unpublished reviews') : __('Nothing waiting')"
                />

                <x-app.ui.stat
                    label="Downloads"
                    :value="$this->stats['downloads']"
                    icon="arrow-down-tray"
                    tone="success"
                    hint="Across every listing"
                />
            </div>
        </section>

        {{-- ============================================================= --
         | Queues
         --}}
        <x-app.ui.section :padded="false">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-sui-hairline px-5">
                <nav class="-mb-px flex gap-1" aria-label="{{ __('Moderation queues') }}">
                    @foreach (['listings' => __('Listings'), 'reviews' => __('Reviews')] as $key => $label)
                        <button
                            type="button"
                            wire:click="showTab('{{ $key }}')"
                            @class([
                                'sui-focus border-b-2 px-3 py-4 text-sm transition-colors',
                                'border-sui-accent font-semibold text-sui-ink' => $tab === $key,
                                'border-transparent text-ink-500 hover:text-sui-ink' => $tab !== $key,
                            ])
                            @if ($tab === $key) aria-current="page" @endif
                        >
                            {{ $label }}

                            @if ($key === 'reviews' && $this->stats['reviews'] > 0)
                                <span class="ml-1.5 rounded-full bg-sui-attention-wash px-1.5 py-0.5 font-mono text-2xs tabular-nums text-sui-attention-ink">
                                    {{ $this->stats['reviews'] }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </nav>

                @if ($tab === 'listings')
                    <div class="flex flex-wrap items-center gap-2 py-2.5">
                        <label for="admin-marketplace-search" class="sr-only">{{ __('Search listings') }}</label>
                        <input
                            id="admin-marketplace-search"
                            type="search"
                            wire:model.live.debounce.400ms="search"
                            placeholder="{{ __('Search listings') }}"
                            class="sui-control w-56 !py-1.5 text-xs"
                        >

                        <label for="admin-marketplace-status" class="sr-only">{{ __('Filter by status') }}</label>
                        <select id="admin-marketplace-status" wire:model.live="status" class="sui-control w-40 !py-1.5 text-xs">
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>

                        @if ($this->hasFilters)
                            <flux:button size="sm" variant="ghost" wire:click="clearFilters" icon="arrow-path">
                                {{ __('Clear') }}
                            </flux:button>
                        @endif
                    </div>
                @endif
            </div>

            @if ($tab === 'listings')
                @if ($this->products->isEmpty())
                    <div class="p-5">
                        <x-app.ui.empty-state
                            icon="shopping-bag"
                            :title="__('No listings match this view')"
                            :description="__('Clear the filters, or wait for a seller to submit something.')"
                        />
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="border-b border-sui-hairline bg-sui-well">
                                <tr>
                                    <th scope="col" class="px-5 py-3 sui-table-head">{{ __('Listing') }}</th>
                                    <th scope="col" class="hidden px-5 py-3 sui-table-head lg:table-cell">{{ __('Category') }}</th>
                                    <th scope="col" class="hidden px-5 py-3 sui-table-head sm:table-cell">{{ __('Price') }}</th>
                                    <th scope="col" class="px-5 py-3 sui-table-head">{{ __('Status') }}</th>
                                    <th scope="col" class="px-5 py-3 text-right sui-table-head">{{ __('Actions') }}</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-sui-hairline">
                                @foreach ($this->products as $product)
                                    <tr wire:key="admin-product-{{ $product->id }}" class="transition duration-150 ease-soft hover:bg-sui-hover">
                                        <td class="px-5 py-3.5">
                                            <div class="min-w-0">
                                                <p class="flex min-w-0 items-center gap-1.5 text-sm font-medium text-sui-ink">
                                                    <span class="truncate">{{ $product->name }}</span>

                                                    @if ($product->is_featured)
                                                        <flux:icon name="star" variant="solid" class="size-3.5 shrink-0 text-sui-warning" />
                                                    @endif
                                                </p>
                                                <p class="truncate text-2xs text-ink-400">
                                                    {{ $product->creator->name }} · v{{ $product->version }}
                                                </p>
                                            </div>
                                        </td>

                                        <td class="hidden px-5 py-3.5 text-2xs text-ink-500 lg:table-cell">
                                            {{ $product->category?->name ?? '—' }}
                                        </td>

                                        <td class="hidden px-5 py-3.5 text-xs tabular-nums text-ink-600 sm:table-cell">
                                            {{ $product->formattedPrice() }}
                                        </td>

                                        <td class="px-5 py-3.5">
                                            <x-app.marketplace.product-status :status="$product->status" />
                                        </td>

                                        <td class="px-5 py-3.5">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    icon="star"
                                                    :icon-variant="$product->is_featured ? 'solid' : 'outline'"
                                                    wire:click="toggleFeatured({{ $product->id }})"
                                                    :aria-label="$product->is_featured ? __('Unfeature :name', ['name' => $product->name]) : __('Feature :name', ['name' => $product->name])"
                                                />

                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    icon="arrow-top-right-on-square"
                                                    :href="route('marketplace.show', ['product' => $product->slug])"
                                                    :aria-label="__('View :name', ['name' => $product->name])"
                                                />

                                                @if ($product->status === ProductStatus::Published)
                                                    <flux:button size="sm" wire:click="withdrawProduct({{ $product->id }})">
                                                        {{ __('Withdraw') }}
                                                    </flux:button>
                                                @else
                                                    <flux:button size="sm" variant="primary" wire:click="approveProduct({{ $product->id }})">
                                                        {{ __('Publish') }}
                                                    </flux:button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="px-5 pb-5">
                        <x-app.ui.pagination :paginator="$this->products" label="{{ __('Listing queue pagination') }}" />
                    </div>
                @endif

            @else
                @if ($this->reviews->isEmpty())
                    <div class="p-5">
                        <x-app.ui.empty-state
                            icon="chat-bubble-left-right"
                            :title="__('No reviews are waiting')"
                            :description="__('New reviews appear here until somebody approves or rejects them.')"
                        />
                    </div>
                @else
                    <ul class="divide-y divide-sui-hairline">
                        @foreach ($this->reviews as $review)
                            <li wire:key="admin-review-{{ $review->id }}" class="px-5 py-4">
                                <p class="text-2xs text-ink-400">
                                    {{ __('On :product', ['product' => $review->product->name]) }}
                                </p>

                                <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-full border border-sui-hairline bg-sui-well text-xs font-semibold text-ink-500">
                                            {{ Str::of($review->author?->name ?? __('Reader'))->substr(0, 1)->upper() }}
                                        </span>

                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-sui-ink">
                                                {{ $review->author?->name ?? __('Former member') }}
                                            </p>

                                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                                <span class="flex items-center gap-0.5" role="img" aria-label="{{ __(':rating out of 5', ['rating' => $review->rating]) }}">
                                                    @for ($star = 1; $star <= 5; $star++)
                                                        <flux:icon
                                                            name="star"
                                                            :variant="$star <= $review->rating ? 'solid' : 'outline'"
                                                            class="size-3 {{ $star <= $review->rating ? 'text-sui-warning' : 'text-ink-300' }}"
                                                        />
                                                    @endfor
                                                </span>

                                                <span class="text-2xs text-ink-400">
                                                    {{ $review->created_at?->format('j M Y') }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <flux:badge :color="$review->status->badgeColor()" size="sm">
                                            {{ $review->status->label() }}
                                        </flux:badge>

                                        <flux:button size="sm" variant="primary" wire:click="approveReview({{ $review->id }})">
                                            {{ __('Approve') }}
                                        </flux:button>

                                        <flux:button size="sm" variant="danger" wire:click="rejectReview({{ $review->id }})">
                                            {{ __('Reject') }}
                                        </flux:button>
                                    </div>
                                </div>

                                @if ($review->title)
                                    <h4 class="mt-4 text-sm font-semibold text-sui-ink">
                                        {{ $review->title }}
                                    </h4>
                                @endif

                                <p class="mt-2 text-sm leading-relaxed text-ink-600">
                                    {{ $review->body }}
                                </p>
                            </li>
                        @endforeach
                    </ul>

                    <div class="px-5 pb-5">
                        <x-app.ui.pagination :paginator="$this->reviews" label="{{ __('Review queue pagination') }}" />
                    </div>
                @endif
            @endif
        </x-app.ui.section>

        <p class="text-2xs leading-relaxed text-ink-500">
            {{ __('Publishing a listing stamps its release date once; withdrawing it returns it to the seller as a draft without deleting anything. Ratings on the storefront are calculated from approved reviews only.') }}
        </p>
    </x-app.ui.body>
</div>
