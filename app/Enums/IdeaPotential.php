<?php

namespace App\Enums;

/**
 * What an idea is worth pursuing, as judged by the account that wrote it.
 *
 * `docs/Idea.md:157` calls this "estimated value or potential". It is recorded as
 * three steps rather than a number because nobody estimates a currency figure
 * for a thought they just had, and a column that invites `1` would be read as
 * precision that the input does not carry.
 */
enum IdeaPotential: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Get the display label for the potential.
     */
    public function label(): string
    {
        return match ($this) {
            self::Low => __('Some value'),
            self::Medium => __('Worth a plan'),
            self::High => __('Worth building now'),
        };
    }

    /**
     * Get the Flux badge colour token for the potential.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Low => 'zinc',
            self::Medium => 'sky',
            self::High => 'emerald',
        };
    }

    /**
     * Get every potential as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $potential) => ['value' => $potential->value, 'label' => $potential->label()])
            ->values()
            ->toArray();
    }
}
