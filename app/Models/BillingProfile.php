<?php

namespace App\Models;

use App\Support\BillingCountry;
use Database\Factories\BillingProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The billing identity an account wants printed on its invoices.
 *
 * @property int $id
 * @property int $user_id
 * @property string $legal_name
 * @property string|null $company
 * @property string|null $tax_id
 * @property string $email
 * @property string|null $phone
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string $city
 * @property string|null $postal_code
 * @property string|null $country
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 */
#[Fillable([
    'user_id',
    'legal_name',
    'company',
    'tax_id',
    'email',
    'phone',
    'address_line1',
    'address_line2',
    'city',
    'postal_code',
    'country',
])]
class BillingProfile extends Model
{
    /** @use HasFactory<BillingProfileFactory> */
    use HasFactory;

    /**
     * Get the account this billing identity belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Compose the postal address onto the lines an invoice prints.
     *
     * @return array<int, string>
     */
    public function addressLines(): array
    {
        $street = array_filter([$this->address_line1, $this->address_line2]);

        $locality = trim(implode(' ', array_filter([$this->postal_code, $this->city])));

        return array_values(array_filter([
            implode(', ', $street),
            $locality,
            $this->country !== null ? BillingCountry::name($this->country) : null,
        ]));
    }

    /**
     * The address as the single string an order snapshots.
     */
    public function addressLine(): string
    {
        return implode(', ', $this->addressLines());
    }

    /**
     * Get the billing identity an account has saved, if it has saved one.
     *
     * Relations are declared on the child side throughout this application —
     * nothing hangs belongs-to/has-many methods off {@see User} — so the
     * lookup lives here rather than as `$user->billingProfile`.
     */
    public static function forAccount(User $account): ?self
    {
        return static::query()->where('user_id', $account->getKey())->first();
    }

    /**
     * The five billing columns an order carries for the rest of its life.
     *
     * Called at checkout, never while rendering: an account that edits this
     * profile afterwards must not be able to change an issued document, and a
     * buyer who never filled one in still needs a receivable order.
     *
     * @return array<string, string|null>
     */
    public static function snapshotFor(User $buyer): array
    {
        $profile = static::forAccount($buyer);

        if ($profile === null) {
            return [
                'billing_name' => $buyer->name,
                'billing_company' => null,
                'billing_tax_id' => null,
                'billing_email' => $buyer->email,
                'billing_address' => null,
            ];
        }

        return [
            'billing_name' => $profile->legal_name,
            'billing_company' => $profile->company,
            'billing_tax_id' => $profile->tax_id,
            'billing_email' => $profile->email,
            'billing_address' => $profile->addressLine(),
        ];
    }
}
