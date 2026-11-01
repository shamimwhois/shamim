<?php

namespace App\Models;

use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MarketplacePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment attempt against an order.
 *
 * The attempt is its own record so the gateway's answer and the platform's
 * commercial decision stay distinguishable: an order is `Paid` because this
 * row says the instrument captured. In the sandbox a failed attempt is
 * terminal, so an order carries at most one payment and a retry means a new
 * checkout.
 *
 * @property int $id
 * @property int $marketplace_order_id
 * @property PaymentProvider $provider
 * @property PaymentMode $mode
 * @property int $amount_cents
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $reference
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read MarketplaceOrder $order
 */
#[Fillable([
    'marketplace_order_id',
    'provider',
    'mode',
    'amount_cents',
    'currency',
    'status',
    'reference',
    'failure_reason',
    'processed_at',
])]
class MarketplacePayment extends Model
{
    /** @use HasFactory<MarketplacePaymentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'mode' => PaymentMode::class,
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Get the order this attempt belongs to.
     *
     * @return BelongsTo<MarketplaceOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    /**
     * Get the amount as a display string.
     */
    public function formattedAmount(): string
    {
        return MarketplaceProduct::symbolFor($this->currency).number_format($this->amount_cents / 100, 2);
    }

    /**
     * Get the moment the ledger should date this attempt from.
     *
     * An unsettled attempt has no `processed_at`, and a history row without a
     * date is unsortable — so the row falls back to the instant it opened. The
     * type is {@see CarbonImmutable} because this application installs an
     * immutable date factory; the docblock properties above carry the same
     * spelling for consistency.
     *
     * `created_at` is only null on a row that was never saved, so the cast is
     * safe and the return is always a date.
     */
    public function occurredAt(): CarbonImmutable
    {
        return $this->processed_at ?? $this->created_at ?? CarbonImmutable::now();
    }

    /**
     * Scope to attempts against one buyer's orders.
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
     * Scope to attempts in one status.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function status(Builder $query, PaymentStatus $status): void
    {
        $query->where('marketplace_payments.status', $status);
    }

    /**
     * Scope to attempts made through one gateway.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function provider(Builder $query, PaymentProvider $provider): void
    {
        $query->where('provider', $provider);
    }

    /**
     * Scope to attempts made in one gateway mode.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function mode(Builder $query, PaymentMode $mode): void
    {
        $query->where('mode', $mode);
    }

    /**
     * Scope to attempts whose reference, or whose order number, matches a term.
     *
     * Escaped the same way as the order ledger so a typed `%` is a literal
     * character rather than a wildcard, and the order number is searched
     * through the relation rather than a join, which would make `status`
     * ambiguous against the filters stacked on top of it.
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
            $inner->where('reference', 'like', $pattern)
                ->orWhereHas(
                    'order',
                    fn (Builder $orderQuery) => $orderQuery->where('order_number', 'like', $pattern),
                );
        });
    }
}
