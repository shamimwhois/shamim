<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Enums\RefundStatus;
use Database\Factories\MarketplaceOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A purchase in the marketplace — and, by its status, the buyer's entitlement.
 *
 * ## What is derived, never stored
 *
 * There is no separate grants table. A buyer holds a listing exactly when an
 * order for (buyer, product) stands in an entitling status
 * ({@see OrderStatus::isEntitling()}), so entitlement cannot drift from the
 * money that paid for it, and a duplicate purchase cannot grant twice.
 *
 * ## What is copied, not joined
 *
 * `product_name`, `product_slug`, `price_cents` and `currency` are snapshots
 * taken at purchase. Listings are renamed, repriced and deleted; a receipt
 * must still read correctly afterwards, and the consoles must never dereference
 * the (nullable) product for display. The buyer's side is copied for the same
 * reason — `billing_*` freezes who an invoice was issued to, so editing a
 * billing profile later cannot restate a document that already exists.
 *
 * ## Money
 *
 * Amounts are integer minor units with an ISO-4217 code, exactly as on the
 * listing — see {@see MarketplaceProduct} for why formatting lives at the edge.
 *
 * @property int $id
 * @property string $order_number
 * @property int $user_id
 * @property int|null $marketplace_product_id
 * @property string $product_name
 * @property string $product_slug
 * @property int $price_cents
 * @property string $currency
 * @property string|null $billing_name
 * @property string|null $billing_company
 * @property string|null $billing_tax_id
 * @property string|null $billing_email
 * @property string|null $billing_address
 * @property int $commission_cents
 * @property OrderStatus $status
 * @property PaymentProvider|null $payment_provider
 * @property PaymentMode|null $payment_mode
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $refunded_at
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $buyer
 * @property-read MarketplaceProduct|null $product
 * @property-read Collection<int, MarketplacePayment> $payments
 * @property-read MarketplacePayment|null $currentPayment
 * @property-read Collection<int, MarketplaceRefund> $refunds
 */
#[Fillable([
    'order_number',
    'user_id',
    'marketplace_product_id',
    'product_name',
    'product_slug',
    'price_cents',
    'currency',
    'billing_name',
    'billing_company',
    'billing_tax_id',
    'billing_email',
    'billing_address',
    'commission_cents',
    'status',
    'payment_provider',
    'payment_mode',
    'paid_at',
    'cancelled_at',
    'refunded_at',
    'failure_reason',
])]
class MarketplaceOrder extends Model
{
    /** @use HasFactory<MarketplaceOrderFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_provider' => PaymentProvider::class,
            'payment_mode' => PaymentMode::class,
            'price_cents' => 'integer',
            'commission_cents' => 'integer',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * Get the account that placed the order.
     *
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the listing that was bought, when it still exists.
     *
     * @return BelongsTo<MarketplaceProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketplaceProduct::class, 'marketplace_product_id');
    }

    /**
     * Get every payment attempt recorded against the order.
     *
     * @return HasMany<MarketplacePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(MarketplacePayment::class);
    }

    /**
     * Get the attempt the order's money now depends on.
     *
     * @return HasOne<MarketplacePayment, $this>
     */
    public function currentPayment(): HasOne
    {
        return $this->hasOne(MarketplacePayment::class)->latestOfMany();
    }

    /**
     * Get every refund issued against the order.
     *
     * @return HasMany<MarketplaceRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(MarketplaceRefund::class);
    }

    /**
     * Scope to the orders belonging to one buyer.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forBuyer(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    /**
     * Scope to sales of the listings one account publishes.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forSellerProducts(Builder $query, int $userId): void
    {
        $query->whereHas(
            'product',
            fn (Builder $productQuery) => $productQuery->where('user_id', $userId),
        );
    }

    /**
     * Scope to orders that grant their buyer access.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function entitling(Builder $query): void
    {
        $query->whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded]);
    }

    /**
     * Scope to orders in one status.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function status(Builder $query, OrderStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope to orders matching a free-text term.
     *
     * Searches the order number and the snapshotted product name. Wildcards in
     * the term are escaped so a user typing `%` matches a literal percent sign.
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

        $query->where(function (Builder $query) use ($pattern): void {
            $query->where('order_number', 'like', $pattern)
                ->orWhere('product_name', 'like', $pattern);
        });
    }

    /**
     * Determine whether the given buyer already holds this product.
     */
    public function isEntitling(): bool
    {
        return $this->status->isEntitling();
    }

    /**
     * Determine whether the given account owns the order.
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    /**
     * Determine whether the buyer already holds an entitling order for the product.
     */
    public static function entitledFor(User $buyer, MarketplaceProduct $product): bool
    {
        return static::query()
            ->where('user_id', $buyer->id)
            ->where('marketplace_product_id', $product->getKey())
            ->entitling()
            ->exists();
    }

    /**
     * Get the total refunded in minor units.
     */
    public function refundedCents(): int
    {
        return (int) $this->refunds()
            ->where('status', RefundStatus::Succeeded)
            ->sum('amount_cents');
    }

    /**
     * Get the amount still refundable in minor units.
     */
    public function refundableCents(): int
    {
        return max(0, $this->price_cents - $this->refundedCents());
    }

    /**
     * Get the paid amount as a display string.
     */
    public function formattedAmount(): string
    {
        return $this->formattedAmountFor($this->price_cents);
    }

    /**
     * Get the refunded amount as a display string.
     */
    public function formattedRefundedAmount(): string
    {
        return $this->formattedAmountFor($this->refundedCents());
    }

    /**
     * Format an arbitrary minor-unit amount in this order's currency.
     */
    public function formattedAmountFor(int $cents): string
    {
        return MarketplaceProduct::symbolFor($this->currency).number_format($cents / 100, 2);
    }

    /**
     * Get the invoice number, or null while nothing is payable.
     *
     * The number is derived rather than stored: an order is invoiced exactly
     * once, when money is captured, so the capture year plus the row's own id
     * is a stable, gap-free label. A counter column would need somewhere to
     * live, a rule for what happens when a row is deleted, and a year boundary
     * to reset on — all to produce a string nobody reconciles against a ledger.
     */
    public function invoiceNumber(): ?string
    {
        return $this->paid_at === null
            ? null
            : sprintf('INV-%s-%05d', $this->paid_at->format('Y'), $this->id);
    }

    /**
     * Generate a human-quotable order number.
     */
    public static function generateOrderNumber(): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }
}
