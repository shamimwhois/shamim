<?php

namespace App\Enums;

/**
 * The plan attached to an account role.
 *
 * Plans describe the *commercial shape* of a role — what the account may
 * publish and on what terms — as opposed to {@see AccountRole}, which describes
 * *authority*. Keeping them separate matters because the roadmap attaches real
 * plan entitlements (listing limits, commission rates, storage quotas) to
 * roles later, and those limits should be data, not conditionals scattered
 * through policies.
 *
 * Plans are derived from the role today ({@see AccountRole::plan()}). When plan
 * entitlements become configurable per account they move onto the `users` table
 * and this enum becomes a fallback default.
 */
enum RolePlan: string
{
    case Free = 'free';
    case Seller = 'seller';
    case Platform = 'platform';

    /**
     * Get the display label for the plan.
     */
    public function label(): string
    {
        return match ($this) {
            self::Free => __('Free'),
            self::Seller => __('Seller'),
            self::Platform => __('Platform'),
        };
    }

    /**
     * Get the marketing blurb shown on the plan card.
     */
    public function tagline(): string
    {
        return match ($this) {
            self::Free => __('Read, write and publish your own work.'),
            self::Seller => __('Publish and sell your own products.'),
            self::Platform => __('Operate the whole platform.'),
        };
    }

    /**
     * Get the plan's feature list, as bullet points for display.
     *
     * @return array<int, string>
     */
    public function features(): array
    {
        return match ($this) {
            self::Free => [
                __('Author and edit your own posts'),
                __('Moderate comments on your own posts'),
                __('Join teams and collaborate'),
            ],
            self::Seller => [
                __('Everything in Free'),
                __('Publish posts without review'),
                __('Create and manage product listings'),
                __('Track your own sales and support requests'),
            ],
            self::Platform => [
                __('Everything in Seller'),
                __('Manage users, roles and sellers'),
                __('Review, approve and moderate listings'),
                __('Configure payments and view audit history'),
            ],
        };
    }

    /**
     * Get the maximum number of published product listings.
     *
     * `null` means unlimited. This is the first real plan entitlement, and the
     * shape every later quota should follow: a single method on the plan, read
     * through the account, never inlined at a call site.
     */
    public function productListingLimit(): ?int
    {
        return match ($this) {
            self::Free => 0,
            self::Seller => 25,
            self::Platform => null,
        };
    }

    /**
     * Get the platform commission rate, as a percentage.
     *
     * Only meaningful for plans that may sell.
     */
    public function commissionPercent(): ?float
    {
        return match ($this) {
            self::Free => null,
            self::Seller => 15.0,
            self::Platform => 0.0,
        };
    }

    /**
     * Determine whether the plan may sell at all.
     */
    public function canSell(): bool
    {
        return ($this->productListingLimit() ?? 1) > 0;
    }

    /**
     * Get every plan as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $plan) => ['value' => $plan->value, 'label' => $plan->label()])
            ->values()
            ->toArray();
    }
}
