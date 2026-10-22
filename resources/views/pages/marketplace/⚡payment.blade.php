<?php

use App\Actions\Marketplace\CancelOrder;
use App\Actions\Marketplace\ConfirmPayment;
use App\Models\MarketplaceOrder;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One order: its status, and the sandbox controls that resolve its payment.
 *
 * ## Who may see it
 *
 * An order belongs to exactly one buyer, and that is the whole rule: anything
 * else — another account reaching this id — answers 404 rather than 403, so
 * order numbers do not leak existence. The check runs on every action, not
 * only on mount, because a Livewire update POST bypasses the route.
 *
 * ## Why the outcome buttons are server actions
 *
 * The platform's rule is that no order is marked paid because a browser
 * landed on a success URL. These buttons do not pretend otherwise: each one
 * is a POST that runs {@see ConfirmPayment}, which stands in for the
 * gateway's server-side verification a real integration would perform. The
 * action's payable-state guard makes a second click — or a second callback —
 * a no-op, so capture happens at most once.
 */
new #[Title('Order')] class extends Component
{
    public MarketplaceOrder $order;

    public function mount(MarketplaceOrder $order): void
    {
        abort_unless($order->user_id === auth()->id(), 404);

        $this->loadOrder($order);
    }

    /**
     * Confirm the payment — the sandbox's stand-in for a verified gateway callback.
     */
    public function confirmPaid(ConfirmPayment $confirmPayment): void
    {
        $order = $this->ownedOrder();

        $confirmPayment->handle($order, true, 'SANDBOX-'.Str::upper(Str::random(10)));

        $this->loadOrder($order);

        Flux::toast(variant: 'success', text: __('Payment confirmed. You own this listing.'));
    }

    /**
     * Record a declined attempt.
     */
    public function confirmFailed(ConfirmPayment $confirmPayment): void
    {
        $order = $this->ownedOrder();

        $confirmPayment->handle($order, false);

        $this->loadOrder($order);

        Flux::toast(variant: 'warning', text: __('The payment attempt was declined.'));
    }

    /**
     * Withdraw the order before any money is captured.
     */
    public function cancelOrder(CancelOrder $cancelOrder): void
    {
        $order = $this->ownedOrder();

        $cancelOrder->handle($order);

        $this->loadOrder($order);

        Flux::toast(text: __('The order was cancelled.'));
    }

    /**
     * Reload the order and re-assert ownership.
     */
    protected function ownedOrder(): MarketplaceOrder
    {
        $order = MarketplaceOrder::query()->findOrFail($this->order->id);

        abort_unless($order->user_id === auth()->id(), 404);

        return $order;
    }

    protected function loadOrder(MarketplaceOrder $order): void
    {
        $this->order = $order->load(['payments', 'refunds', 'product.currentVersion']);
    }
}; ?>

<div class="mx-auto w-full max-w-3xl px-4 py-10 sm:px-6 lg:py-14">
    <p class="v96-eyebrow">{{ __('Order :number', ['number' => $order->order_number]) }}</p>

    <div class="v96-card mt-4 !h-auto p-6 sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-ink-900 dark:text-ink-50">{{ $order->product_name }}</h1>

                <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">
                    {{ __('Placed :date', ['date' => $order->created_at->format('j M Y, H:i')]) }}
                </p>
            </div>

            <flux:badge :color="$order->status->badgeColor()" size="md">
                {{ $order->status->label() }}
            </flux:badge>
        </div>

        <dl class="mt-6 grid gap-4 border-t border-ink-200 pt-6 text-sm sm:grid-cols-3 dark:border-ink-800">
            <div>
                <dt class="text-2xs uppercase tracking-widest text-ink-400">{{ __('Paid') }}</dt>
                <dd class="mt-1 font-medium tabular-nums text-ink-900 dark:text-ink-50">
                    {{ $order->price_cents === 0 ? __('Free') : $order->formattedAmount() }}
                </dd>
            </div>

            <div>
                <dt class="text-2xs uppercase tracking-widest text-ink-400">{{ __('Method') }}</dt>
                <dd class="mt-1 font-medium text-ink-900 dark:text-ink-50">
                    {{ $order->payment_provider?->label() ?? __('—') }}
                </dd>
            </div>

            <div>
                <dt class="text-2xs uppercase tracking-widest text-ink-400">{{ __('Status') }}</dt>
                <dd class="mt-1 text-ink-500 dark:text-ink-400">{{ $order->status->description() }}</dd>
            </div>
        </dl>

        @if ($order->payments->isNotEmpty())
            <div class="mt-6 border-t border-ink-200 pt-6 dark:border-ink-800">
                <h2 class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
                    {{ __('Payment attempts') }}
                </h2>

                <ul class="mt-3 space-y-2">
                    @foreach ($order->payments as $payment)
                        <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-xs" wire:key="attempt-{{ $payment->id }}">
                            <flux:badge :color="$payment->status->badgeColor()" size="sm">
                                {{ $payment->status->label() }}
                            </flux:badge>

                            <span class="text-ink-500 dark:text-ink-400">
                                {{ $payment->provider->label() }} · {{ $payment->mode->label() }}
                            </span>

                            <span class="tabular-nums text-ink-900 dark:text-ink-50">{{ $payment->formattedAmount() }}</span>

                            <span class="text-ink-400">{{ $payment->occurredAt()->format('j M Y, H:i') }}</span>

                            @if ($payment->reference)
                                <span class="font-mono text-2xs text-ink-400">{{ $payment->reference }}</span>
                            @endif

                            @if ($payment->failure_reason)
                                <span class="text-rose-600 dark:text-rose-400">{{ $payment->failure_reason }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($order->refunds->isNotEmpty())
            <ul class="mt-4 space-y-1 border-t border-ink-200 pt-4 dark:border-ink-800">
                @foreach ($order->refunds as $refund)
                    <li class="text-xs text-ink-500 dark:text-ink-400" wire:key="refund-{{ $refund->id }}">
                        {{ __('Refunded :amount :date', ['amount' => $refund->formattedAmount(), 'date' => $refund->processed_at?->format('j M Y') ?? '']) }}
                        @if ($refund->reason)
                            — {{ $refund->reason }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($order->status->isPayable())
            <div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700/60 dark:bg-amber-900/20" data-test="order-sandbox">
                <p class="text-xs font-semibold uppercase tracking-widest text-amber-700 dark:text-amber-400">
                    {{ __('Sandbox payment') }}
                </p>

                <p class="mt-2 text-xs leading-relaxed text-ink-600 dark:text-ink-300">
                    {{ __('A real gateway verifies a payment server-side; returning to a success URL would never mark this order paid. These buttons are the simulated gateway’s server-side confirmation — the seam a real callback would occupy.') }}
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="confirmPaid" wire:loading.attr="disabled" class="focus-ring v96-btn !py-2 text-sm" data-test="order-confirm-paid">
                        {{ __('Simulate success') }}
                    </button>

                    <button type="button" wire:click="confirmFailed" wire:loading.attr="disabled" class="focus-ring v96-btn v96-btn--ghost !py-2 text-sm" data-test="order-confirm-failed">
                        {{ __('Simulate failure') }}
                    </button>

                    <button type="button" wire:click="cancelOrder" wire:loading.attr="disabled" class="focus-ring v96-btn v96-btn--ghost !py-2 text-sm" data-test="order-cancel">
                        {{ __('Cancel order') }}
                    </button>
                </div>
            </div>
        @elseif ($order->status->isEntitling() && $order->product !== null)
            <div class="mt-6 border-t border-ink-200 pt-6 dark:border-ink-800">
                <p class="text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                    {{ __('You own this listing. The current release is v:version.', ['version' => $order->product->currentVersion?->version ?? $order->product->version]) }}
                </p>

                @if ($order->product->install_command)
                    <code class="mt-3 block rounded-lg bg-ink-950 px-4 py-3 font-mono text-xs text-emerald-300 dark:bg-ink-900">{{ $order->product->install_command }}</code>
                @endif

                @if ($order->product->currentVersion?->hasDeliverable())
                    {{-- A plain link, not `wire:navigate`: the navigator would fetch the
                         archive as a page and the buyer would get nothing. The route
                         streams the bytes while the order still entitles. --}}
                    <a
                        href="{{ route('purchases.download', $order) }}"
                        class="focus-ring v96-btn mt-4 inline-flex !py-2 text-sm"
                        data-test="order-download"
                    >
                        <flux:icon name="arrow-down-tray" class="size-4" />
                        {{ __('Download :file', ['file' => $order->product->currentVersion->deliverableName()]) }}
                    </a>
                @else
                    <p class="mt-3 text-xs leading-relaxed text-ink-500 dark:text-ink-400">
                        {{ __('The author has not attached a file to this release, so there is nothing to download yet. The listing’s install instructions are how it is set up.') }}
                    </p>
                @endif

                <a href="{{ route('marketplace.show', $order->product_slug) }}" wire:navigate class="focus-ring v96-btn v96-btn--ghost mt-4 inline-flex !py-2 text-sm">
                    {{ __('Back to the listing') }}
                </a>
            </div>
        @endif

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('purchases.index') }}" wire:navigate class="text-xs text-ink-500 underline-offset-4 hover:underline dark:text-ink-400">
                &larr; {{ __('All purchases') }}
            </a>

            @if ($order->paid_at !== null)
                <a
                    href="{{ route('purchases.invoice', $order) }}"
                    wire:navigate
                    class="focus-ring v96-btn v96-btn--ghost inline-flex !py-2 text-sm"
                    data-test="order-invoice"
                >
                    <flux:icon name="document-text" class="size-4" />
                    {{ __('View invoice') }}
                </a>
            @endif
        </div>
    </div>
</div>
