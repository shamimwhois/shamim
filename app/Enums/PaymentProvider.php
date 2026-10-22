<?php

namespace App\Enums;

/**
 * The payment providers the platform can offer at checkout.
 *
 * Each case declares the credential fields its gateway needs and whether a
 * field is secret. The payment console renders its forms from this metadata so
 * adding a sixth gateway is a change here, not in the Blade — and secrets are
 * write-only in the UI because the form knows which fields to mask.
 */
enum PaymentProvider: string
{
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Binance = 'binance';
    case Paypal = 'paypal';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Bkash => __('bKash'),
            self::Nagad => __('Nagad'),
            self::Binance => __('Binance Pay'),
            self::Paypal => __('PayPal'),
            self::Card => __('Card'),
        };
    }

    /**
     * The currency a provider is most likely to settle in.
     *
     * A default for seeding and new rows only — the console lets an operator
     * override it per provider.
     */
    public function defaultCurrency(): string
    {
        return match ($this) {
            self::Bkash, self::Nagad => 'BDT',
            default => 'USD',
        };
    }

    /**
     * The credential fields this gateway expects.
     *
     * @return array<int, array{key: string, label: string, secret: bool}>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::Bkash => [
                ['key' => 'username', 'label' => __('Username / PIN'), 'secret' => false],
                ['key' => 'app_key', 'label' => __('App Key'), 'secret' => false],
                ['key' => 'app_secret', 'label' => __('App Secret'), 'secret' => true],
            ],
            self::Nagad => [
                ['key' => 'merchant_id', 'label' => __('Merchant ID'), 'secret' => false],
                ['key' => 'api_token', 'label' => __('API Token'), 'secret' => true],
            ],
            self::Binance => [
                ['key' => 'merchant_id', 'label' => __('Merchant ID'), 'secret' => false],
                ['key' => 'api_key', 'label' => __('API Key'), 'secret' => false],
                ['key' => 'api_secret', 'label' => __('API Secret'), 'secret' => true],
            ],
            self::Paypal => [
                ['key' => 'client_id', 'label' => __('Client ID'), 'secret' => false],
                ['key' => 'client_secret', 'label' => __('Client Secret'), 'secret' => true],
            ],
            self::Card => [
                ['key' => 'publishable_key', 'label' => __('Publishable Key'), 'secret' => false],
                ['key' => 'secret_key', 'label' => __('Secret Key'), 'secret' => true],
            ],
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $provider) => ['value' => $provider->value, 'label' => $provider->label()])
            ->values()
            ->toArray();
    }
}
