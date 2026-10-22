<?php

use App\Enums\AccountPermission;
use App\Enums\OrderStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceRefund;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The payment ledger: every attempt and every refund, across every account.
 *
 * ## Why this is not the orders console
 *
 * `/admin/orders` answers "what happened to this sale" and walks down from an
 * order to its attempts. This page answers the other question — "what did this
 * gateway do today" — and walks across accounts, which is the shape an
 * operator needs when a provider reports a decline rate, a deposit is missing
 * a reference, or two accounts share one gateway id. An order cannot be the
 * entry point for that query because the row that matters is the attempt, not
 * the sale.
 *
 * ## Authorisation
 *
 * `sale:view-any` opens it. Nothing here mutates: a decline is not a button,
 * and sending money back belongs to the order that owes it. Rows therefore link
 * out — to the order console for the sale, and to the invoice for the document
 * — rather than re-implement either.
 *
 * ## Money has no single total
 *
 * Amounts are stored in the currency the listing was sold in, so the band
 * reports counts, and value per currency below them. Summing minor units
 * across currencies would produce a number that reconciles against nothing.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Payments')] class extends Component
{
    use WithPagination;

    /** The two halves of the ledger. */
    private const array TABS = ['attempts', 'refunds'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'tab', except: 'attempts')]
    public string $tab = 'attempts';

    #[Url(as: 'provider', except: 'all')]
    public string $provider = 'all';

    #[Url(as: 'mode', except: 'all')]
    public string $mode = 'all';

    /** A {@see PaymentStatus} value on the attempts tab, a {@see RefundStatus} one on refunds. */
    #[Url(as: 'status', except: 'all')]
    public string $status = 'all';

    public function mount(): void
    {
        $this->authorizeView();

        abort_unless(in_array($this->tab, self::TABS, true), 404);

        $this->assertFiltersAreReal();

        app(Seo::class)->set([
            'title' => 'Payments',
            'description' => 'Every payment attempt and refund on the platform.',
            'noindex' => true,
        ]);
    }

    /**
     * Switching queue resets the filters that only exist on the other half:
     * an attempt is captured or declined, a refund is issued or reversed, and
     * no refund row carries a gateway or a mode.
     */
    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, self::TABS, true), 404);

        $this->tab = $tab;
        $this->reset('provider', 'mode', 'status');
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'provider', 'mode', 'status');
        $this->resetPage();
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'provider', 'mode', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array{attempts: int, captured: int, declined: int, open: int, refunds: int, refunds_issued: int}
     */
    #[Computed]
    public function counts(): array
    {
        return [
            'attempts' => MarketplacePayment::query()->count(),
            'captured' => MarketplacePayment::query()->status(PaymentStatus::Succeeded)->count(),
            'declined' => MarketplacePayment::query()->status(PaymentStatus::Failed)->count(),
            'open' => MarketplacePayment::query()->status(PaymentStatus::Pending)->count(),
            'refunds' => MarketplaceRefund::query()->count(),
            'refunds_issued' => MarketplaceRefund::query()->status(RefundStatus::Succeeded)->count(),
        ];
    }

    /**
     * Captured, refunded and earned, grouped by the currency each was taken in.
     *
     * @return array<string, array{captured_cents: int, refunded_cents: int, commission_cents: int}>
     */
    #[Computed]
    public function valueByCurrency(): array
    {
        $captured = MarketplacePayment::query()
            ->status(PaymentStatus::Succeeded)
            ->selectRaw('currency, sum(amount_cents) as aggregate_cents')
            ->groupBy('currency')
            ->pluck('aggregate_cents', 'currency');

        $refunded = MarketplaceRefund::query()
            ->status(RefundStatus::Succeeded)
            ->selectRaw('currency, sum(amount_cents) as aggregate_cents')
            ->groupBy('currency')
            ->pluck('aggregate_cents', 'currency');

        $commission = MarketplaceOrder::query()
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded])
            ->selectRaw('currency, sum(commission_cents) as aggregate_cents')
            ->groupBy('currency')
            ->pluck('aggregate_cents', 'currency');

        $currencies = $captured->keys()->merge($refunded->keys())->merge($commission->keys())->unique();

        return $currencies->mapWithKeys(fn (string $currency) => [
            $currency => [
                'captured_cents' => (int) $captured->get($currency, 0),
                'refunded_cents' => (int) $refunded->get($currency, 0),
                'commission_cents' => (int) $commission->get($currency, 0),
            ],
        ])->all();
    }

    /**
     * The filtered page of ledger rows, shaped the same whichever half is on
     * screen so one table renders both.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->tab === 'attempts'
            ? $this->attemptRows()
            : $this->refundRows();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->provider !== 'all'
            || $this->mode !== 'all'
            || $this->status !== 'all';
    }

    /**
     * The statuses offered by the tab on screen.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return $this->tab === 'attempts'
            ? PaymentStatus::options()
            : RefundStatus::options();
    }

    /**
     * The filters arrive from the query string, so a value that is not a real
     * case of its enum is not a filter to ignore — it is a URL that does not
     * describe anything.
     */
    protected function assertFiltersAreReal(): void
    {
        if ($this->provider !== 'all') {
            abort_unless(PaymentProvider::tryFrom($this->provider) !== null, 404);
        }

        if ($this->mode !== 'all') {
            abort_unless(PaymentMode::tryFrom($this->mode) !== null, 404);
        }

        if ($this->status !== 'all') {
            $enum = $this->tab === 'attempts' ? PaymentStatus::class : RefundStatus::class;

            abort_unless($enum::tryFrom($this->status) !== null, 404);
        }
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function attemptRows(): LengthAwarePaginator
    {
        return MarketplacePayment::query()
            ->with(['order:id,order_number,user_id,paid_at', 'order.buyer:id,name'])
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when($this->provider !== 'all', fn (Builder $query) => $query->provider(PaymentProvider::from($this->provider)))
            ->when($this->mode !== 'all', fn (Builder $query) => $query->mode(PaymentMode::from($this->mode)))
            ->when($this->status !== 'all', fn (Builder $query) => $query->status(PaymentStatus::from($this->status)))
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MarketplacePayment $payment): array => [
                'id' => $payment->id,
                'order_id' => $payment->marketplace_order_id,
                'date' => $payment->occurredAt(),
                'order_number' => $payment->order->order_number,
                'buyer' => $payment->order->buyer->name,
                'method' => $payment->provider->label().' · '.$payment->mode->label(),
                'status' => $payment->status->label(),
                'badge' => $payment->status->badgeColor(),
                'amount' => $payment->formattedAmount(),
                'reference' => $payment->reference,
                'note' => $payment->failure_reason,
                'invoice' => $payment->order->invoiceNumber(),
            ]);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function refundRows(): LengthAwarePaginator
    {
        return MarketplaceRefund::query()
            ->with(['order:id,order_number,user_id,paid_at', 'order.buyer:id,name'])
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when($this->status !== 'all', fn (Builder $query) => $query->status(RefundStatus::from($this->status)))
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MarketplaceRefund $refund): array => [
                'id' => $refund->id,
                'order_id' => $refund->marketplace_order_id,
                'date' => $refund->occurredAt(),
                'order_number' => $refund->order->order_number,
                'buyer' => $refund->order->buyer->name,
                'method' => $refund->author?->name ?? __('Platform'),
                'status' => $refund->status->label(),
                'badge' => $refund->status->badgeColor(),
                'amount' => '−'.$refund->formattedAmount(),
                'reference' => $refund->reason,
                'note' => null,
                'invoice' => $refund->order->invoiceNumber(),
            ]);
    }

    protected function authorizeView(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::ViewAnySales), 403);
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Administration"
        title="Payments"
        description="Every payment attempt and every refund on the platform, across accounts and gateways."
        :breadcrumbs="[['label' => __('Administration')], ['label' => __('Payments')]]"
    />

    <x-app.ui.body>
        {{-- ============================================================= --
         | Ledger metrics
         --}}
        <section aria-labelledby="payment-metrics-heading">
            <h2 id="payment-metrics-heading" class="sr-only">{{ __('Payment metrics') }}</h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-app.ui.stat
                    label="Attempts"
                    :value="number_format($this->counts['attempts'])"
                    icon="credit-card"
                    tone="primary"
                    :hint="__(':count awaiting an outcome', ['count' => number_format($this->counts['open'])])"
                />

                <x-app.ui.stat
                    label="Captured"
                    :value="number_format($this->counts['captured'])"
                    icon="check-circle"
                    tone="success"
                    hint="Attempts that moved money"
                />

                <x-app.ui.stat
                    label="Declined"
                    :value="number_format($this->counts['declined'])"
                    icon="x-circle"
                    :tone="$this->counts['declined'] > 0 ? 'danger' : 'primary'"
                    hint="Rejected by the instrument"
                />

                <x-app.ui.stat
                    label="Refunds"
                    :value="number_format($this->counts['refunds'])"
                    icon="banknotes"
                    :tone="$this->counts['refunds'] > 0 ? 'warning' : 'primary'"
                    :hint="__(':count issued', ['count' => number_format($this->counts['refunds_issued'])])"
                />
            </div>
        </section>

        @if ($this->valueByCurrency !== [])
            <div class="sui-card overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="border-b border-sui-hairline px-5 py-3 text-left sui-label">{{ __('Value by currency') }}</caption>

                    <thead class="border-b border-sui-hairline bg-sui-well">
                        <tr>
                            <th scope="col" class="px-5 py-3 sui-table-head">{{ __('Currency') }}</th>
                            <th scope="col" class="px-5 py-3 text-right sui-table-head">{{ __('Captured') }}</th>
                            <th scope="col" class="px-5 py-3 text-right sui-table-head">{{ __('Refunded') }}</th>
                            <th scope="col" class="px-5 py-3 text-right sui-table-head">{{ __('Commission') }}</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-sui-hairline">
                        @foreach ($this->valueByCurrency as $currency => $value)
                            <tr wire:key="currency-{{ $currency }}">
                                <th scope="row" class="px-5 py-3 text-left font-mono text-xs font-medium text-ink-900">{{ $currency }}</th>

                                <td class="px-5 py-3 text-right tabular-nums text-ink-900">
                                    {{ \App\Models\MarketplaceProduct::symbolFor($currency).number_format($value['captured_cents'] / 100, 2) }}
                                </td>

                                <td class="px-5 py-3 text-right tabular-nums text-ink-500">
                                    {{ \App\Models\MarketplaceProduct::symbolFor($currency).number_format($value['refunded_cents'] / 100, 2) }}
                                </td>

                                <td class="px-5 py-3 text-right tabular-nums text-ink-500">
                                    {{ \App\Models\MarketplaceProduct::symbolFor($currency).number_format($value['commission_cents'] / 100, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ============================================================= --
         | The ledger
         --}}
        <x-app.ui.section :padded="false">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-sui-hairline px-5">
                <nav class="-mb-px flex gap-1" aria-label="{{ __('Ledger sections') }}">
                    @foreach (['attempts' => __('Payment attempts'), 'refunds' => __('Refunds')] as $key => $label)
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
                        </button>
                    @endforeach
                </nav>

                <div class="flex flex-wrap items-center gap-2 py-2.5">
                    <label for="payment-search" class="sr-only">{{ __('Search the ledger') }}</label>
                    <input
                        id="payment-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="{{ __('Reference or order number') }}"
                        class="sui-control w-56 !py-1.5 text-xs"
                        data-test="payment-search"
                    >

                    @if ($tab === 'attempts')
                        <label for="payment-provider" class="sr-only">{{ __('Filter by gateway') }}</label>
                        <select id="payment-provider" wire:model.live="provider" class="sui-control w-36 !py-1.5 text-xs" data-test="payment-provider">
                            <option value="all">{{ __('Any gateway') }}</option>
                            @foreach (\App\Enums\PaymentProvider::options() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>

                        <label for="payment-mode" class="sr-only">{{ __('Filter by mode') }}</label>
                        <select id="payment-mode" wire:model.live="mode" class="sui-control w-32 !py-1.5 text-xs" data-test="payment-mode">
                            <option value="all">{{ __('Any mode') }}</option>
                            @foreach (\App\Enums\PaymentMode::options() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    @endif

                    <label for="payment-status" class="sr-only">{{ __('Filter by status') }}</label>
                    <select id="payment-status" wire:model.live="status" class="sui-control w-36 !py-1.5 text-xs" data-test="payment-status">
                        <option value="all">{{ __('Any status') }}</option>
                        @foreach ($this->statusOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>

                    @if ($this->hasFilters)
                        <flux:button size="sm" variant="ghost" wire:click="clearFilters" icon="arrow-path">
                            {{ __('Clear') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            @if ($this->rows->isEmpty())
                <div class="p-5">
                    <x-app.ui.empty-state
                        icon="banknotes"
                        :title="$tab === 'attempts' ? __('No payment attempts match this view') : __('No refunds match this view')"
                        :description="__('Attempts appear here as buyers check out. Clear the filters to see the whole ledger.')"
                    >
                        @if ($this->hasFilters)
                            <flux:button size="sm" wire:click="clearFilters" icon="arrow-path">
                                {{ __('Clear filters') }}
                            </flux:button>
                        @endif
                    </x-app.ui.empty-state>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="border-b border-sui-hairline bg-sui-well">
                            <tr>
                                <th scope="col" class="px-5 py-3 sui-table-head">{{ __('When') }}</th>
                                <th scope="col" class="px-5 py-3 sui-table-head">{{ __('Order') }}</th>
                                <th scope="col" class="hidden px-5 py-3 sui-table-head lg:table-cell">{{ __('Buyer') }}</th>
                                <th scope="col" class="hidden px-5 py-3 sui-table-head sm:table-cell">{{ $tab === 'attempts' ? __('Method') : __('Issued by') }}</th>
                                <th scope="col" class="px-5 py-3 sui-table-head">{{ __('Status') }}</th>
                                <th scope="col" class="px-5 py-3 text-right sui-table-head">{{ __('Amount') }}</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-sui-hairline">
                            @foreach ($this->rows as $row)
                                <tr wire:key="ledger-{{ $tab }}-{{ $row['id'] }}" class="transition duration-150 ease-soft hover:bg-sui-hover">
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <p class="text-sm text-ink-900">{{ $row['date']->format('j M Y') }}</p>
                                        <p class="mt-0.5 font-mono text-2xs text-ink-400">{{ $row['date']->format('H:i') }}</p>
                                    </td>

                                    <td class="px-5 py-3.5">
                                        <a
                                            href="{{ route('admin.orders', ['q' => $row['order_number']]) }}"
                                            wire:navigate
                                            class="focus-ring font-mono text-2xs text-sui-ink underline-offset-4 hover:underline"
                                        >
                                            {{ $row['order_number'] }}
                                        </a>

                                        @if ($row['reference'])
                                            <p class="mt-0.5 max-w-52 truncate font-mono text-2xs text-ink-400">{{ $row['reference'] }}</p>
                                        @endif

                                        @if ($row['note'])
                                            <p class="mt-0.5 max-w-52 truncate text-2xs text-sui-danger">{{ $row['note'] }}</p>
                                        @endif

                                        @if ($row['invoice'] !== null)
                                            <a
                                                href="{{ route('purchases.invoice', ['order' => $row['order_id']]) }}"
                                                class="focus-ring mt-1 inline-block font-mono text-2xs text-ink-400 uppercase tracking-[0.08em] underline-offset-4 hover:underline"
                                            >
                                                {{ $row['invoice'] }}
                                            </a>
                                        @endif
                                    </td>

                                    <td class="hidden px-5 py-3.5 text-xs text-ink-500 lg:table-cell">
                                        {{ $row['buyer'] }}
                                    </td>

                                    <td class="hidden px-5 py-3.5 text-xs text-ink-500 sm:table-cell">
                                        {{ $row['method'] }}
                                    </td>

                                    <td class="px-5 py-3.5">
                                        <flux:badge :color="$row['badge']" size="sm">{{ $row['status'] }}</flux:badge>
                                    </td>

                                    <td class="px-5 py-3.5 text-right text-sm whitespace-nowrap tabular-nums text-ink-900">
                                        {{ $row['amount'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-sui-hairline px-5 py-4">
                    <x-app.ui.pagination :paginator="$this->rows" label="{{ __('Payment ledger pagination') }}" />
                </div>
            @endif
        </x-app.ui.section>
    </x-app.ui.body>
</div>
