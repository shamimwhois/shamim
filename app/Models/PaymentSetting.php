<?php

namespace App\Models;

use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use Database\Factories\PaymentSettingFactory;
use Database\Seeders\PaymentProviderSeeder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configuration for one payment provider, edited in the admin console.
 *
 * Rows are created by {@see PaymentProviderSeeder} — one per
 * {@see PaymentProvider} case — so the console never has to handle a provider
 * that does not exist yet.
 *
 * `credentials` is an `encrypted:array`: the gateway's keys and secrets are
 * stored ciphertext-only. The array shape is defined by
 * {@see PaymentProvider::credentialFields()}.
 *
 * @property int $id
 * @property PaymentProvider $provider
 * @property bool $enabled
 * @property PaymentMode $mode
 * @property string $currency
 * @property string|null $min_amount
 * @property string|null $max_amount
 * @property string|null $instructions
 * @property array<string, string>|null $credentials
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['provider', 'enabled', 'mode', 'currency', 'min_amount', 'max_amount', 'instructions', 'credentials'])]
#[Hidden(['credentials'])]
class PaymentSetting extends Model
{
    /** @use HasFactory<PaymentSettingFactory> */
    use HasFactory;

    /**
     * The currencies an operator may pick for a provider.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_CURRENCIES = ['BDT', 'USD', 'EUR', 'GBP'];

    protected $attributes = [
        'enabled' => false,
        'mode' => PaymentMode::Test->value,
        'currency' => 'BDT',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'enabled' => 'boolean',
            'mode' => PaymentMode::class,
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'credentials' => 'encrypted:array',
        ];
    }

    /**
     * Providers currently accepting payments.
     *
     * The future checkout reads through this scope so "enabled" has exactly one
     * definition in the application.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function enabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * True when at least one secret credential has been stored.
     */
    public function hasConfiguredSecrets(): bool
    {
        $stored = $this->credentials ?? [];

        foreach ($this->provider->credentialFields() as $field) {
            if ($field['secret'] && filled($stored[$field['key']] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
