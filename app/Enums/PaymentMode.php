<?php

namespace App\Enums;

/**
 * Whether a gateway points at its sandbox or its live endpoints.
 *
 * Nothing reads the base URLs yet — checkout lands in a later phase — but the
 * mode is stored from day one so a half-configured live gateway cannot quietly
 * become a real-money one.
 */
enum PaymentMode: string
{
    case Test = 'test';
    case Live = 'live';

    public function label(): string
    {
        return match ($this) {
            self::Test => __('Test'),
            self::Live => __('Live'),
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $mode) => ['value' => $mode->value, 'label' => $mode->label()])
            ->values()
            ->toArray();
    }
}
