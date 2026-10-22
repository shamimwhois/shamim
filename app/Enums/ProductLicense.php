<?php

namespace App\Enums;

/**
 * The licence a marketplace product is distributed under.
 *
 * The set deliberately mixes open-source licences (MIT, Apache, GPL) with the
 * commercial shapes a paid listing uses (personal, commercial, extended). A
 * buyer reads this before paying, so the label is a promise — it must describe
 * what the product actually grants, not what would sell best.
 */
enum ProductLicense: string
{
    case Mit = 'mit';
    case Apache = 'apache-2.0';
    case Gpl = 'gpl-3.0';
    case Personal = 'personal';
    case Commercial = 'commercial';
    case Extended = 'extended';

    /**
     * Get the short label shown on a product card or badge.
     */
    public function label(): string
    {
        return match ($this) {
            self::Mit => 'MIT',
            self::Apache => 'Apache 2.0',
            self::Gpl => 'GPL 3.0',
            self::Personal => __('Personal use'),
            self::Commercial => __('Commercial'),
            self::Extended => __('Extended commercial'),
        };
    }

    /**
     * Get the sentence-length description shown on the product page.
     */
    public function description(): string
    {
        return match ($this) {
            self::Mit => __('Permissive: use, modify and redistribute with attribution.'),
            self::Apache => __('Permissive, with an explicit patent grant and attribution terms.'),
            self::Gpl => __('Copyleft: derivative works must stay under the same licence.'),
            self::Personal => __('One person, non-commercial projects only.'),
            self::Commercial => __('One commercial product, including client work.'),
            self::Extended => __('Unlimited commercial products, with resale rights.'),
        };
    }

    /**
     * Determine whether the licence is an OSI-approved open-source licence.
     */
    public function isOpenSource(): bool
    {
        return in_array($this, [self::Mit, self::Apache, self::Gpl], true);
    }

    /**
     * Determine whether the licence permits commercial use.
     */
    public function allowsCommercialUse(): bool
    {
        return $this !== self::Personal;
    }

    /**
     * Get every licence as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $license) => ['value' => $license->value, 'label' => $license->label()])
            ->values()
            ->toArray();
    }
}
