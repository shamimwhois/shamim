<?php

use App\Actions\Payments\SavePaymentSetting;
use App\Enums\AccountPermission;
use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Models\PaymentSetting;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Payment provider configuration.
 *
 * One card per {@see PaymentProvider}; editing happens inline, the same way
 * the idea categories console works. Two invariants hold this page together:
 *
 *   1. Secrets are write-only. Stored credentials never round-trip into the
 *      form — a blank secret input means "keep what is stored", and
 *      {@see SavePaymentSetting} merges rather than replaces.
 *   2. A provider cannot be enabled with holes in its keyring. Saving with
 *      `enabled` on requires every credential field to be present, either
 *      entered now or already stored — an enabled gateway that would fail at
 *      the first real transaction is worse than a disabled one.
 *
 * The rows themselves are seeded ({@see \Database\Seeders\PaymentProviderSeeder});
 * this console configures, it does not create.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Payment')] class extends Component
{
    /** The row currently open in the inline editor. */
    public ?int $editingId = null;

    public bool $enabled = false;

    public string $mode = PaymentMode::Test->value;

    public string $currency = 'BDT';

    public string $minAmount = '';

    public string $maxAmount = '';

    public string $instructions = '';

    /**
     * Credential input for the row being edited, keyed by field key.
     *
     * Secrets arrive empty and empty means "unchanged" on save.
     *
     * @var array<string, string>
     */
    public array $credentials = [];

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::ConfigurePayments), 403);
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Payment',
            'description' => 'Configure the payment gateways offered at checkout.',
            'noindex' => true,
        ]);
    }

    /**
     * @return Collection<int, PaymentSetting>
     */
    #[Computed]
    public function settings(): Collection
    {
        return PaymentSetting::query()->orderBy('id')->get();
    }

    /**
     * Open a provider's row in the inline editor.
     */
    public function edit(int $settingId): void
    {
        $setting = PaymentSetting::query()->findOrFail($settingId);
        $stored = $setting->credentials ?? [];

        $this->editingId = $setting->id;
        $this->enabled = $setting->enabled;
        $this->mode = $setting->mode->value;
        $this->currency = $setting->currency;
        $this->minAmount = (string) ($setting->min_amount ?? '');
        $this->maxAmount = (string) ($setting->max_amount ?? '');
        $this->instructions = (string) $setting->instructions;

        $fields = [];
        foreach ($setting->provider->credentialFields() as $field) {
            // Public identifiers round-trip; secrets never leave the store.
            $fields[$field['key']] = $field['secret'] ? '' : (string) ($stored[$field['key']] ?? '');
        }
        $this->credentials = $fields;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'enabled', 'mode', 'currency', 'minAmount', 'maxAmount', 'instructions', 'credentials');
        $this->resetValidation();
    }

    /**
     * Validate and persist the open row.
     */
    public function save(SavePaymentSetting $savePaymentSetting): void
    {
        $setting = PaymentSetting::query()->findOrFail((int) $this->editingId);

        $this->validate([
            'mode' => ['required', 'string', Rule::in(collect(PaymentMode::cases())->pluck('value')->all())],
            'currency' => ['required', 'string', Rule::in(PaymentSetting::SUPPORTED_CURRENCIES)],
            'minAmount' => ['nullable', 'numeric', 'min:0'],
            'maxAmount' => ['nullable', 'numeric', 'min:0'],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        if (filled($this->minAmount) && filled($this->maxAmount) && (float) $this->minAmount > (float) $this->maxAmount) {
            $this->addError('maxAmount', __('The maximum must be at least the minimum.'));

            return;
        }

        if ($this->enabled && ! $this->credentialsComplete($setting)) {
            $this->addError('credentials', __('Fill in every credential before enabling :provider.', [
                'provider' => $setting->provider->label(),
            ]));

            return;
        }

        $savePaymentSetting->handle($setting, [
            'enabled' => $this->enabled,
            'mode' => $this->mode,
            'currency' => $this->currency,
            'min_amount' => $this->minAmount,
            'max_amount' => $this->maxAmount,
            'instructions' => $this->instructions,
            'credentials' => $this->credentials,
        ]);

        $this->resetForm();

        unset($this->settings);

        Flux::toast(variant: 'success', text: __(':provider settings saved.', ['provider' => $setting->provider->label()]));
    }

    /**
     * True when every credential field is present, entered now or already stored.
     */
    protected function credentialsComplete(PaymentSetting $setting): bool
    {
        $stored = $setting->credentials ?? [];

        foreach ($setting->provider->credentialFields() as $field) {
            if (! filled($this->credentials[$field['key']] ?? null) && ! filled($stored[$field['key']] ?? null)) {
                return false;
            }
        }

        return true;
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Administration"
        title="Payment"
        description="The gateways buyers can pay through. Nothing is live until it is enabled and fully keyed."
        :breadcrumbs="[['label' => __('Administration')], ['label' => __('Payment')]]"
    />

    <x-app.ui.body>
        @error('credentials')
            <div class="rounded-md border border-sui-danger/30 bg-sui-danger-soft px-4 py-3 text-sm text-sui-danger">
                {{ $message }}
            </div>
        @enderror

        <div class="grid gap-4 xl:grid-cols-2">
            @foreach ($this->settings as $setting)
                @php
                    $editing = $editingId === $setting->id;
                    $stored = $setting->credentials ?? [];
                    $fields = $setting->provider->credentialFields();
                    $setCount = collect($fields)->filter(fn (array $f) => filled($stored[$f['key']] ?? null))->count();
                @endphp

                <div @class(['sui-card p-5 sm:p-6', 'ring-1 ring-sui-accent/30' => $editing]) data-test="payment-card-{{ $setting->provider->value }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-ink-900">{{ $setting->provider->label() }}</h2>
                            <p class="mt-1 text-2xs text-ink-400">
                                {{ $setCount }} / {{ count($fields) }}
                                {{ __('credentials set') }}
                                · {{ $setting->currency }}
                                @if ($setting->min_amount !== null || $setting->max_amount !== null)
                                    · {{ __('limits') }}
                                    {{ $setting->min_amount ?? '0' }}
                                    –
                                    {{ $setting->max_amount ?: '∞' }}
                                @endif
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <flux:badge :color="$setting->enabled ? 'green' : 'gray'" size="sm">
                                {{ $setting->enabled ? __('Enabled') : __('Disabled') }}
                            </flux:badge>
                            <flux:badge :color="$setting->mode === PaymentMode::Live ? 'purple' : 'yellow'" size="sm">
                                {{ $setting->mode->label() }}
                            </flux:badge>

                            @unless ($editing)
                                <flux:button variant="ghost" size="xs" icon="pencil-square" wire:click="edit({{ $setting->id }})" data-test="payment-edit-{{ $setting->provider->value }}">
                                    {{ __('Edit') }}
                                </flux:button>
                            @endunless
                        </div>
                    </div>

                    @if ($setting->instructions && ! $editing)
                        <p class="mt-3 line-clamp-2 text-xs leading-normal text-ink-500">{{ $setting->instructions }}</p>
                    @endif

                    @if ($editing)
                        <form class="mt-5 space-y-5 border-t border-sui-hairline pt-5" wire:submit="save">
                            <div class="flex flex-wrap items-center gap-4">
                                <label class="flex cursor-pointer items-center gap-2 text-sm text-ink-900">
                                    <input type="checkbox" wire:model.live="enabled" class="sui-focus size-4 shrink-0 accent-sui-accent" data-test="payment-enabled" />
                                    {{ __('Enabled') }}
                                </label>

                                <div class="flex items-center gap-2">
                                    <label for="payment-mode" class="text-xs text-ink-500">{{ __('Mode') }}</label>
                                    <select id="payment-mode" wire:model.live="mode" class="sui-control !py-1.5 text-xs" data-test="payment-mode">
                                        @foreach (PaymentMode::cases() as $case)
                                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="flex items-center gap-2">
                                    <label for="payment-currency" class="text-xs text-ink-500">{{ __('Currency') }}</label>
                                    <select id="payment-currency" wire:model.live="currency" class="sui-control !py-1.5 text-xs" data-test="payment-currency">
                                        @foreach (PaymentSetting::SUPPORTED_CURRENCIES as $code)
                                            <option value="{{ $code }}">{{ $code }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="payment-min" class="sui-control-label">{{ __('Minimum amount') }}</label>
                                    <input id="payment-min" type="number" min="0" step="0.01" wire:model="minAmount" placeholder="{{ __('No minimum') }}" class="sui-control mt-2 w-full" />
                                    @error('minAmount')
                                        <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="payment-max" class="sui-control-label">{{ __('Maximum amount') }}</label>
                                    <input id="payment-max" type="number" min="0" step="0.01" wire:model="maxAmount" placeholder="{{ __('No maximum') }}" class="sui-control mt-2 w-full" />
                                    @error('maxAmount')
                                        <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div>
                                <h3 class="sui-label">{{ __('Credentials') }}</h3>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    @foreach ($fields as $field)
                                        <div>
                                            <label for="payment-cred-{{ $setting->id }}-{{ $field['key'] }}" class="sui-control-label">
                                                {{ $field['label'] }}
                                                @if ($field['secret'] && filled($stored[$field['key']] ?? null))
                                                    <span class="ml-1 text-2xs text-ink-400">({{ __('stored') }})</span>
                                                @endif
                                            </label>
                                            <input
                                                id="payment-cred-{{ $setting->id }}-{{ $field['key'] }}"
                                                type="{{ $field['secret'] ? 'password' : 'text' }}"
                                                wire:model="credentials.{{ $field['key'] }}"
                                                autocomplete="{{ $field['secret'] ? 'new-password' : 'off' }}"
                                                placeholder="{{ $field['secret'] ? __('leave blank to keep') : '' }}"
                                                class="sui-control mt-2 w-full"
                                                data-test="payment-cred-{{ $field['key'] }}"
                                            />
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <label for="payment-instructions" class="sui-control-label">{{ __('Buyer instructions') }}</label>
                                <textarea
                                    id="payment-instructions"
                                    wire:model="instructions"
                                    rows="3"
                                    placeholder="{{ __('Shown to buyers at checkout, e.g. a personal number to send to or steps to follow.') }}"
                                    class="sui-control mt-2 w-full resize-y text-xs leading-normal"
                                ></textarea>
                                @error('instructions')
                                    <p class="mt-1 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" data-test="payment-save">
                                    {{ __('Save settings') }}
                                </flux:button>
                                <flux:button variant="ghost" wire:click="cancel">
                                    {{ __('Cancel') }}
                                </flux:button>
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </x-app.ui.body>
</div>
