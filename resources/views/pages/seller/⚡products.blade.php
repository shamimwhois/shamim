<?php

use App\Actions\Marketplace\DeleteProduct;
use App\Actions\Marketplace\SubmitProduct;
use App\Enums\AccountPermission;
use App\Enums\ProductStatus;
use App\Models\MarketplaceProduct;
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
 * The seller's own listings, and the two moves they can make on each one.
 *
 * One scope governs the whole page: every query is pinned to the signed-in
 * account before any filter runs, so a search or a status chip narrows the
 * seller's shelf and never reaches past it. A seller reading another's drafts
 * would leak both the unpublished product and the revenue behind it.
 *
 * The two mutations delegate their rules to the actions — ownership, draft
 * state, plan quota — and re-assert the guard the route already carries,
 * because a Livewire update POST never passes through route middleware.
 *
 * The quota line is deliberately advisory. What enforces it is
 * {@see SubmitProduct}; this page only tells the seller where they stand before
 * they click.
 */
new #[Layout('layouts::seller.dashboard')] #[Title('Products')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::CreateProduct), 403);
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Products',
            'description' => 'Your marketplace listings, drafts and submissions.',
            'noindex' => true,
        ]);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search');
        $this->statusFilter = 'all';
        $this->resetPage();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== 'all';
    }

    /**
     * Send a draft listing to the review queue.
     *
     * The action owns the refusal — completeness and plan quota — so the
     * screen reports what it said rather than deciding the same question a
     * second time here.
     */
    public function submit(int $productId, SubmitProduct $submitProduct): void
    {
        $product = $this->findOwned($productId);

        $this->authorize('submit', $product);

        try {
            $submitProduct->handle(auth()->user(), $product);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->forgetListing();

        Flux::toast(variant: 'success', text: __(':name is waiting for review.', ['name' => $product->name]));
    }

    /**
     * Take a listing off the seller's shelf.
     */
    public function delete(int $productId, DeleteProduct $deleteProduct): void
    {
        $product = $this->findOwned($productId);

        $this->authorize('delete', $product);

        $deleteProduct->handle(auth()->user(), $product);

        $this->forgetListing();

        Flux::toast(variant: 'success', text: __(':name was deleted.', ['name' => $product->name]));
    }

    /**
     * @return LengthAwarePaginator<int, MarketplaceProduct>
     */
    #[Computed]
    public function products(): LengthAwarePaginator
    {
        return $this->ownListings()
            ->with('category')
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when(
                $this->statusFilter !== 'all',
                fn (Builder $query) => $query->where('status', $this->statusFilter),
            )
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * Counts per status for the filter chips, across the seller's own listings.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $counts = $this->ownListings()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(ProductStatus::cases())
            ->mapWithKeys(fn (ProductStatus $status) => [
                $status->value => (int) $counts->get($status->value, 0),
            ])
            ->all();
    }

    /**
     * How many of the seller's listings occupy a slot in their plan's quota.
     *
     * @return array{limit: int|null, used: int}
     */
    #[Computed]
    public function quota(): array
    {
        return [
            'limit' => auth()->user()->plan()->productListingLimit(),
            'used' => $this->ownListings()->occupyingQuota()->count(),
        ];
    }

    /**
     * The room left, or null when the plan does not cap listings.
     */
    #[Computed]
    public function roomLeft(): ?int
    {
        $limit = $this->quota['limit'];

        return $limit === null ? null : max($limit - $this->quota['used'], 0);
    }

    protected function ownListings(): Builder
    {
        return MarketplaceProduct::query()->where('user_id', auth()->id());
    }

    /**
     * The listing is only reachable through this page if it is the account's own.
     */
    protected function findOwned(int $productId): MarketplaceProduct
    {
        return $this->ownListings()->findOrFail($productId);
    }

    protected function forgetListing(): void
    {
        unset($this->products, $this->statusCounts, $this->quota, $this->roomLeft);
        $this->resetValidation();
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Selling"
        title="Products"
        description="Everything you have listed. A draft is private until you submit it for review."
        :breadcrumbs="[['label' => __('Selling')], ['label' => __('Products')]]"
    >
        <x-slot:actions>
            <flux:button variant="primary" :href="route('seller.products.create')" icon="plus" wire:navigate>
                {{ __('New product') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.body>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="w-full lg:max-w-xs">
                <label for="product-search" class="sr-only">{{ __('Search your listings') }}</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="product-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('Search name or slug…')"
                        autocomplete="off"
                        class="sui-control w-full pl-9"
                        data-test="product-search"
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by status">
                <button
                    type="button"
                    wire:click="$set('statusFilter', 'all')"
                    @class(['sui-focus sui-tab', 'sui-tab-active' => $statusFilter === 'all'])
                >
                    {{ __('All') }} <span class="opacity-60 tabular-nums">{{ array_sum($this->statusCounts) }}</span>
                </button>

                @foreach ($this->statusCounts as $statusValue => $count)
                    <button
                        type="button"
                        wire:click="$set('statusFilter', '{{ $statusValue }}')"
                        @class(['sui-focus sui-tab', 'sui-tab-active' => $statusFilter === $statusValue])
                    >
                        {{ \App\Enums\ProductStatus::from($statusValue)->label() }}
                        <span class="opacity-60 tabular-nums">{{ $count }}</span>
                    </button>
                @endforeach

                @if ($this->hasFilters)
                    <button type="button" wire:click="clearFilters" class="sui-focus sui-action !px-2.5 !py-1.5 !text-xs">
                        {{ __('Clear') }}
                    </button>
                @endif
            </div>
        </div>

        <p class="text-xs leading-normal text-ink-500">
            @if ($this->quota['limit'] === null)
                {{ __('Your plan lists without a cap.') }}
            @else
                {{ __(':used of :limit listing slots are in use. Drafts do not take a slot until they are submitted.', [
                    'used' => $this->quota['used'],
                    'limit' => $this->quota['limit'],
                ]) }}
            @endif
        </p>

        @if ($this->products->isEmpty())
            <x-app.ui.empty-state
                icon="cube"
                :title="$this->hasFilters ? __('No listings match those filters') : __('You have no listings yet')"
                :description="$this->hasFilters
                    ? __('Clear the filters to see your whole shelf.')
                    : __('Start with a draft: give it a name, a price and a description, then submit it for review.')"
            >
                @if ($this->hasFilters)
                    <flux:button size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                @else
                    <flux:button size="sm" variant="primary" :href="route('seller.products.create')" icon="plus" wire:navigate>
                        {{ __('New product') }}
                    </flux:button>
                @endif
            </x-app.ui.empty-state>
        @else
            <div class="sui-card overflow-hidden">
                <table class="w-full text-left">
                    <thead class="border-b border-sui-hairline bg-sui-well">
                        <tr>
                            <th scope="col" class="px-5 py-3 sui-table-head">{{ __('Product') }}</th>
                            <th scope="col" class="px-5 py-3 sui-table-head">{{ __('Status') }}</th>
                            <th scope="col" class="hidden px-5 py-3 text-right sui-table-head sm:table-cell">{{ __('Price') }}</th>
                            <th scope="col" class="hidden px-5 py-3 text-right sui-table-head md:table-cell">{{ __('Updated') }}</th>
                            <th scope="col" class="px-5 py-3 text-right sui-table-head">
                                <span class="sr-only">{{ __('Actions') }}</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-sui-hairline">
                        @foreach ($this->products as $product)
                            <tr wire:key="listing-{{ $product->id }}" class="transition duration-150 ease-soft hover:bg-sui-hover">
                                <td class="px-5 py-3.5">
                                    <p class="truncate text-sm font-medium text-sui-ink">{{ $product->name }}</p>

                                    <p class="mt-0.5 truncate font-mono text-2xs text-ink-400">
                                        {{ $product->slug }} · v{{ $product->version }}
                                    </p>
                                </td>

                                <td class="px-5 py-3.5">
                                    <flux:badge :color="$product->status->badgeColor()" size="sm">
                                        {{ $product->status->label() }}
                                    </flux:badge>
                                </td>

                                <td class="hidden px-5 py-3.5 text-right text-sm tabular-nums text-sui-ink sm:table-cell">
                                    {{ $product->isFree() ? __('Free') : $product->formattedPrice() }}
                                </td>

                                <td class="hidden px-5 py-3.5 text-right text-2xs text-ink-500 md:table-cell">
                                    <time datetime="{{ $product->updated_at?->toIso8601String() }}">
                                        {{ $product->updated_at?->diffForHumans(short: true) }}
                                    </time>
                                </td>

                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($product->status === \App\Enums\ProductStatus::Draft)
                                            @unless ($product->isReadyForSubmission())
                                                <span
                                                    class="text-2xs text-ink-400"
                                                    title="{{ __('Still needs: :fields', ['fields' => implode(', ', $product->missingSubmissionFields())]) }}"
                                                    data-test="listing-incomplete-{{ $product->id }}"
                                                >
                                                    {{ __('Incomplete') }}
                                                </span>
                                            @endunless

                                            <div x-data="{ confirmingDelete: false }" class="flex items-center gap-2">
                                                <template x-if="! confirmingDelete">
                                                    <div class="flex items-center gap-2">
                                                        <flux:button
                                                            variant="ghost"
                                                            size="xs"
                                                            :href="route('seller.products.edit', $product)"
                                                            icon="pencil-square"
                                                            wire:navigate
                                                            data-test="listing-edit-{{ $product->id }}"
                                                        >
                                                            {{ __('Edit') }}
                                                        </flux:button>

                                                        <flux:button
                                                            variant="primary"
                                                            size="xs"
                                                            wire:click="submit({{ $product->id }})"
                                                            wire:loading.attr="disabled"
                                                            icon="paper-airplane"
                                                            data-test="listing-submit-{{ $product->id }}"
                                                        >
                                                            {{ __('Submit') }}
                                                        </flux:button>

                                                        <flux:button
                                                            variant="ghost"
                                                            size="xs"
                                                            x-on:click="confirmingDelete = true"
                                                            icon="trash"
                                                            class="!text-sui-danger"
                                                        >
                                                            <span class="sr-only">{{ __('Delete') }}</span>
                                                        </flux:button>
                                                    </div>
                                                </template>

                                                <template x-if="confirmingDelete">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-2xs text-sui-danger">{{ __('Delete this draft?') }}</span>

                                                        <flux:button
                                                            variant="danger"
                                                            size="xs"
                                                            wire:click="delete({{ $product->id }})"
                                                            wire:loading.attr="disabled"
                                                            data-test="listing-confirm-{{ $product->id }}"
                                                        >
                                                            {{ __('Confirm') }}
                                                        </flux:button>

                                                        <flux:button variant="ghost" size="xs" x-on:click="confirmingDelete = false">
                                                            {{ __('Cancel') }}
                                                        </flux:button>
                                                    </div>
                                                </template>
                                            </div>
                                        @else
                                            <flux:button
                                                variant="ghost"
                                                size="xs"
                                                :href="route('seller.products.edit', $product)"
                                                icon="eye"
                                                wire:navigate
                                                data-test="listing-edit-{{ $product->id }}"
                                            >
                                                {{ $product->isPublished() ? __('View') : __('Open') }}
                                            </flux:button>

                                            @if ($product->isPublished())
                                                <a
                                                    href="{{ route('marketplace.show', $product->slug) }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="sui-action !px-2.5 !py-1.5 !text-xs"
                                                >
                                                    <flux:icon name="arrow-top-right-on-square" class="size-3.5" />
                                                    {{ __('Storefront') }}
                                                </a>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-app.ui.pagination :paginator="$this->products" label="{{ __('Products pagination') }}" />
        @endif
    </x-app.ui.body>
</div>
