<?php

use App\Enums\AccountPermission;
use App\Enums\PaymentStatus;
use App\Models\MarketplaceOrder;
use App\Support\Seo;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One invoice: the document a buyer keeps and an accountant accepts.
 *
 * ## What this page reads
 *
 * Only `marketplace_orders` and the billing columns copied onto the order at
 * purchase. The listing may since have been renamed, repriced or deleted, and
 * the account that bought it may have rewritten its billing profile; neither
 * can restate a sale that already happened, which is why the snapshot exists
 * and why nothing here dereferences the product or the live profile.
 *
 * ## Why it is HTML rather than a PDF
 *
 * The receipt is a document, and a document has to be printable. A PDF
 * generator would be a new dependency, a new font pipeline and a new place for
 * the layout to break, to produce something the browser's own Print dialog
 * already renders on A4 paper. So the download affordance is print, and the
 * `.invoice-sheet` rules in the marketplace stylesheet are the difference
 * between a web page and a receipt.
 *
 * ## Who may open it
 *
 * Its buyer, or an operator holding `sale:view-any`. Anyone else is answered
 * with 404 rather than 403, the same rule the order screen follows: an invoice
 * number is not public information, and the difference between the two codes
 * would confirm which ids exist.
 *
 * An order that never captured money has no invoice at all — there is nothing
 * to bill — so it answers 404 as well, at every status that is not paid.
 */
new #[Layout('layouts::plain')] #[Title('Invoice')] class extends Component
{
    public MarketplaceOrder $order;

    public function mount(MarketplaceOrder $order): void
    {
        $account = auth()->user();

        abort_unless(
            $order->user_id === ($account?->id ?? 0) || ($account?->hasPermission(AccountPermission::ViewAnySales) ?? false),
            404,
        );
        abort_if($order->paid_at === null, 404);

        $this->order = $order->load(['payments', 'refunds']);

        app(Seo::class)->set([
            'title' => $order->invoiceNumber(),
            'description' => __('Invoice :invoice for order :order.', [
                'invoice' => $order->invoiceNumber(),
                'order' => $order->order_number,
            ]),
            'noindex' => true,
        ]);
    }

    /**
     * The successful attempt's gateway reference, when one was recorded.
     */
    public function captureReference(): ?string
    {
        return $this->order->payments
            ->firstWhere('status', PaymentStatus::Succeeded)
            ?->reference;
    }
}; ?>

@php
    $invoiceNumber = $order->invoiceNumber();
    $refundedCents = $order->refundedCents();
    $netCents = $order->price_cents - $refundedCents;
@endphp

<div class="mx-auto w-full max-w-3xl px-4 py-10 sm:px-6 print:px-0 print:py-0">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 print:hidden">
        <a
            href="{{ route('purchases.show', $order) }}"
            wire:navigate
            class="focus-ring text-xs text-ink-500 underline-offset-4 hover:underline dark:text-ink-400"
        >
            &larr; {{ __('Back to the order') }}
        </a>

        <button
            type="button"
            onclick="window.print()"
            class="focus-ring v96-btn inline-flex !py-2 text-sm"
            data-test="invoice-print"
        >
            <flux:icon name="printer" class="size-4" />
            {{ __('Print / save as PDF') }}
        </button>
    </div>

    <article class="invoice-sheet rounded-xl border border-ink-200 bg-white p-8 shadow-sm sm:p-10 dark:border-ink-800 dark:bg-ink-950 print:rounded-none print:border-0 print:p-0 print:shadow-none">
        <header class="flex flex-wrap items-start justify-between gap-6 border-b border-ink-200 pb-6 dark:border-ink-800">
            <div>
                <p class="text-lg font-bold tracking-tight text-ink-900 dark:text-ink-50">{{ config('app.name') }}</p>

                <p class="mt-1 font-mono text-2xs uppercase tracking-widest text-ink-400">{{ __('Marketplace invoice') }}</p>
            </div>

            <div class="text-right">
                <p class="font-mono text-2xs uppercase tracking-widest text-ink-400">{{ __('Invoice number') }}</p>

                <p class="mt-1 font-mono text-sm font-semibold text-ink-900 dark:text-ink-50">{{ $invoiceNumber }}</p>

                <p class="mt-3 text-2xs text-ink-500">
                    {{ __('Issued :date', ['date' => $order->paid_at->format('j M Y')]) }}
                </p>
            </div>
        </header>

        <section class="grid gap-8 border-b border-ink-200 py-6 sm:grid-cols-2 dark:border-ink-800">
            <div>
                <h2 class="font-mono text-2xs uppercase tracking-widest text-ink-400">{{ __('Billed to') }}</h2>

                <address class="mt-3 text-sm not-italic leading-relaxed text-ink-800 dark:text-ink-200">
                    <strong class="font-semibold">{{ $order->billing_name }}</strong>

                    @if ($order->billing_company)
                        <br />{{ $order->billing_company }}
                    @endif

                    @if ($order->billing_address)
                        <br />{{ $order->billing_address }}
                    @endif

                    @if ($order->billing_tax_id)
                        <br /><span class="font-mono text-xs">{{ $order->billing_tax_id }}</span>
                    @endif

                    @if ($order->billing_email)
                        <br /><span class="text-ink-500">{{ $order->billing_email }}</span>
                    @endif
                </address>
            </div>

            <div class="sm:text-right">
                <h2 class="font-mono text-2xs uppercase tracking-widest text-ink-400">{{ __('Order') }}</h2>

                <dl class="mt-3 space-y-1.5 text-sm text-ink-800 dark:text-ink-200">
                    <div class="sm:flex sm:justify-end sm:gap-2">
                        <dt class="text-ink-500">{{ __('Order number') }}</dt>
                        <dd class="font-mono text-xs">{{ $order->order_number }}</dd>
                    </div>

                    <div class="sm:flex sm:justify-end sm:gap-2">
                        <dt class="text-ink-500">{{ __('Placed') }}</dt>
                        <dd>{{ $order->created_at->format('j M Y, H:i') }}</dd>
                    </div>

                    <div class="sm:flex sm:justify-end sm:gap-2">
                        <dt class="text-ink-500">{{ __('Paid') }}</dt>
                        <dd>{{ $order->paid_at->format('j M Y, H:i') }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="border-b border-ink-200 py-6 dark:border-ink-800">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">{{ __('Invoiced line items') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" class="pb-2 font-mono text-2xs font-normal uppercase tracking-widest text-ink-400">{{ __('Item') }}</th>
                        <th scope="col" class="pb-2 text-right font-mono text-2xs font-normal uppercase tracking-widest text-ink-400">{{ __('Amount') }}</th>
                    </tr>
                </thead>

                <tbody>
                    <tr>
                        <th scope="row" class="py-2 text-left font-normal">
                            <span class="block font-semibold text-ink-900 dark:text-ink-50">{{ $order->product_name }}</span>

                            <span class="mt-0.5 block font-mono text-2xs text-ink-400">{{ $order->product_slug }}</span>
                        </th>

                        <td class="py-2 text-right tabular-nums text-ink-900 dark:text-ink-50">
                            {{ $order->price_cents === 0 ? __('No charge') : $order->formattedAmount() }}
                        </td>
                    </tr>

                    @if ($refundedCents > 0)
                        <tr>
                            <td class="py-2 text-ink-500">
                                {{ __('Refunded') }}

                                <span class="mt-0.5 block text-2xs">
                                    {{ trans_choice(':count refund|:count refunds', $order->refunds->count()) }}
                                </span>
                            </td>

                            <td class="py-2 text-right tabular-nums text-ink-500">
                                &minus;{{ $order->formattedAmountFor($refundedCents) }}
                            </td>
                        </tr>
                    @endif
                </tbody>

                <tfoot>
                    <tr class="border-t border-ink-200 dark:border-ink-800">
                        <th scope="row" class="pt-3 text-left font-semibold text-ink-900 dark:text-ink-50">{{ __('Total charged') }}</th>

                        <td class="pt-3 text-right text-base font-bold tabular-nums text-ink-900 dark:text-ink-50">
                            {{ $order->formattedAmountFor($netCents) }}
                        </td>
                    </tr>
                </tfoot>
            </table>

            <p class="mt-3 text-2xs text-ink-400">
                {{ __('Amounts are stated in the currency the listing was sold in (:currency), in minor units rounded to two decimals.', ['currency' => $order->currency]) }}
            </p>
        </section>

        <section class="grid gap-8 border-b border-ink-200 py-6 sm:grid-cols-2 dark:border-ink-800">
            <div>
                <h2 class="font-mono text-2xs uppercase tracking-widest text-ink-400">{{ __('Payment') }}</h2>

                <dl class="mt-3 space-y-1.5 text-sm text-ink-800 dark:text-ink-200">
                    <div class="flex gap-2">
                        <dt class="text-ink-500">{{ __('Method') }}</dt>
                        <dd>{{ $order->payment_provider?->label() ?? __('—') }}</dd>
                    </div>

                    <div class="flex gap-2">
                        <dt class="text-ink-500">{{ __('Mode') }}</dt>
                        <dd>{{ $order->payment_mode?->label() ?? __('—') }}</dd>
                    </div>

                    <div class="flex gap-2">
                        <dt class="text-ink-500">{{ __('Reference') }}</dt>
                        <dd class="font-mono text-xs">{{ $this->captureReference() ?? __('—') }}</dd>
                    </div>
                </dl>
            </div>

            @if ($order->refunds->isNotEmpty())
                <div>
                    <h2 class="font-mono text-2xs uppercase tracking-widest text-ink-400">{{ __('Refunds') }}</h2>

                    <ul class="mt-3 space-y-1.5 text-sm text-ink-800 dark:text-ink-200">
                        @foreach ($order->refunds as $refund)
                            <li class="flex items-baseline justify-between gap-3" wire:key="invoice-refund-{{ $refund->id }}">
                                <span class="text-ink-500">
                                    {{ $refund->status->label() }} · {{ $refund->occurredAt()->format('j M Y') }}
                                    @if ($refund->reason)
                                        <span class="block text-2xs">{{ $refund->reason }}</span>
                                    @endif
                                </span>

                                <span class="tabular-nums">{{ $refund->formattedAmount() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        <footer class="pt-6">
            <p class="text-2xs leading-relaxed text-ink-400">
                {{ __('Issued by :platform. This invoice reflects the billing details on file when the order was placed; later changes to the account are not restated here.', ['platform' => config('app.name')]) }}
            </p>
        </footer>
    </article>
</div>
