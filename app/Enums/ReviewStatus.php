<?php

namespace App\Enums;

/**
 * The moderation state of a marketplace review.
 *
 * Reviews are user-generated content, so they follow the same rule as comments:
 * nothing is public until a human has approved it. The default is `Pending`
 * because that is what a newly submitted review is — a factory or seeder that
 * wanted a visible review has to say so.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting review'),
            self::Approved => __('Published'),
            self::Rejected => __('Rejected'),
        };
    }

    /**
     * Get the Flux badge colour token for the status.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'rose',
        };
    }

    /**
     * Determine whether the review is readable by the public.
     */
    public function isPublic(): bool
    {
        return $this === self::Approved;
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
