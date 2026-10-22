<?php

namespace App\Support;

/**
 * The territory list a billing address may name.
 *
 * A fixed map rather than `Locale::getDisplayRegion()` because the `intl`
 * extension is not guaranteed to be installed, and because a document that has
 * to be readable in five years should not depend on how this month's ICU build
 * happens to spell a country. Codes are ISO-3166-1 alpha-2 — the same two
 * letters a tax identifier is prefixed with — and the list is the markets this
 * storefront actually ships to, not every state on earth.
 */
class BillingCountry
{
    /**
     * ISO-3166-1 alpha-2 codes mapped to the name printed on an invoice.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        'AU' => 'Australia',
        'BD' => 'Bangladesh',
        'BE' => 'Belgium',
        'BR' => 'Brazil',
        'CA' => 'Canada',
        'CN' => 'China',
        'DK' => 'Denmark',
        'EE' => 'Estonia',
        'FI' => 'Finland',
        'FR' => 'France',
        'DE' => 'Germany',
        'GH' => 'Ghana',
        'IN' => 'India',
        'ID' => 'Indonesia',
        'IE' => 'Ireland',
        'IT' => 'Italy',
        'JP' => 'Japan',
        'KE' => 'Kenya',
        'MY' => 'Malaysia',
        'NL' => 'Netherlands',
        'NZ' => 'New Zealand',
        'NG' => 'Nigeria',
        'NO' => 'Norway',
        'PK' => 'Pakistan',
        'PH' => 'Philippines',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'RO' => 'Romania',
        'SA' => 'Saudi Arabia',
        'SG' => 'Singapore',
        'ZA' => 'South Africa',
        'KR' => 'South Korea',
        'ES' => 'Spain',
        'SE' => 'Sweden',
        'CH' => 'Switzerland',
        'TW' => 'Taiwan',
        'TH' => 'Thailand',
        'TR' => 'Türkiye',
        'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom',
        'US' => 'United States',
        'VN' => 'Vietnam',
        'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',
    ];

    /**
     * Options for a `<select>`: code => name, sorted by name.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $names = self::NAMES;
        asort($names);

        return $names;
    }

    /**
     * Determine whether a code is one this storefront accepts.
     */
    public static function accepts(?string $code): bool
    {
        if ($code === null || $code === '') {
            return true;
        }

        return isset(self::NAMES[strtoupper($code)]);
    }

    /**
     * Read a code back as the name an invoice prints, or null when unknown.
     */
    public static function name(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::NAMES[strtoupper($code)] ?? strtoupper($code);
    }
}
