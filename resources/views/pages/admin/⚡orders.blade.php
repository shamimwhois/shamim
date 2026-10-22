<?php

use App\Actions\Marketplace\ConfirmPayment;
use App\Actions\Marketplace\RefundOrder;
use App\Enums\AccountPermission;
use App\Enums\OrderStatus;
use App\Models\MarketplaceOrder;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every order on the platform, and the operator's money controls.
 *
 * Two abilities govern this page, and they are deliberately different:
 * `sale:view-any` opens it, `refund:process` arms its two mutations. Reading
 * sales is oversight; capturing a reconciled deposit or sending money back
 * moves real value, so both actions re-check `ProcessRefunds` in the method
 * — a Livewire update POST never passes through route middleware, and the
 * rendered button is a convenience, not the control.
 *
 * "Mark as paid" runs {@see ConfirmPayment}, the same action the sandbox
 * checkout uses, for the real-world case it stands in for: a deposit that
 * cleared at the provider but never reached the platform. Its payable-state
 * guard means clicking it on a paid order changes nothing.
 *
 * Refunds are amounts, not a toggle: the form takes any value up to what
 * remains refundable, and {@see RefundOrder} refuses the rest.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Orders')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    /** Orders expanded to show their payment and refund ledger. */
    public array $expanded = [];

    /** Refund amounts by order id, in major units as typed. */
    public array $refundAmount = [];

    public string $refundReason = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::ViewAnySales), 403);
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Orders',
            'description' => 'Marketplace orders, payments and refunds.',
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

    public function toggle(int $orderId): void
    {
        if (in_array($orderId, $this->expanded, true)) {
            $this->expanded = array_values(array_diff($this->expanded, [$orderId]));

            return;
        }

        // An admin may inspect any order, but the expanding control is
        // harmless read-only detail; the view guard in boot() already ran.
        $this->expanded[] = $orderId;
    }

    /**
     * @return LengthAwarePaginator<int, MarketplaceOrder>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return MarketplaceOrder::query()
            ->with('buyer')
            ->when($this->search !== '', fn ($query) => $query->search($this->search))
            ->when(
                $this->statusFilter !== 'all',
                fn ($query) => $query->where('status', $this->statusFilter),
            )
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $counts = MarketplaceOrder::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $status) => [
                $status->value => (int) $counts->get($status->value, 0),
            ])
            ->all();
    }

    /**
     * Settle a payable order whose payment cleared out-of-band.
     */
    public function markPaid(int $orderId, ConfirmPayment $confirmPayment): void
    {
        $this->authorizeRefundAction();

        $order = MarketplaceOrder::query()->findOrFail($orderId);

        $confirmPayment->handle($order, true, 'MANUAL-'.Str::upper(Str::random(8)));

        $this->forgetComputed();

        Flux::toast(variant: 'success', text: __('Order :number marked paid.', ['number' => $order->order_number]));
    }

    /**
     * Decline a payable order the operator could not reconcile.
     */
    public function markFailed(int $orderId, ConfirmPayment $confirmPayment): void
    {
        $this->authorizeRefundAction();

        $order = MarketplaceOrder::query()->findOrFail($orderId);

        $confirmPayment->handle($order, false);

        $this->forgetComputed();

        Flux::toast(variant: 'warning', text: __('Order :number marked failed.', ['number' => $order->order_number]));
    }

    /**
     * Refund part or all of a paid order.
     */
    public function refund(int $orderId, RefundOrder $refundOrder): void
    {
        $this->authorizeRefundAction();

        $order = MarketplaceOrder::query()->with('refunds')->findOrFail($orderId);

        $validated = $this->validate([
            'refundAmount.'.$orderId => ['required', 'numeric', 'min:0.01'],
            'refundReason' => ['nullable', 'string', 'max:500'],
        ], [
            'refundAmount.'.$orderId.'.required' => __('Enter an amount to refund.'),
            'refundAmount.'.$orderId.'.min' => __('The refund must be at least :min.', ['min' => '0.01']),
        ]);

        $cents = (int) round((float) $validated['refundAmount'][$orderId] * 100);

        try {
            $refundOrder->handle($order, $cents, auth()->user(), $this->refundReason ?: null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('refundAmount.'.$orderId, $e->validator->errors()->first('amount'));

            return;
        }

        $this->reset('refundReason');
        $this->forgetComputed();

        Flux::toast(variant: 'success', text: __('Refund issued for order :number.', ['number' => $order->order_number]));
    }

    /**
     * Both mutations move money, so both are gated by the same ability —
     * inside the method, where client input cannot reach past it.
     */
    protected function authorizeRefundAction(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::ProcessRefunds), 403);
    }

    protected function forgetComputed(): void
    {
        unset($this->orders, $this->statusCounts);
        $this->resetValidation();
    }

    /**
     * True when the operator's account can move money from this page.
     */
    #[Computed]
    public function canProcessMoney(): bool
    {
        return auth()->user()?->hasPermission(AccountPermission::ProcessRefunds) ?? false;
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Administration"
        title="Orders"
        description="Every marketplace order, with the payment and refund ledger behind each one."
        :breadcrumbs="[['label' => __('Administration')], ['label' => __('Orders')]]"
    />

    <x-app.ui.body>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="w-full lg:max-w-xs">
                <label for="order-search" class="sr-only">{{ __('Search') }}</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="order-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('Search order number or listing…')"
                        autocomplete="off"
                        class="sui-control w-full pl-9"
                        data-test="order-search"
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
                        {{ \App\Enums\OrderStatus::from($statusValue)->label() }}
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

        @if ($this->orders->isEmpty())
            <x-app.ui.empty-state
                icon="receipt-refund"
                title="No orders match those filters"
                description="Checkout orders will appear here as buyers purchase listings."
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
                            <th scope="col" class="px-5 py-3 text-right sui-table-head">
                                <span class="sr-only">{{ __('Details') }}</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-sui-hairline">
                        @foreach ($this->orders as $order)
                            @php $isOpen = in_array($order->id, $this->expanded, true); @endphp

                            <tr wire:key="order-{{ $order->id }}" class="transition duration-150 ease-soft hover:bg-sui-hover">
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

                                <td class="px-5 py-3.5 text-right">
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        icon="chevron-down"
                                        wire:click="toggle({{ $order->id }})"
                                        data-test="order-toggle-{{ $order->id }}"
                                        :aria-label="$isOpen ? __('Hide details') : __('Show details')"
                                    />
                                </td>
                            </tr>

                            @if ($isOpen)
                                <tr wire:key="order-detail-{{ $order->id }}">
                                    <td colspan="5" class="bg-sui-well px-5 py-5">
                                        <div class="grid gap-5 lg:grid-cols-2">
                                            <div>
                                                <h3 class="sui-label">{{ __('Payment attempts') }}</h3>

                                                <ul class="mt-2 space-y-1.5 text-xs text-ink-600 dark:text-ink-300">
                                                    @forelse ($order->payments as $payment)
                                                        <li wire:key="payment-{{ $payment->id }}">
                                                            {{ $payment->provider->label() }}
                                                            · {{ $payment->status->label() }}
                                                            · {{ $payment->formattedAmount() }}
                                                            @if ($payment->reference)
                                                                <span class="font-mono text-2xs">{{ $payment->reference }}</span>
                                                            @endif
                                                            @if ($payment->failure_reason)
                                                                <span class="text-sui-danger">{{ $payment->failure_reason }}</span>
                                                            @endif
                                                        </li>
                                                    @empty
                                                        <li class="text-ink-400">{{ __('None — this was a free order.') }}</li>
                                                    @endforelse
                                                </ul>

                                                @if ($order->refunds->isNotEmpty())
                                                    <h3 class="sui-label mt-4">{{ __('Refunds') }}</h3>

                                                    <ul class="mt-2 space-y-1.5 text-xs text-ink-600 dark:text-ink-300">
                                                        @foreach ($order->refunds as $refund)
                                                            <li wire:key="refund-{{ $refund->id }}">
                                                                {{ $refund->formattedAmount() }}
                                                                · {{ $refund->status->label() }}
                                                                · {{ $refund->processed_at?->format('j M Y') }}
                                                                @if ($refund->reason)
                                                                    — {{ $refund->reason }}
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ul>

                                                    <p class="mt-2 text-2xs text-ink-400">
                                                        {{ __(':refunded of :total returned', [
                                                            'refunded' => $order->formattedRefundedAmount(),
                                                            'total' => $order->formattedAmount(),
                                                        ]) }}
                                                    </p>
                                                @endif
                                            </div>

                                            <div>
                                                @if ($this->canProcessMoney && $order->status->isPayable())
                                                    <h3 class="sui-label">{{ __('Reconcile') }}</h3>

                                                    <p class="mt-1 text-2xs leading-relaxed text-ink-400">
                                                        {{ __('Use only when the payment cleared at the provider out-of-band. Both choices resolve the open attempt.') }}
                                                    </p>

                                                    <div class="mt-3 flex flex-wrap gap-2">
                                                        <flux:button
                                                            variant="primary"
                                                            size="xs"
                                                            wire:click="markPaid({{ $order->id }})"
                                                            wire:loading.attr="disabled"
                                                            data-test="order-mark-paid-{{ $order->id }}"
                                                        >
                                                            {{ __('Mark as paid') }}
                                                        </flux:button>

                                                        <flux:button
                                                            variant="ghost"
                                                            size="xs"
                                                            wire:click="markFailed({{ $order->id }})"
                                                            wire:loading.attr="disabled"
                                                            class="!text-sui-danger"
                                                            data-test="order-mark-failed-{{ $order->id }}"
                                                        >
                                                            {{ __('Mark as failed') }}
                                                        </flux:button>
                                                    </div>
                                                @endif

                                                @if ($this->canProcessMoney && $order->status->isRefundable() && $order->refundableCents() > 0)
                                                    <h3 class="sui-label mt-4">{{ __('Issue refund') }}</h3>

                                                    <div class="mt-2 flex flex-wrap items-start gap-2">
                                                        <div>
                                                            <label for="refund-amount-{{ $order->id }}" class="sr-only">{{ __('Amount') }}</label>
                                                            <input
                                                                id="refund-amount-{{ $order->id }}"
                                                                type="number"
                                                                min="0.01"
                                                                step="0.01"
                                                                max="{{ $order->refundableCents() / 100 }}"
                                                                wire:model="refundAmount.{{ $order->id }}"
                                                                :placeholder="__('Up to :max', ['max' => $order->formattedAmountFor($order->refundableCents())])"
                                                                class="sui-control w-32 !py-1.5 text-xs"
                                                                data-test="order-refund-amount-{{ $order->id }}"
                                                            />

                                                            @error('refundAmount.'.$order->id)
                                                                <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                                            @enderror
                                                        </div>

                                                        <flux:button
                                                            variant="danger"
                                                            size="xs"
                                                            class="mt-0.5"
                                                            wire:click="refund({{ $order->id }})"
                                                            wire:loading.attr="disabled"
                                                            data-test="order-refund-{{ $order->id }}"
                                                        >
                                                            {{ __('Refund') }}
                                                        </flux:button>
                                                    </div>

                                                    <div class="mt-2">
                                                        <label for="refund-reason" class="sr-only">{{ __('Reason') }}</label>
                                                        <input
                                                            id="refund-reason"
                                                            type="text"
                                                            maxlength="500"
                                                            wire:model="refundReason"
                                                            :placeholder="__('Reason (optional)')"
                                                            class="sui-control w-full !py-1.5 text-xs"
                                                            data-test="order-refund-reason"
                                                        />
                                                    </div>
                                                @elseif ($order->status->isRefundable() && $order->refundableCents() === 0)
                                                    <p class="mt-4 text-2xs text-ink-400">{{ __('Fully refunded. Nothing left to return.') }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-app.ui.pagination :paginator="$this->orders" />
        @endif
    </x-app.ui.body>
</div>
