<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Database\Factories\MarketplaceReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A moderated review of a marketplace product.
 *
 * One row per account per product, enforced by a unique index. The rating is
 * the only input to a product's average; nothing aggregates a pending or
 * rejected review, so a product cannot be made to look better than its
 * published reviews say.
 *
 * @property int $id
 * @property int $marketplace_product_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $title
 * @property string $body
 * @property ReviewStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MarketplaceProduct $product
 * @property-read User $author
 */
#[Fillable(['marketplace_product_id', 'user_id', 'rating', 'title', 'body', 'status'])]
class MarketplaceReview extends Model
{
    /** @use HasFactory<MarketplaceReviewFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'rating' => 'integer',
        ];
    }

    /**
     * Get the product being reviewed.
     *
     * @return BelongsTo<MarketplaceProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketplaceProduct::class, 'marketplace_product_id');
    }

    /**
     * Get the account that wrote the review.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope to reviews the public may read.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('status', ReviewStatus::Approved);
    }

    /**
     * Scope to reviews waiting on a moderator.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', ReviewStatus::Pending);
    }
}
