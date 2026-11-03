<?php

use App\Enums\AccountPermission;
use App\Enums\OrderStatus;
use App\Models\MarketplaceOrder;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sales of the signed-in account's listings.
 *
 * The whole page is one scope: {@see MarketplaceOrder::scopeForSellerProducts()}
 * keeps the list to orders whose product belongs to this account, and there is
 * no filter that widens it. A seller reading a stranger's sales would be a
 * leak of buyer behaviour and product revenue at once, so the scope runs
 * before the search does.
 *
 * Money is shown in the currency each order was paid in; totals are per
 * currency, never summed across them.
 */
new #[Layout('layouts::seller.dashboard')] #[Title('Sales')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::ViewOwnSales), 403);
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Sales',
            'description' => 'Orders placed against your marketplace listings.',
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
        $this->search = '';
        $this->statusFilter = 'all';
        $this->resetPage();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->statusFilter !== 'all';
    }

    /**
     * The seller's orders with every filter applied.
     */
    protected function baseQuery()
    {
        return MarketplaceOrder::query()
            ->forSellerProducts(auth()->id())
            ->when($this->search !== '', fn ($query) => $query->search($this->search))
            ->when(
                $this->statusFilter !== 'all',
                fn ($query) => $query->where('status', $this->statusFilter),
            );
    }

    /**
     * @return LengthAwarePaginator<int, MarketplaceOrder>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->with('buyer')
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Gross, count and refunds per currency, over the seller's entitling orders.
     *
     * Deliberately unfiltered: the header describes the account's selling,
     * not the page currently on screen.
     *
     * @return Collection<string, array{orders: int, gross_cents: int, refunded_cents: int}>
     */
    #[Computed]
    public function totals(): Collection
    {
        $sellerId = auth()->id();

        $gross = MarketplaceOrder::query()
            ->forSellerProducts($sellerId)
            ->entitling()
            ->selectRaw('currency, count(*) as aggregate_orders, sum(price_cents) as aggregate_cents')
            ->groupBy('currency')
            ->get()
            ->keyBy('currency');

        $refunded = MarketplaceOrder::query()
            ->forSellerProducts($sellerId)
            ->whereIn('marketplace_orders.status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded])
            ->join('marketplace_refunds', 'marketplace_refunds.marketplace_order_id', '=', 'marketplace_orders.id')
            ->where('marketplace_refunds.status', 'succeeded')
            ->selectRaw('marketplace_orders.currency as currency, sum(marketplace_refunds.amount_cents) as refunded_cents')
            ->groupBy('marketplace_orders.currency')
            ->pluck('refunded_cents', 'currency');

        return $gross->map(fn ($row, string $currency) => [
            'orders' => (int) $row->aggregate_orders,
            'gross_cents' => (int) $row->aggregate_cents,
            'refunded_cents' => (int) ($refunded[$currency] ?? 0),
        ]);
    }

    /**
     * Counts per status for the filter chips, across the seller's orders only.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $counts = MarketplaceOrder::query()
            ->forSellerProducts(auth()->id())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $status) => [
                $status->value => (int) $counts->get($status->value, 0),
            ])
            ->all();
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Selling"
        title="Sales"
        description="Every order placed against your listings. Buyer identities stay private to you, the seller."
        :breadcrumbs="[['label' => __('Selling')], ['label' => __('Sales')]]"
    />

    <x-app.ui.body>
        @if ($this->totals->isNotEmpty())
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ($this->totals as $currency => $row)
                    <div class="sui-card p-5" wire:key="totals-{{ $currency }}">
                        <p class="sui-label">{{ __('Gross (:currency)', ['currency' => $currency]) }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-sui-ink">
                            {{ \App\Models\MarketplaceProduct::symbolFor($currency) }}{{ number_format($row['gross_cents'] / 100, 2) }}
                        </p>
                        <p class="mt-1 text-2xs text-ink-400">
                            {{ $row['orders'] }} {{ __('orders') }}
                            @if ($row['refunded_cents'] > 0)
                                · {{ __(':amount refunded', ['amount' => \App\Models\MarketplaceProduct::symbolFor($currency).number_format($row['refunded_cents'] / 100, 2)]) }}
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="w-full lg:max-w-xs">
                <label for="sale-search" class="sr-only">{{ __('Search') }}</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="sale-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('Search order number or listing…')"
                        autocomplete="off"
                        class="sui-control w-full pl-9"
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
                    @if ($count > 0)
                        <button
                            type="button"
                            wire:click="$set('statusFilter', '{{ $statusValue }}')"
                            @class(['sui-focus sui-tab', 'sui-tab-active' => $statusFilter === $statusValue])
                        >
                            {{ \App\Enums\OrderStatus::from($statusValue)->label() }}
                            <span class="opacity-60 tabular-nums">{{ $count }}</span>
                        </button>
                    @endif
                @endforeach

                @if ($this->hasFilters)
                    <button type="button" wire:click="clearFilters" class="sui-focus sui-action !px-2.5 !py-1.5 !text-xs">
                        {{ __('Clear') }}
                    </button>
                @endif
            </div>
        </div>

        @if ($this->orders->isEmpty())
            <x-app.ui.empty-state
                icon="banknotes"
                title="No sales here yet"
                description="Orders against your listings will appear in this list as buyers check out."
            >
                @if ($this->hasFilters)
                    <flux:button size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                @endif
            </x-app.ui.empty-state>
        @else
            <div class="sui-card overflow-hidden">
                <table class="w-full text-left">
                    <thead class="border-b border-sui-hairline bg-sui-well">
                        <tr>
                            <th scope="col" class="px-5 py-3 sui-table-head">{{ __('Order') }}</th>
                            <th scope="col" class="hidden px-5 py-3 sui-table-head md:table-cell">{{ __('Buyer') }}</th>
                            <th scope="col" class="px-5 py-3 text-right sui-table-head">{{ __('Amount') }}</th>
                            <th scope="col" class="px-5 py-3 text-right sui-table-head">{{ __('Status') }}</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-sui-hairline">
                        @foreach ($this->orders as $order)
                            <tr wire:key="sale-{{ $order->id }}" class="transition duration-150 ease-soft hover:bg-sui-hover">
                                <td class="px-5 py-3.5">
                                    <p class="truncate text-sm font-medium text-sui-ink">{{ $order->product_name }}</p>
                                    <p class="mt-0.5 font-mono text-2xs text-ink-400">
                                        {{ $order->order_number }} · {{ $order->created_at->format('j M Y') }}
                                    </p>
                                </td>

                                <td class="hidden px-5 py-3.5 text-xs text-ink-500 md:table-cell">
                                    {{ $order->buyer->name }}
                                </td>

                                <td class="px-5 py-3.5 text-right text-sm tabular-nums text-sui-ink">
                                    {{ $order->price_cents === 0 ? __('Free') : $order->formattedAmount() }}
                                </td>

                                <td class="px-5 py-3.5 text-right">
                                    <flux:badge :color="$order->status->badgeColor()" size="sm">
                                        {{ $order->status->label() }}
                                    </flux:badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-app.ui.pagination :paginator="$this->orders" />
        @endif
    </x-app.ui.body>
</div>
