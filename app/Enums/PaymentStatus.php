<?php

namespace App\Enums;

/**
 * The state of a single payment attempt against an order.
 *
 * The payment record is deliberately separate from the commercial order: an
 * order says what the buyer owes, a payment says what one instrument did
 * about it. In the sandbox a failed attempt is terminal and a retry means a
 * fresh checkout, so an order carries at most one payment.
 *
 * The values are stored on `marketplace_payments.status`.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting payment'),
            self::Succeeded => __('Captured'),
            self::Failed => __('Declined'),
        };
    }

    /**
     * Get the Flux badge colour for the status.
     *
     * The same vocabulary as {@see OrderStatus::badgeColor()}, because a buyer
     * sees both badges on one screen and they must not disagree about what
     * amber means.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Succeeded => 'emerald',
            self::Failed => 'rose',
        };
    }

    /**
     * Determine whether the attempt is still awaiting an outcome.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Get every status as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()])
            ->values()
            ->toArray();
    }
}
