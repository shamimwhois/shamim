<?php

namespace App\Enums;

/**
 * The publication state of a marketplace product.
 *
 * The states describe the review pipeline a seller's listing travels through:
 * a draft is private to its creator, a pending listing is waiting on an
 * operator holding `product:review`, and only a published listing is readable
 * by the public. Archiving withdraws a listing without destroying its sales
 * history, which is why it is a status rather than a soft delete.
 *
 * The values are stored on `marketplace_products.status` and are the only
 * thing the public catalogue filters on, so they must not be renamed without a
 * migration.
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::PendingReview => __('In review'),
            self::Published => __('Published'),
            self::Archived => __('Archived'),
        };
    }

    /**
     * Get a one-line explanation of what the status means for the listing.
     */
    public function description(): string
    {
        return match ($this) {
            self::Draft => __('Only the creator can see this listing.'),
            self::PendingReview => __('Submitted and waiting for an operator to review it.'),
            self::Published => __('Live in the public catalogue.'),
            self::Archived => __('Withdrawn from the catalogue; the record is kept.'),
        };
    }

    /**
     * Get the Flux badge colour token for the status.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::PendingReview => 'amber',
            self::Published => 'emerald',
            self::Archived => 'zinc',
        };
    }

    /**
     * Determine whether a listing in this state is readable by the public.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    /**
     * Determine whether an operator can act on a listing in this state.
     */
    public function awaitsReview(): bool
    {
        return $this === self::PendingReview;
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
