<?php

use App\Actions\Marketplace\SaveBillingProfile;
use App\Models\BillingProfile;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Support\BillingCountry;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Billing settings — the account's money surface.
 *
 * Everything the buyer side needs in one place: the billing details an
 * invoice is cut from (the same profile, and the same write action, the
 * purchases page uses — there is one billing profile, not two), the recent
 * orders, and the recent payment attempts. The full tabbed history stays on
 * /purchases; this page carries the essentials and hands off.
 */
new #[Title('Billing')] #[Layout('layouts::feed')] class extends Component
{
    /**
     * The billing profile as a plain array, so the form renders and validates
     * without an Eloquent model in front of it.
     *
     * @var array<string, string|null>
     */
    public array $billing = [];

    /**
     * Form fields in display order, with the key the log's settings grid
     * prints. The invoice needs the starred ones.
     */
    private const array FIELDS = [
        'legal_name' => 'legal name *',
        'company' => 'company',
        'tax_id' => 'tax id',
        'email' => 'email *',
        'phone' => 'phone',
        'address_line1' => 'address *',
        'address_line2' => 'address 2',
        'city' => 'city *',
        'postal_code' => 'postal code',
        'country' => 'country',
    ];

    public function mount(): void
    {
        $profile = BillingProfile::forAccount(Auth::user());

        $blank = SaveBillingProfile::blankFor(Auth::user());

        foreach (array_keys(self::FIELDS) as $column) {
            $this->billing[$column] = $profile?->{$column} ?? $blank[$column];
        }
    }

    /**
     * Save the billing profile.
     */
    public function saveBilling(SaveBillingProfile $saveBillingProfile): void
    {
        $this->billing = array_map(
            fn (?string $value): ?string => ($value === '' || $value === null) ? null : $value,
            $this->billing,
        );

        // One profile means one rule set: the rules live on the action, and
        // this page consumes them rather than restating them.
        $validated = $this->validate(SaveBillingProfile::rules());

        $saveBillingProfile->handle(Auth::user(), $validated['billing']);

        Flux::toast(variant: 'success', text: __('Your billing details were saved.'));
    }

    /**
     * The account's most recent orders, newest first.
     */
    #[Computed]
    public function orders(): \Illuminate\Support\Collection
    {
        return MarketplaceOrder::query()
            ->forBuyer(Auth::id())
            ->with('currentPayment')
            ->latest('created_at')
            ->limit(6)
            ->get();
    }

    /**
     * The most recent payment attempts, reduced to the row the ledger prints.
     */
    #[Computed]
    public function payments(): \Illuminate\Support\Collection
    {
        return MarketplacePayment::query()
            ->forBuyer(Auth::id())
            ->with('order:id,order_number')
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->map(fn (MarketplacePayment $payment): array => [
                'date' => $payment->occurredAt(),
                'order_number' => $payment->order->order_number,
                'method' => $payment->provider->label().' · '.$payment->mode->label(),
                'status' => $payment->status->label(),
                'amount' => $payment->formattedAmount(),
                'reference' => $payment->reference ?? $payment->failure_reason,
            ]);
    }

    /**
     * Whether the form is backed by a stored profile.
     */
    #[Computed]
    public function hasBillingProfile(): bool
    {
        return BillingProfile::forAccount(Auth::user()) !== null;
    }
}; ?>

<x-log.settings tab="billing">
    <section class="dn-settings-section" aria-labelledby="settings-billing">
        <h2 id="settings-billing" class="dn-settings-title">{{ __('Billing details') }}</h2>
        <p class="dn-settings-note">
            {{ __('The details invoices are cut from. They travel with every order, so an order never depends on what the profile says later.') }}
        </p>

        <form wire:submit="saveBilling" class="dn-fields" style="margin-top: 16px;">
            @foreach (self::FIELDS as $column => $key)
                @php
                    $required = str_contains($key, '*');
                @endphp
                <div class="dn-field">
                    <label class="dn-field-key" for="billing-{{ $column }}">{{ __($key) }}</label>

                    @if ($column === 'country')
                        <select
                            id="billing-{{ $column }}"
                            wire:model="billing.country"
                            class="dn-input"
                            data-test="billing-country"
                        >
                            <option value="">{{ __('—') }}</option>
                            @foreach (\App\Support\BillingCountry::options() as $code => $name)
                                <option value="{{ $code }}" @selected($billing[$column] === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                    @else
                        <input
                            id="billing-{{ $column }}"
                            wire:model="billing.{{ $column }}"
                            type="{{ $column === 'email' ? 'email' : 'text' }}"
                            @if ($required) required @endif
                            autocomplete="off"
                            class="dn-input"
                            data-test="billing-{{ $column }}"
                        />
                    @endif
                </div>
            @endforeach

            @if ($errors->isNotEmpty())
                <div class="dn-field">
                    <span class="dn-field-key">{{ __('error') }}</span>
                    <div>
                        @foreach ($errors->all() as $message)
                            <p class="dn-form-error" style="margin: 0 0 4px;">{{ $message }}</p>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="dn-field" style="justify-content: flex-end; display: flex;">
                <button type="submit" class="dn-btn" data-test="billing-save">
                    {{ __('Save billing details') }}
                </button>
            </div>
        </form>

        @if (! $this->hasBillingProfile)
            <p class="dn-settings-note" style="margin-top: 10px;">
                {{ __('No billing profile yet — the form is prefilled from your account and saved on the first save.') }}
            </p>
        @endif
    </section>

    <section class="dn-settings-section" aria-labelledby="settings-purchases">
        <h2 id="settings-purchases" class="dn-settings-title">{{ __('Purchases') }}</h2>
        <p class="dn-settings-note">{{ __('The most recent orders this account placed.') }}</p>

        <div class="dn-fields" style="margin-top: 16px;">
            @if ($this->orders->isEmpty())
                <div class="dn-field">
                    <span class="dn-field-key">{{ __('empty') }}</span>
                    <span style="font-size: 14px;">
                        {{ __('Nothing bought yet.') }}
                        <a href="{{ route('marketplace.index') }}" wire:navigate class="dn-link">{{ __('Browse the marketplace') }}</a>.
                    </span>
                </div>
            @else
                @foreach ($this->orders as $order)
                    <a
                        href="{{ route('purchases.show', ['order' => $order->id]) }}"
                        wire:navigate
                        class="dn-field"
                        style="text-decoration: none;"
                        data-test="billing-order"
                    >
                        <span class="dn-field-key">{{ $order->created_at->format('M j') }}</span>
                        <span style="display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 0;">
                            <span style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <span style="font-size: 14px; font-weight: 500; color: var(--dn-ink);">{{ $order->product_name }}</span>
                                <span class="dn-field-key" style="margin-left: 6px;">{{ $order->order_number }}</span>
                            </span>
                            <span style="flex: none; display: inline-flex; align-items: center; gap: 8px;">
                                <span class="dn-field-key">{{ $order->status->label() }}</span>
                                <span style="font-family: var(--dn-mono); font-size: 13px;">{{ $order->formattedAmount() }}</span>
                            </span>
                        </span>
                    </a>
                @endforeach
            @endif
        </div>

        <p style="margin-top: 10px;">
            <a href="{{ route('purchases.index') }}" wire:navigate class="dn-link">
                {{ __('All purchases and the full ledger') }} →
            </a>
        </p>
    </section>

    <section class="dn-settings-section" aria-labelledby="settings-payments">
        <h2 id="settings-payments" class="dn-settings-title">{{ __('Payments') }}</h2>
        <p class="dn-settings-note">{{ __('The most recent payment attempts on this account.') }}</p>

        <div class="dn-fields" style="margin-top: 16px;">
            @if ($this->payments->isEmpty())
                <div class="dn-field">
                    <span class="dn-field-key">{{ __('empty') }}</span>
                    <span style="font-size: 14px;">{{ __('No payments yet.') }}</span>
                </div>
            @else
                @foreach ($this->payments as $row)
                    <div class="dn-field" data-test="billing-payment">
                        <span class="dn-field-key">{{ \Illuminate\Support\Str::of($row['date'])->before(' ')->toString() }}</span>
                        <span style="display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 0;">
                            <span style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <span style="font-size: 14px;">{{ $row['method'] }}</span>
                                <span class="dn-field-key" style="margin-left: 6px;">{{ $row['order_number'] }}</span>
                                @if ($row['reference'])
                                    <span class="dn-field-key" style="margin-left: 6px;">{{ \Illuminate\Support\Str::limit($row['reference'], 18) }}</span>
                                @endif
                            </span>
                            <span style="flex: none; display: inline-flex; align-items: center; gap: 8px;">
                                <span class="dn-field-key">{{ $row['status'] }}</span>
                                <span style="font-family: var(--dn-mono); font-size: 13px;">{{ $row['amount'] }}</span>
                            </span>
                        </span>
                    </div>
                @endforeach
            @endif
        </div>
    </section>
</x-log.settings>
