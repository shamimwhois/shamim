<?php

namespace Database\Seeders;

use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Models\PaymentSetting;
use Illuminate\Database\Seeder;

/**
 * Seed one configuration row per payment provider.
 *
 * Keyed upsert on `provider` so re-running is safe and adding a case to
 * {@see PaymentProvider} only ever adds the missing row. Nothing is enabled or
 * live by default: an unconfigured gateway must be switched on deliberately in
 * the admin console.
 */
class PaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PaymentProvider::cases() as $provider) {
            PaymentSetting::query()->updateOrCreate(
                ['provider' => $provider->value],
                [
                    'enabled' => false,
                    'mode' => PaymentMode::Test->value,
                    'currency' => $provider->defaultCurrency(),
                ],
            );
        }
    }
}
