<?php

namespace App\Enums;

/**
 * The state of a refund against a paid order.
 *
 * The sandbox settles every recorded refund immediately, so `Pending` exists
 * for the shape a real gateway's asynchronous refund will need — the ledger
 * should not have to be re-migrated when that day comes.
 *
 * The values are stored on `marketplace_refunds.status`.
 */
enum RefundStatus: string
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
            self::Pending => __('Pending'),
            self::Succeeded => __('Refunded'),
            self::Failed => __('Failed'),
        };
    }

    /**
     * Get the Flux badge colour for the status.
     *
     * Matches {@see OrderStatus::badgeColor()} so a refund row and the order it
     * moved share one colour vocabulary.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Succeeded => 'violet',
            self::Failed => 'rose',
        };
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
