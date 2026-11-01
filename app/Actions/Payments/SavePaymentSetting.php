<?php

namespace App\Actions\Payments;

use App\Enums\PaymentMode;
use App\Models\PaymentSetting;

/**
 * Persist one provider's payment configuration.
 *
 * Credentials merge rather than replace: an empty input means "keep what is
 * stored", which is what the write-only secret fields in the console promise.
 * A value is only ever overwritten by another value; clearing one secret
 * specifically is not a UI operation.
 */
class SavePaymentSetting
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(PaymentSetting $setting, array $data): PaymentSetting
    {
        $credentials = $setting->credentials ?? [];

        foreach ($data['credentials'] ?? [] as $key => $value) {
            if (filled($value)) {
                $credentials[$key] = $value;
            }
        }

        $setting->fill([
            'enabled' => (bool) $data['enabled'],
            'mode' => PaymentMode::from($data['mode']),
            'currency' => $data['currency'],
            'min_amount' => filled($data['min_amount'] ?? null) ? $data['min_amount'] : null,
            'max_amount' => filled($data['max_amount'] ?? null) ? $data['max_amount'] : null,
            'instructions' => filled($data['instructions'] ?? null) ? $data['instructions'] : null,
            'credentials' => $credentials,
        ])->save();

        return $setting->refresh();
    }
}
