<?php

namespace App\Actions\Marketplace;

use App\Models\BillingProfile;
use App\Models\User;
use App\Support\BillingCountry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Write an account's billing identity.
 *
 * One profile per account, so this creates on first save and updates after —
 * there is no "delete my billing details" state to reason about, and the
 * checkout needs a row it can snapshot or fall back from.
 *
 * Nothing here reaches an already-placed order: the five billing columns on
 * `marketplace_orders` were copied when that order was written, which is the
 * whole reason an invoice can be re-printed years later and still say what it
 * said on the day it was issued.
 *
 * The rules live here, not on the pages, because there is one billing profile
 * and therefore one definition of what may go into it. A second form that
 * writes a profile must inherit the same contract rather than restate it — two
 * copies of a rule set drift, and the copy that is not edited is the one that
 * lets bad data through. {@see rules()} is the single source; the pages consume
 * it and never spell out their own.
 */
class SaveBillingProfile
{
    /**
     * The field names, in display order.
     *
     * @var array<int, string>
     */
    public const FIELDS = [
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
    ];

    /**
     * The validation rules for a billing form.
     *
     * Keys are `billing.<column>` so a page binding `wire:model="billing.x"`
     * validates its own payload without translation. Required fields are the
     * ones an invoice cannot be cut without.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'billing.legal_name' => ['required', 'string', 'max:255'],
            'billing.company' => ['nullable', 'string', 'max:255'],
            'billing.tax_id' => ['nullable', 'string', 'max:32'],
            'billing.email' => ['required', 'email', 'max:255'],
            'billing.phone' => ['nullable', 'string', 'max:32'],
            'billing.address_line1' => ['required', 'string', 'max:255'],
            'billing.address_line2' => ['nullable', 'string', 'max:255'],
            'billing.city' => ['required', 'string', 'max:120'],
            'billing.postal_code' => ['nullable', 'string', 'max:16'],
            'billing.country' => ['nullable', 'string', Rule::in(array_keys(BillingCountry::options()))],
        ];
    }

    /**
     * The blank form a page renders before a profile exists.
     *
     * Prefilled from the account only where the account already knows the
     * answer — the name and the email an invoice is addressed to. Everything
     * else starts empty rather than guessed.
     *
     * @return array<string, string|null>
     */
    public static function blankFor(User $account): array
    {
        $form = [];

        foreach (self::FIELDS as $column) {
            $form[$column] = match ($column) {
                'legal_name' => $account->name,
                'email' => $account->email,
                default => null,
            };
        }

        return $form;
    }

    /**
     * Create or replace the account's billing profile.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $account, array $data): BillingProfile
    {
        return DB::transaction(function () use ($account, $data): BillingProfile {
            $legalName = trim((string) ($data['legal_name'] ?? ''));

            if ($legalName === '') {
                throw new InvalidArgumentException(__('A billing name is required before an invoice can be issued.'));
            }

            $country = mb_strtoupper(trim((string) ($data['country'] ?? '')));

            /*
            | The country is written onto a document of record, so an unknown
            | code is refused here rather than trusted because some form
            | happened to filter it. The pages validate this too; the action
            | does not assume they did.
            */
            if (! BillingCountry::accepts($country)) {
                throw new InvalidArgumentException(__('That country cannot be used for a billing address.'));
            }

            $profile = BillingProfile::forAccount($account) ?? new BillingProfile;

            $profile->fill([
                'user_id' => $account->getKey(),
                'legal_name' => $legalName,
                'company' => trim((string) ($data['company'] ?? '')) ?: null,
                'tax_id' => mb_strtoupper(trim((string) ($data['tax_id'] ?? ''))) ?: null,
                'email' => trim((string) ($data['email'] ?? $account->email)),
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'address_line1' => trim((string) ($data['address_line1'] ?? '')),
                'address_line2' => trim((string) ($data['address_line2'] ?? '')) ?: null,
                'city' => trim((string) ($data['city'] ?? '')),
                'postal_code' => trim((string) ($data['postal_code'] ?? '')) ?: null,
                'country' => $country ?: null,
            ]);

            $profile->save();

            return $profile->refresh();
        });
    }
}
