<?php

namespace App\Enums;

use App\Actions\Marketplace;

/**
 * The lifecycle of a marketplace order.
 *
 * An order is opened at `Pending`, travels to `Processing` while the payment
 * is being verified, and resolves to exactly one of `Paid` or `Failed`.
 * `Cancelled` withdraws an order that never captured money. The refund states
 * are aggregate views over the refunds recorded against a paid order: money
 * back that does not consume the whole price leaves the order
 * `PartiallyRefunded` — and still entitling — until the last cent returns.
 *
 * The transition rules live here, on the enum, because every surface that
 * moves an order (checkout, the buyer's pending page, the admin console)
 * must agree on what "allowed" means. The mutations themselves live in
 * {@see Marketplace}.
 *
 * The values are stored on `marketplace_orders.status`.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Processing => __('Processing'),
            self::Paid => __('Paid'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
            self::PartiallyRefunded => __('Partially refunded'),
            self::Refunded => __('Refunded'),
        };
    }

    /**
     * Get a one-line explanation of what the status means for the order.
     */
    public function description(): string
    {
        return match ($this) {
            self::Pending => __('Waiting for the payment to be made.'),
            self::Processing => __('The payment is being verified.'),
            self::Paid => __('Paid in full — the buyer holds the listing.'),
            self::Failed => __('The payment attempt was declined.'),
            self::Cancelled => __('Withdrawn before any money was captured.'),
            self::PartiallyRefunded => __('Some of the price was returned; the buyer keeps access.'),
            self::Refunded => __('The full price was returned.'),
        };
    }

    /**
     * Get the Flux badge colour token for the status.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Processing => 'sky',
            self::Paid => 'emerald',
            self::Failed => 'rose',
            self::Cancelled => 'zinc',
            self::PartiallyRefunded => 'violet',
            self::Refunded => 'red',
        };
    }

    /**
     * Determine whether an order in this state grants the buyer access.
     *
     * A partial refund returns money without withdrawing the listing, so
     * entitlement covers both refund states short of the full price.
     */
    public function isEntitling(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }

    /**
     * Determine whether a payment outcome may still land on this order.
     */
    public function isPayable(): bool
    {
        return in_array($this, [self::Pending, self::Processing], true);
    }

    /**
     * Determine whether an order in this state may still be refunded.
     */
    public function isRefundable(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }

    /**
     * Determine whether no transition is possible out of this state.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Get the states this one may move to.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Paid, self::Failed, self::Cancelled],
            self::Processing => [self::Paid, self::Failed, self::Cancelled],
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Failed, self::Cancelled, self::Refunded => [],
        };
    }

    /**
     * Determine whether the move from this state to the given one is legal.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
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
