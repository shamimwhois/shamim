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
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
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
new #[Title('Purchases')] class extends Component
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

        $validated = $this->validate($this->billingRules());

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
     * @return array<string, array{attribute: string, rules: array<int, mixed>}>
     */
    protected function billingRules(): array
    {
        return [
            'billing.legal_name' => ['required', 'string', 'max:255'],
            'billing.company' => ['nullable', 'string', 'max:255'],
            'billing.tax_id' => ['nullable', 'string', 'max:32'],
            'billing.email' => ['required', 'email', 'max:255'],
            'billing.phone' => ['nullable', 'string', 'max:32'],
            'billing.address_line1' => ['required', 'string', 'max:255'],
            'billing.address_line2' => ['nullable', 'string', 'max:255'],
            'billing.city' => ['required', 'string', 'max:120'],
            'billing.postal_code' => ['nullable', 'string', 'max:16'],
            'billing.country' => ['nullable', 'string', Rule::in(array_keys(BillingCountry::options()))],
        ];
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
    $tabLabels = [
        'orders' => __('Orders'),
        'payments' => __('Payments'),
        'billing' => __('Billing'),
    ];

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

<div class="v96 mx-auto w-full max-w-4xl px-4 py-10 sm:px-6 lg:py-14">
    <p class="v96-eyebrow">{{ __('Purchases') }}</p>

    {{-- ============================================================= --
     | Views
     --}}
    <div class="mt-4 border-b border-ink-200 dark:border-ink-800">
        <nav aria-label="{{ __('Purchase sections') }}" class="-mb-px flex gap-1 overflow-x-auto">
            @foreach ($tabLabels as $key => $label)
                <button
                    type="button"
                    wire:click="showTab('{{ $key }}')"
                    @class([
                        'focus-ring v96-mono shrink-0 border-b-2 px-4 py-4 text-[13px] font-medium uppercase tracking-[0.08em] transition-colors',
                        'border-(--v96-purple) font-bold text-(--v96-ink) dark:border-(--v96-purple-lift)' => $tab === $key,
                        'border-transparent text-(--v96-body) hover:border-[#D8CCF4] hover:text-[#211A31] dark:text-ink-400 dark:hover:border-[#3A3152] dark:hover:text-ink-200' => $tab !== $key,
                    ])
                    @if ($tab === $key) aria-current="page" @endif
                >
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ============================================================= --
     | Orders
     --}}
    @if ($tab === 'orders')
        @if ($this->orders->isEmpty())
            <div class="v96-card mt-4 !h-auto p-10 text-center">
                <p class="text-sm text-ink-500 dark:text-ink-400">
                    {{ $status === 'all' ? __('Nothing bought yet.') : __('No orders match this filter.') }}
                </p>

                @if ($status === 'all')
                    <a href="{{ route('marketplace.index') }}" wire:navigate class="focus-ring v96-btn mt-5 inline-flex">
                        {{ __('Browse the marketplace') }}
                    </a>
                @else
                    <button type="button" wire:click="filterStatus('all')" class="focus-ring v96-btn v96-btn--ghost mt-5 inline-flex">
                        {{ __('Show all orders') }}
                    </button>
                @endif
            </div>
        @else
            <div class="mt-6 flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Filter orders by status') }}">
                @foreach ($statusLabels as $value => $label)
                    <button
                        type="button"
                        wire:click="filterStatus('{{ $value }}')"
                        class="v96-chip v96-chip-sm"
                        @if ($status === $value) aria-pressed="true" @endif
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="mt-4 space-y-3">
                @foreach ($this->orders as $order)
                    <a
                        href="{{ route('purchases.show', $order) }}"
                        wire:navigate
                        class="v96-card flex flex-wrap items-center gap-x-4 gap-y-2 !h-auto p-5 transition hover:ring-1 hover:ring-sui-accent/40"
                        wire:key="purchase-{{ $order->id }}"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink-900 dark:text-ink-50">{{ $order->product_name }}</p>

                            <p class="mt-0.5 font-mono text-2xs text-ink-400">{{ $order->order_number }}</p>
                        </div>

                        <span class="text-xs tabular-nums text-ink-500 dark:text-ink-400">
                            {{ $order->price_cents === 0 ? __('Free') : $order->formattedAmount() }}
                        </span>

                        <flux:badge :color="$order->status->badgeColor()" size="sm">
                            {{ $order->status->label() }}
                        </flux:badge>

                        <span class="text-2xs text-ink-400">{{ $order->created_at->format('j M Y') }}</span>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                <x-app.ui.pagination :paginator="$this->orders" label="{{ __('Purchases pagination') }}" />
            </div>
        @endif
    @elseif ($tab === 'payments')
        {{-- ========================================================= --
         | Ledger
         --}}
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Choose which half of the ledger to show') }}">
                @foreach ($kindLabels as $value => $label)
                    <button
                        type="button"
                        wire:click="showKind('{{ $value }}')"
                        class="v96-chip v96-chip-sm"
                        @if ($kind === $value) aria-pressed="true" @endif
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <p class="v96-mono text-2xs uppercase tracking-[0.13em] text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count entry|:count entries', $this->ledger->total()) }}
            </p>
        </div>

        @if ($this->ledger->isEmpty())
            <div class="v96-card mt-4 !h-auto p-10 text-center">
                <p class="text-sm text-ink-500 dark:text-ink-400">
                    {{ $kind === 'payments' ? __('No payment attempts yet.') : __('Nothing refunded yet.') }}
                </p>

                <a href="{{ route('purchases.index') }}" wire:navigate class="focus-ring v96-btn v96-btn--ghost mt-5 inline-flex">
                    {{ __('Back to orders') }}
                </a>
            </div>
        @else
            <div class="v96-card mt-4 !h-auto overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ __('Payment history') }}</caption>
                    <thead class="border-b border-ink-200 dark:border-ink-800">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-left font-mono text-2xs uppercase tracking-widest font-normal text-ink-400">{{ __('Date') }}</th>
                            <th scope="col" class="px-5 py-3 text-left font-mono text-2xs uppercase tracking-widest font-normal text-ink-400">{{ __('Order') }}</th>
                            <th scope="col" class="hidden px-5 py-3 text-left font-mono text-2xs uppercase tracking-widest font-normal text-ink-400 sm:table-cell">{{ $kind === 'payments' ? __('Method') : __('Reason') }}</th>
                            <th scope="col" class="px-5 py-3 text-left font-mono text-2xs uppercase tracking-widest font-normal text-ink-400">{{ __('Status') }}</th>
                            <th scope="col" class="px-5 py-3 text-right font-mono text-2xs uppercase tracking-widest font-normal text-ink-400">{{ __('Amount') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($this->ledger as $row)
                            <tr class="border-b border-ink-100 last:border-0 dark:border-ink-900" wire:key="ledger-{{ $kind }}-{{ $row['id'] }}">
                                <td class="px-5 py-3 whitespace-nowrap text-xs text-ink-500 dark:text-ink-400">
                                    {{ $row['date']->format('j M Y, H:i') }}
                                </td>

                                <td class="px-5 py-3">
                                    <a
                                        href="{{ route('purchases.show', $row['order_id']) }}"
                                        wire:navigate
                                        class="focus-ring font-mono text-2xs text-ink-900 underline-offset-4 hover:underline dark:text-ink-50"
                                    >
                                        {{ $row['order_number'] }}
                                    </a>

                                    @if ($row['reference'])
                                        <p class="mt-1 max-w-48 truncate font-mono text-2xs text-ink-400">{{ $row['reference'] }}</p>
                                    @endif
                                </td>

                                <td class="hidden px-5 py-3 text-xs text-ink-500 dark:text-ink-400 sm:table-cell">
                                    {{ $row['method'] }}
                                </td>

                                <td class="px-5 py-3">
                                    <flux:badge :color="$row['badge']" size="sm">{{ $row['status'] }}</flux:badge>
                                </td>

                                <td class="px-5 py-3 text-right whitespace-nowrap tabular-nums text-ink-900 dark:text-ink-50">
                                    {{ $row['amount'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6">
                <x-app.ui.pagination :paginator="$this->ledger" label="{{ __('Payment history pagination') }}" />
            </div>
        @endif
    @else
        {{-- ========================================================= --
         | Billing identity
         --}}
        <form wire:submit="saveBilling" class="mt-6 space-y-6">
            <div class="v96-card !h-auto p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-ink-900 dark:text-ink-50">{{ __('Billing details') }}</h2>

                <p class="mt-2 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ __('Invoices are issued to the details on file when an order is placed. Change them here and the next receipt carries the change; every receipt already issued keeps the wording it was printed with.') }}
                </p>

                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    @foreach ($this->billingFields() as $column => $field)
                        @if ($column === 'country')
                            <div class="sm:col-span-2">
                                <label for="billing-country" class="sui-label">
                                    {{ $field['label'] }}

                                    @if (! $field['required'])
                                        <span class="font-normal normal-case tracking-normal text-ink-400">{{ __('(optional)') }}</span>
                                    @endif
                                </label>

                                <div class="relative">
                                    <select id="billing-country" wire:model="billing.country" class="v96-select mt-2 w-full appearance-none pr-9">
                                        <option value="">{{ __('Not specified') }}</option>
                                        @foreach ($countryOptions as $code => $name)
                                            <option value="{{ $code }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                                </div>

                                @error("billing.$column")
                                    <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                                @enderror
                            </div>
                        @else
                            <div @class(['sm:col-span-2' => in_array($column, ['legal_name', 'address_line1', 'address_line2'], true)])>
                                <label for="billing-{{ $column }}" class="sui-label">
                                    {{ $field['label'] }}

                                    @if (! $field['required'])
                                        <span class="font-normal normal-case tracking-normal text-ink-400">{{ __('(optional)') }}</span>
                                    @endif
                                </label>

                                <input
                                    id="billing-{{ $column }}"
                                    type="{{ $column === 'email' ? 'email' : 'text' }}"
                                    wire:model="billing.{{ $column }}"
                                    maxlength="{{ in_array($column, ['tax_id', 'phone', 'postal_code'], true) ? 32 : 255 }}"
                                    class="focus-ring mt-2 min-h-10 w-full rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm text-ink-900 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-50"
                                >

                                @if ($field['hint'] !== '')
                                    <p class="mt-1 text-2xs text-ink-400 dark:text-ink-500">{{ $field['hint'] }}</p>
                                @endif

                                @error("billing.$column")
                                    <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    @endforeach
                </div>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary" data-test="billing-save">
                        {{ $this->hasBillingProfile() ? __('Update billing details') : __('Save billing details') }}
                    </flux:button>

                    @if (! $this->hasBillingProfile())
                        <span class="text-2xs text-ink-400 dark:text-ink-500">
                            {{ __('Purchases you already made keep the details they were billed with.') }}
                        </span>
                    @endif
                </div>
            </div>
        </form>
    @endif
</div>
