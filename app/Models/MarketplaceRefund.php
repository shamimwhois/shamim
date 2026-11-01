<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MarketplaceRefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of money back to a buyer.
 *
 * Refunds are rows rather than a column so a partial refund is a sequence an
 * operator can read: the order's status is an aggregate over these amounts,
 * and each movement keeps the reason and the account that issued it.
 * `processed_by` is nulled on account deletion so the money trail survives
 * the person.
 *
 * @property int $id
 * @property int $marketplace_order_id
 * @property int $amount_cents
 * @property string $currency
 * @property RefundStatus $status
 * @property string|null $reason
 * @property int|null $processed_by
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read MarketplaceOrder $order
 * @property-read User|null $author
 */
#[Fillable([
    'marketplace_order_id',
    'amount_cents',
    'currency',
    'status',
    'reason',
    'processed_by',
    'processed_at',
])]
class MarketplaceRefund extends Model
{
    /** @use HasFactory<MarketplaceRefundFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_cents' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Get the order this refund was issued against.
     *
     * @return BelongsTo<MarketplaceOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    /**
     * Get the operator account that issued the refund.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Get the amount as a display string.
     */
    public function formattedAmount(): string
    {
        return MarketplaceProduct::symbolFor($this->currency).number_format($this->amount_cents / 100, 2);
    }

    /**
     * Get the moment the ledger should date this movement from.
     *
     * An unprocessed refund has no `processed_at`, so the row falls back to
     * the instant it opened — a ledger row without a date is unsortable.
     */
    public function occurredAt(): CarbonImmutable
    {
        return $this->processed_at ?? $this->created_at ?? CarbonImmutable::now();
    }

    /**
     * Scope to refunds against one buyer's orders.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forBuyer(Builder $query, int $userId): void
    {
        $query->whereHas(
            'order',
            fn (Builder $orderQuery) => $orderQuery->where('user_id', $userId),
        );
    }

    /**
     * Scope to refunds in one status.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function status(Builder $query, RefundStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope to refunds whose reason, or whose order number, matches a term.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $query->where(function (Builder $inner) use ($pattern): void {
            $inner->where('reason', 'like', $pattern)
                ->orWhereHas(
                    'order',
                    fn (Builder $orderQuery) => $orderQuery->where('order_number', 'like', $pattern),
                );
        });
    }
}
