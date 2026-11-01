<?php

use App\Actions\Marketplace\PlaceOrder;
use App\Enums\PaymentProvider;
use App\Models\MarketplaceProduct;
use App\Models\PaymentSetting;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Checkout for one marketplace listing.
 *
 * The page offers exactly the providers the payment console has enabled —
 * {@see PaymentSetting::scopeEnabled()} is the single definition of "can
 * accept a payment", and {@see PlaceOrder} refuses any other choice, so the
 * radios are a convenience and the action is the control.
 *
 * Placing an order does not pay for it. A priced listing lands at Pending
 * with an open payment attempt, and only
 * {@see \App\Actions\Marketplace\ConfirmPayment} moving that attempt can
 * make the order Paid. In the sandbox the confirmation is a pair of demo
 * buttons on the next screen; the seam where a real gateway's verified
 * callback would land is the same method.
 */
new #[Title('Checkout')] class extends Component
{
    public MarketplaceProduct $product;

    public string $provider = '';

    public function mount(MarketplaceProduct $product): void
    {
        abort_unless($product->isPublished(), 404);
        abort_if($product->isOwnedBy(auth()->user()), 403);

        $this->product = $product->load('creator');
    }

    /**
     * Providers buyers may currently pay through, console configuration verbatim.
     *
     * @return Collection<int, PaymentSetting>
     */
    #[Computed]
    public function enabledSettings(): Collection
    {
        return PaymentSetting::query()->enabled()->orderBy('id')->get();
    }

    /**
     * Open the order and send the buyer to its payment screen.
     */
    public function placeOrder(PlaceOrder $placeOrder): void
    {
        if (! $this->product->isFree()) {
            $this->validate(['provider' => ['required', 'string']]);
        }

        $order = $placeOrder->handle(
            $this->product,
            auth()->user(),
            $this->product->isFree() ? null : PaymentProvider::tryFrom($this->provider),
        );

        $this->redirectRoute('purchases.show', $order, navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-3xl px-4 py-10 sm:px-6 lg:py-14">
    <p class="v96-eyebrow">{{ __('Checkout') }}</p>

    <div class="v96-card mt-4 !h-auto p-6 sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-ink-900 dark:text-ink-50">
                    <a href="{{ route('marketplace.show', $product->slug) }}" wire:navigate class="hover:underline">
                        {{ $product->name }}
                    </a>
                </h1>

                <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">
                    {{ __('By :name', ['name' => $product->creator->name]) }} · {{ $product->license->label() }}
                </p>
            </div>

            <p class="v96-price shrink-0 !text-[24px]">{{ $product->formattedPrice() }}</p>
        </div>

        <form class="mt-6 border-t border-ink-200 pt-6 dark:border-ink-800" wire:submit="placeOrder">
            @if ($product->isFree())
                <p class="text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ __('This listing is free. Getting it records a purchase at no cost — nothing is charged.') }}
                </p>

                <button type="submit" class="focus-ring v96-btn mt-5 w-full sm:w-auto" data-test="checkout-submit">
                    {{ __('Get for free') }}
                    <span aria-hidden="true">&rarr;</span>
                </button>
            @elseif ($this->enabledSettings->isEmpty())
                <p class="text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ __('No payment method is available right now. Please check back soon.') }}
                </p>
            @else
                <fieldset>
                    <legend class="v96-eyebrow">{{ __('Pay with') }}</legend>

                    <div class="mt-4 space-y-3">
                        @foreach ($this->enabledSettings as $setting)
                            <label
                                @class([
                                    'block cursor-pointer rounded-xl border p-4 transition',
                                    'border-ink-200 dark:border-ink-800' => $provider !== $setting->provider->value,
                                    'border-sui-accent bg-sui-accent-soft/40' => $provider === $setting->provider->value,
                                ])
                            >
                                <span class="flex items-center gap-3">
                                    <input
                                        type="radio"
                                        name="provider"
                                        value="{{ $setting->provider->value }}"
                                        wire:model.live="provider"
                                        class="size-4 accent-sui-accent"
                                        data-test="checkout-provider-{{ $setting->provider->value }}"
                                    />

                                    <span class="min-w-0 text-sm font-medium text-ink-900 dark:text-ink-50">
                                        {{ $setting->provider->label() }}
                                    </span>

                                    <span class="ml-auto shrink-0 text-xs tabular-nums text-ink-400">
                                        {{ $setting->currency }}
                                        @if ($setting->mode->value === 'live')
                                            · {{ __('live') }}
                                        @else
                                            · {{ __('sandbox') }}
                                        @endif
                                    </span>
                                </span>

                                @if ($setting->instructions)
                                    <span class="mt-2 block ps-7 text-xs leading-relaxed text-ink-500 dark:text-ink-400">
                                        {{ $setting->instructions }}
                                    </span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                @error('provider')
                    <p class="mt-3 text-xs leading-relaxed text-sui-danger">{{ $message }}</p>
                @enderror

                <p class="mt-4 border-t border-ink-200 pt-4 text-2xs leading-relaxed text-ink-400 dark:border-ink-800 dark:text-ink-500">
                    {{ __('Payments on this platform are simulated end to end — no real account is charged. Your order is only marked paid once the payment is confirmed server-side, never by returning to a success page.') }}
                </p>

                <button type="submit" class="focus-ring v96-btn mt-5 w-full sm:w-auto" data-test="checkout-submit">
                    {{ __('Review order') }}
                    <span aria-hidden="true">&rarr;</span>
                </button>
            @endif
        </form>
    </div>
</div>
