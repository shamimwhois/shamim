<?php

use App\Actions\Marketplace\SaveBillingProfile;
use App\Enums\OrderStatus;
use App\Models\BillingProfile;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceRefund;
use App\Support\BillingCountry;
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
 * The signed-in account's purchasing hub: orders, the money behind them, and
 * the identity printed on the receipts.
 *
 * ## Three views, one page
 *
 * Orders, payments and billing are the same question asked at three depths —
 * what did I buy, how did it get paid, who was it billed to — so they share a
 * route and the tab is carried in the URL. A shared link therefore opens the
 * tab it points at, and the guard on `?tab=` is a whitelist rather than a
 * fallback because the query string is client input.
 *
 * ## Where each list comes from
 *
 * Orders are derived the way entitlement is: the `forBuyer` scope pins the list
 * to the session's account before anything else runs, and nothing a buyer owns
 * needs a second record to be shown here. The payment ledger is derived the
 * same way — every attempt and every refund reaches the buyer through the order
 * that carries it, so the ledger can never list another account's instrument.
 *
 * ## Billing is written, then copied
 *
 * Editing this form changes future invoices only. {@see MarketplaceOrder}
 * keeps a snapshot of the billing identity taken at the moment of purchase, so
 * a receipt already issued cannot be restated by a later rename or address
 * change. The snapshot, not this table, is what the invoice renders.
 */
new #[Title('Purchases')] #[Layout('layouts::feed')] class extends Component
{
    use WithPagination;

    /** The three views this page owns. */
    private const array TABS = ['orders', 'payments', 'billing'];

    /** Order-status chips, in the order they are offered. */
    private const array STATUS_FILTERS = [
        'all' => 'All orders',
        'owned' => 'Owned',
        'awaiting' => 'Awaiting payment',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
    ];

    /** Which half of the ledger the payments tab is showing. */
    private const array KINDS = ['payments', 'refunds'];

    #[Url(as: 'tab', except: 'orders')]
    public string $tab = 'orders';

    #[Url(as: 'status', except: 'all')]
    public string $status = 'all';

    #[Url(as: 'kind', except: 'payments')]
    public string $kind = 'payments';

    /**
     * The billing profile as a plain array so the form can be rendered and
     * validated without an Eloquent model in front of it.
     *
     * @var array<string, string|null>
     */
    public array $billing = [];

    public function mount(): void
    {
        abort_unless(in_array($this->tab, self::TABS, true), 404);
        abort_unless(in_array($this->kind, self::KINDS, true), 404);
        abort_unless(array_key_exists($this->status, self::STATUS_FILTERS), 404);

        $this->loadBillingForm();
    }

    /**
     * Any change of view or filter sends every list back to its first page.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['tab', 'status', 'kind'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Switch view. Re-checks the whitelist because `wire:click` is client input.
     */
    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, self::TABS, true), 404);

        $this->tab = $tab;
    }

    /**
     * Filter the order list by status.
     */
    public function filterStatus(string $status): void
    {
        abort_unless(array_key_exists($status, self::STATUS_FILTERS), 404);

        $this->status = $status;
    }

    /**
     * Switch the ledger between payment attempts and refunds.
     */
    public function showKind(string $kind): void
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        $this->kind = $kind;
    }

    /**
     * Store the billing identity future invoices will be issued against.
     */
    public function saveBilling(SaveBillingProfile $saveBillingProfile): void
    {
        $this->billing = array_map(
            fn (?string $value): ?string => ($value === '' || $value === null) ? null : $value,
            $this->billing,
        );

        // One profile means one rule set: the rules live on the action.
        $validated = $this->validate(SaveBillingProfile::rules());

        $saveBillingProfile->handle(auth()->user(), $validated['billing']);

        Flux::toast(variant: 'success', text: __('Your billing details were saved.'));
    }

    /**
     * The orders this account placed, filtered by status.
     *
     * @return LengthAwarePaginator<int, MarketplaceOrder>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->ordersQuery()
            ->latest('created_at')
            ->paginate(10)
            ->withQueryString();
    }

    /**
     * The payment ledger: attempts or refunds, newest first.
     *
     * Both halves are reduced to the same row shape here, so the table below
     * renders one list rather than two versions of itself, and every row still
     * carries the order it belongs to.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function ledger(): LengthAwarePaginator
    {
        return $this->kind === 'payments'
            ? $this->attemptRows()
            : $this->refundRows();
    }

    /**
     * Whether the billing tab has a stored profile behind the form.
     */
    public function hasBillingProfile(): bool
    {
        return BillingProfile::forAccount(auth()->user()) !== null;
    }

    /**
     * The account's orders with the active status group applied.
     *
     * The groups are the ones a buyer actually asks about — what I own, what is
     * still waiting for money, what was withdrawn, what came back — and each is
     * a set of {@see OrderStatus} values rather than a single status.
     *
     * @return Builder<MarketplaceOrder>
     */
    protected function ordersQuery(): Builder
    {
        $query = MarketplaceOrder::query()
            ->forBuyer(auth()->id())
            ->with('currentPayment');

        match ($this->status) {
            'owned' => $query->entitling(),
            'awaiting' => $query->whereIn('status', [OrderStatus::Pending, OrderStatus::Processing]),
            'cancelled' => $query->where('status', OrderStatus::Cancelled),
            'refunded' => $query->whereIn('status', [OrderStatus::Refunded, OrderStatus::PartiallyRefunded]),
            default => null,
        };

        return $query;
    }

    /**
     * Seed the billing form from the stored profile, or from the account when
     * this buyer has never set one up.
     */
    protected function loadBillingForm(): void
    {
        $profile = BillingProfile::forAccount(auth()->user());

        foreach (array_keys($this->billingFields()) as $column) {
            $this->billing[$column] = $profile?->{$column}
                ?? match ($column) {
                    'legal_name' => auth()->user()->name,
                    'email' => auth()->user()->email,
                    default => null,
                };
        }
    }

    /**
     * Form fields in display order: label, whether the invoice needs them, and
     * the hint that explains a field nobody has seen on a form before.
     *
     * @return array<string, array{label: string, required: bool, hint: string}>
     */
    public function billingFields(): array
    {
        return [
            'legal_name' => ['label' => __('Legal name'), 'required' => true, 'hint' => __('The name the invoice is addressed to.')],
            'company' => ['label' => __('Company'), 'required' => false, 'hint' => __('Shown under the name when you buy for an organisation.')],
            'tax_id' => ['label' => __('Tax ID'), 'required' => false, 'hint' => __('VAT, GST or the equivalent registration number.')],
            'email' => ['label' => __('Billing email'), 'required' => true, 'hint' => __('Where the seller can reach you about this purchase.')],
            'phone' => ['label' => __('Phone'), 'required' => false, 'hint' => __('')],
            'address_line1' => ['label' => __('Address line 1'), 'required' => true, 'hint' => __('')],
            'address_line2' => ['label' => __('Address line 2'), 'required' => false, 'hint' => __('')],
            'city' => ['label' => __('City'), 'required' => true, 'hint' => __('')],
            'postal_code' => ['label' => __('Postal code'), 'required' => false, 'hint' => __('')],
            'country' => ['label' => __('Country'), 'required' => false, 'hint' => __('')],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function attemptRows(): LengthAwarePaginator
    {
        return MarketplacePayment::query()
            ->forBuyer(auth()->id())
            ->with('order:id,order_number')
            ->latest('created_at')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (MarketplacePayment $payment): array => [
                'id' => $payment->id,
                'date' => $payment->occurredAt(),
                'order_id' => $payment->marketplace_order_id,
                'order_number' => $payment->order->order_number,
                'method' => $payment->provider->label().' · '.$payment->mode->label(),
                'status' => $payment->status->label(),
                'badge' => $payment->status->badgeColor(),
                'amount' => $payment->formattedAmount(),
                'reference' => $payment->reference ?? $payment->failure_reason,
            ]);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function refundRows(): LengthAwarePaginator
    {
        return MarketplaceRefund::query()
            ->forBuyer(auth()->id())
            ->with('order:id,order_number')
            ->latest('created_at')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (MarketplaceRefund $refund): array => [
                'id' => $refund->id,
                'date' => $refund->occurredAt(),
                'order_id' => $refund->marketplace_order_id,
                'order_number' => $refund->order->order_number,
                'method' => $refund->reason ?? __('Refund'),
                'status' => $refund->status->label(),
                'badge' => $refund->status->badgeColor(),
                'amount' => '−'.$refund->formattedAmount(),
                'reference' => null,
            ]);
    }
}; ?>

@php
    $statusLabels = [
        'all' => __('All orders'),
        'owned' => __('Owned'),
        'awaiting' => __('Awaiting payment'),
        'cancelled' => __('Cancelled'),
        'refunded' => __('Refunded'),
    ];

    $kindLabels = [
        'payments' => __('Payment attempts'),
        'refunds' => __('Refunds'),
    ];

    $countryOptions = BillingCountry::options();
@endphp

<x-log.frame active="marketplace" data-test="purchases">
    <x-slot:header>
        <div class="dn-header">
            <div class="dn-page-head">
                <h1 class="dn-page-title">{{ __('Purchases') }}</h1>
                <p class="dn-page-meta">{{ __('What this account has bought, paid for and been refunded.') }}</p>
            </div>

            {{-- The same rail the settings tabs use: three views of one panel. --}}
            <nav class="dn-filters" aria-label="{{ __('Purchase sections') }}">
                @foreach (['orders' => __('Orders'), 'payments' => __('Payments'), 'billing' => __('Billing')] as $key => $label)
                    <button
                        type="button"
                        wire:click="showTab('{{ $key }}')"
                        class="dn-filter {{ $tab === $key ? 'is-active' : '' }}"
                        @if ($tab === $key) aria-current="page" @endif
                    >{{ $label }}</button>
                @endforeach
            </nav>
        </div>
    </x-slot:header>

    <div class="dn-settings">
        {{-- ============================================================= --
         | Orders
         --}}
        @if ($tab === 'orders')
            <section class="dn-settings-section" aria-labelledby="purchases-orders">
                <h2 id="purchases-orders" class="dn-settings-title">{{ __('Orders') }}</h2>

                @if ($this->orders->isEmpty())
                    <p class="dn-settings-note" style="margin-top: 8px;">
                        {{ $status === 'all' ? __('Nothing bought yet.') : __('No orders match this filter.') }}
                    </p>

                    <p style="margin-top: 10px;">
                        @if ($status === 'all')
                            <a href="{{ route('marketplace.index') }}" wire:navigate class="dn-link">
                                {{ __('Browse the marketplace') }}
                            </a>
                        @else
                            <button type="button" wire:click="filterStatus('all')" class="dn-link" style="background: none; border: 0; padding: 0; cursor: pointer;">
                                {{ __('Show all orders') }}
                            </button>
                        @endif
                    </p>
                @else
                    <div class="dn-categories" role="group" aria-label="{{ __('Filter orders by status') }}">
                        <div class="dn-filter-row">
                            @foreach ($statusLabels as $value => $label)
                                <button
                                    type="button"
                                    wire:click="filterStatus('{{ $value }}')"
                                    class="dn-chip {{ $status === $value ? 'is-on' : '' }}"
                                    @if ($status === $value) aria-pressed="true" @endif
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="dn-fields" style="margin-top: 12px;">
                        @foreach ($this->orders as $order)
                            <a
                                href="{{ route('purchases.show', $order) }}"
                                wire:navigate
                                wire:key="purchase-{{ $order->id }}"
                                class="dn-field"
                                style="text-decoration: none;"
                            >
                                <span class="dn-field-key">{{ $order->created_at->format('M j') }}</span>

                                <span style="display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 0;">
                                    <span style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <span style="font-size: 14px; font-weight: 500; color: var(--dn-ink);">{{ $order->product_name }}</span>
                                        <span class="dn-field-key" style="margin-left: 6px;">{{ $order->order_number }}</span>
                                    </span>

                                    <span style="flex: none; display: inline-flex; align-items: center; gap: 8px;">
                                        <span class="dn-status-chip">{{ $order->status->label() }}</span>
                                        <span style="font-family: var(--dn-mono); font-size: 13px;">
                                            {{ $order->price_cents === 0 ? __('Free') : $order->formattedAmount() }}
                                        </span>
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    </div>

                    <div style="margin-top: 12px;">
                        <x-app.ui.pagination :paginator="$this->orders" label="{{ __('Purchases pagination') }}" />
                    </div>
                @endif
            </section>

        {{-- ========================================================= --}}
        @elseif ($tab === 'payments')
            <section class="dn-settings-section" aria-labelledby="purchases-payments">
                <h2 id="purchases-payments" class="dn-settings-title">
                    {{ $kind === 'payments' ? __('Payment attempts') : __('Refunds') }}
                </h2>

                <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 8px;">
                    <div class="dn-categories" role="group" aria-label="{{ __('Choose which half of the ledger to show') }}">
                        <div class="dn-filter-row">
                            @foreach ($kindLabels as $value => $label)
                                <button
                                    type="button"
                                    wire:click="showKind('{{ $value }}')"
                                    class="dn-chip {{ $kind === $value ? 'is-on' : '' }}"
                                    @if ($kind === $value) aria-pressed="true" @endif
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <p class="dn-field-key" aria-live="polite">
                        {{ trans_choice(':count entry|:count entries', $this->ledger->total()) }}
                    </p>
                </div>

                @if ($this->ledger->isEmpty())
                    <p class="dn-settings-note" style="margin-top: 8px;">
                        {{ $kind === 'payments' ? __('No payment attempts yet.') : __('Nothing refunded yet.') }}
                    </p>

                    <p style="margin-top: 10px;">
                        <button type="button" wire:click="showTab('orders')" class="dn-link" style="background: none; border: 0; padding: 0; cursor: pointer;">
                            {{ __('Back to orders') }}
                        </button>
                    </p>
                @else
                    <div class="dn-fields" style="margin-top: 12px;">
                        @foreach ($this->ledger as $row)
                            <div class="dn-field" wire:key="ledger-{{ $kind }}-{{ $row['id'] }}">
                                <span class="dn-field-key">{{ \Illuminate\Support\Str::of($row['date'])->before(',')->toString() }}</span>

                                <span style="display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 0;">
                                    <span style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <a href="{{ route('purchases.show', $row['order_id']) }}" wire:navigate class="dn-link" style="font-family: var(--dn-mono); font-size: 12px;">{{ $row['order_number'] }}</a>
                                        <span class="dn-field-key" style="margin-left: 6px;">{{ $row['method'] }}</span>
                                        @if ($row['reference'])
                                            <span class="dn-field-key" style="margin-left: 6px;">{{ \Illuminate\Support\Str::limit($row['reference'], 18) }}</span>
                                        @endif
                                    </span>

                                    <span style="flex: none; display: inline-flex; align-items: center; gap: 8px;">
                                        <span class="dn-status-chip">{{ $row['status'] }}</span>
                                        <span style="font-family: var(--dn-mono); font-size: 13px;">{{ $row['amount'] }}</span>
                                    </span>
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <div style="margin-top: 12px;">
                        <x-app.ui.pagination :paginator="$this->ledger" label="{{ __('Payment history pagination') }}" />
                    </div>
                @endif
            </section>

        {{-- ========================================================= --}}
        @else
            <section class="dn-settings-section" aria-labelledby="purchases-billing">
                <h2 id="purchases-billing" class="dn-settings-title">{{ __('Billing details') }}</h2>
                <p class="dn-settings-note">
                    {{ __('Invoices are issued to the details on file when an order is placed. Change them here and the next receipt carries the change; every receipt already issued keeps the wording it was printed with.') }}
                </p>

                <form wire:submit="saveBilling" class="dn-fields" style="margin-top: 16px;">
                    @foreach ($this->billingFields() as $column => $field)
                        <div class="dn-field">
                            <label class="dn-field-key" for="billing-{{ $column }}">
                                {{ $field['label'] }}@if (! $field['required']) <span title="{{ __('optional') }}">·</span>@endif
                            </label>

                            @if ($column === 'country')
                                <select id="billing-country" wire:model="billing.country" class="dn-input">
                                    <option value="">{{ __('Not specified') }}</option>
                                    @foreach ($countryOptions as $code => $name)
                                        <option value="{{ $code }}" @selected($billing[$column] === $code)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input
                                    id="billing-{{ $column }}"
                                    type="{{ $column === 'email' ? 'email' : 'text' }}"
                                    wire:model="billing.{{ $column }}"
                                    maxlength="{{ in_array($column, ['tax_id', 'phone', 'postal_code'], true) ? 32 : 255 }}"
                                    class="dn-input"
                                >
                            @endif

                            @if ($field['hint'] !== '')
                                <p class="dn-field-key" style="grid-column: 2; margin: 0;">{{ $field['hint'] }}</p>
                            @endif

                            @error("billing.$column")
                                <p class="dn-field-key" style="grid-column: 2; margin: 0; color: var(--dn-destructive);">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach

                    <div class="dn-field" style="justify-content: flex-end; display: flex;">
                        <button type="submit" class="dn-btn" data-test="billing-save">
                            {{ $this->hasBillingProfile() ? __('Update billing details') : __('Save billing details') }}
                        </button>
                    </div>
                </form>

                @if (! $this->hasBillingProfile())
                    <p class="dn-settings-note" style="margin-top: 10px;">
                        {{ __('Purchases you already made keep the details they were billed with.') }}
                    </p>
                @endif
            </section>
        @endif
    </div>
</x-log.frame>
