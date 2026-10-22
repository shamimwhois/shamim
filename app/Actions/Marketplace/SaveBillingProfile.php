<?php

namespace App\Actions\Marketplace;

use App\Models\BillingProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
 */
class SaveBillingProfile
{
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
                'country' => mb_strtoupper(trim((string) ($data['country'] ?? ''))) ?: null,
            ]);

            $profile->save();

            return $profile->refresh();
        });
    }
}
