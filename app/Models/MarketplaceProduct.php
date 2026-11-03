<?php

namespace App\Models;

use App\Concerns\HasSlug;
use App\Enums\AccountPermission;
use App\Enums\ProductLicense;
use App\Enums\ProductStatus;
use App\Enums\ReviewStatus;
use Database\Factories\MarketplaceProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A listing in the developer marketplace.
 *
 * ## What is derived, never stored
 *
 * Ratings and review counts are computed from approved reviews. There is no
 * `rating` column to fall out of date, and no seeded number that could claim
 * popularity the platform has not earned. `download_count` is the one stored
 * counter, because it records events rather than content.
 *
 * ## Money
 *
 * `price_cents` is an integer of minor units and `currency` is an ISO-4217
 * code. Formatting happens at the edge ({@see self::formattedPrice()}) so the
 * stored value never depends on a locale.
 *
 * @property int $id
 * @property int|null $marketplace_category_id
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property string $tagline
 * @property string $description
 * @property int $price_cents
 * @property string $currency
 * @property ProductLicense $license
 * @property string $version
 * @property string|null $framework
 * @property string|null $language
 * @property string|null $install_command
 * @property string|null $demo_url
 * @property string|null $docs_url
 * @property string|null $repository_url
 * @property array<int, string>|null $tags
 * @property ProductStatus $status
 * @property bool $is_featured
 * @property int $download_count
 * @property Carbon|null $published_at
 * @property Carbon|null $last_released_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read MarketplaceCategory|null $category
 * @property-read User $creator
 * @property-read Collection<int, MarketplaceProductVersion> $versions
 * @property-read MarketplaceProductVersion|null $currentVersion
 * @property-read Collection<int, MarketplaceProductMedia> $media
 * @property-read Collection<int, MarketplaceReview> $reviews
 * @property-read Collection<int, MarketplaceReview> $approvedReviews
 */
#[Fillable([
    'marketplace_category_id',
    'user_id',
    'name',
    'slug',
    'tagline',
    'description',
    'price_cents',
    'currency',
    'license',
    'version',
    'framework',
    'language',
    'install_command',
    'demo_url',
    'docs_url',
    'repository_url',
    'tags',
    'status',
    'is_featured',
    'published_at',
    'last_released_at',
])]
class MarketplaceProduct extends Model
{
    /** @use HasFactory<MarketplaceProductFactory> */
    use HasFactory, HasSlug, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'license' => ProductLicense::class,
            'tags' => 'array',
            'price_cents' => 'integer',
            'is_featured' => 'boolean',
            'download_count' => 'integer',
            'published_at' => 'datetime',
            'last_released_at' => 'datetime',
        ];
    }

    /**
     * Get the attribute used for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the category the listing belongs to.
     *
     * @return BelongsTo<MarketplaceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MarketplaceCategory::class, 'marketplace_category_id');
    }

    /**
     * Get the account that publishes the listing.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get every release of the product, newest first.
     *
     * @return HasMany<MarketplaceProductVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(MarketplaceProductVersion::class)->latest('released_at');
    }

    /**
     * Get the release currently advertised as current.
     *
     * @return HasOne<MarketplaceProductVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(MarketplaceProductVersion::class)->where('is_current', true);
    }

    /**
     * Get the screenshots in the order the seller arranged them.
     *
     * @return HasMany<MarketplaceProductMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(MarketplaceProductMedia::class)->orderBy('sort_order');
    }

    /**
     * Get every review on the product, moderated or not.
     *
     * @return HasMany<MarketplaceReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(MarketplaceReview::class)->latest();
    }

    /**
     * Get the reviews the public may read.
     *
     * @return HasMany<MarketplaceReview, $this>
     */
    public function approvedReviews(): HasMany
    {
        return $this->reviews()->where('status', ReviewStatus::Approved);
    }

    /**
     * Get the purchase orders placed against this listing.
     *
     * @return HasMany<MarketplaceOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(MarketplaceOrder::class);
    }

    /**
     * Scope to listings the public may read.
     *
     * The single definition of "publicly readable": a published status and a
     * publish timestamp. Soft-deleted rows are excluded by Eloquent.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', ProductStatus::Published)
            ->whereNotNull('published_at');
    }

    /**
     * Scope to listings flagged for the front page.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function featured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    /**
     * Scope to listings waiting on an operator.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function pendingReview(Builder $query): void
    {
        $query->where('status', ProductStatus::PendingReview);
    }

    /**
     * Scope to listings that occupy a slot in their author's plan quota.
     *
     * A draft takes no room — it is private and may be abandoned — and an
     * archived one has already been withdrawn from the catalogue. What the cap
     * counts is the shelf an author actually holds open: submitted or live.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function occupyingQuota(Builder $query): void
    {
        $query->whereIn('status', [ProductStatus::PendingReview, ProductStatus::Published]);
    }

    /**
     * Scope to listings that cost nothing.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function free(Builder $query): void
    {
        $query->where('price_cents', 0);
    }

    /**
     * Scope to listings with a price.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function paid(Builder $query): void
    {
        $query->where('price_cents', '>', 0);
    }

    /**
     * Scope to listings in the given category slug.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inCategory(Builder $query, string $categorySlug): void
    {
        $query->whereHas(
            'category',
            fn (Builder $categoryQuery) => $categoryQuery->where('slug', $categorySlug),
        );
    }

    /**
     * Scope to listings matching a free-text term.
     *
     * Searches the name, tagline and description. Wildcards in the term are
     * escaped so a user typing `%` matches a literal percent sign.
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

        $pattern = '%'.$this->escapeLikeValue($term).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            $query->where('name', 'like', $pattern)
                ->orWhere('tagline', 'like', $pattern)
                ->orWhere('description', 'like', $pattern)
                ->orWhere('framework', 'like', $pattern)
                ->orWhere('language', 'like', $pattern);
        });
    }

    /**
     * Scope to listings from the same category, excluding this one.
     *
     * Falls back to the same framework when the listing has no category, so the
     * related rail is never empty purely because a seller skipped that field.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function relatedTo(Builder $query, self $product, int $limit = 3): void
    {
        $query->published()->whereKeyNot($product->getKey());

        if ($product->marketplace_category_id !== null) {
            $query->where('marketplace_category_id', $product->marketplace_category_id);
        } elseif ($product->framework !== null) {
            $query->where('framework', $product->framework);
        }

        $query->orderByDesc('is_featured')->orderByDesc('published_at')->limit($limit);
    }

    /**
     * Apply one of the catalogue's sort orders.
     *
     * `rating` relies on the `approved_reviews_avg_rating` aggregate that the
     * catalogue query selects; without it the clause falls back to newest,
     * which is still a valid ordering rather than an error.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function sortedBy(Builder $query, string $sort): void
    {
        match ($sort) {
            'newest' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'updated' => $query->orderByDesc('last_released_at')->orderByDesc('published_at'),
            'price-low' => $query->orderBy('price_cents')->orderByDesc('published_at'),
            'price-high' => $query->orderByDesc('price_cents')->orderByDesc('published_at'),
            'rating' => $query->orderByDesc('approved_reviews_avg_rating')->orderByDesc('approved_reviews_count'),
            'downloads' => $query->orderByDesc('download_count')->orderByDesc('published_at'),
            default => $query->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * Escape LIKE wildcards in a user-supplied value.
     */
    public function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Determine whether the listing is live.
     */
    public function isPublished(): bool
    {
        return $this->status === ProductStatus::Published && $this->published_at !== null;
    }

    /**
     * Determine whether the listing costs nothing.
     */
    public function isFree(): bool
    {
        return $this->price_cents === 0;
    }

    /**
     * What the listing still owes before an operator can judge it.
     *
     * A submission is a request for somebody's attention, so the bar is the
     * fields a reviewer reads: a name, a promise, a description, a currency to
     * price it in and a shelf to file it on. A price of zero is not a gap —
     * free listings are a thing this platform sells.
     *
     * @return array<int, string>
     */
    public function missingSubmissionFields(): array
    {
        $fields = [
            'name' => __('name'),
            'tagline' => __('tagline'),
            'description' => __('description'),
            'currency' => __('currency'),
            'marketplace_category_id' => __('category'),
        ];

        return collect($fields)
            ->filter(fn (string $label, string $column): bool => trim((string) $this->{$column}) === '')
            ->values()
            ->all();
    }

    /**
     * Determine whether the listing carries enough to be reviewed.
     */
    public function isReadyForSubmission(): bool
    {
        return $this->missingSubmissionFields() === [];
    }

    /**
     * Determine whether the given account may read this listing.
     *
     * A published listing is readable by anyone. Otherwise only its creator and
     * accounts that review products may see it — the same rule the route uses,
     * expressed once.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($this->isPublished()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $this->user_id === $user->id
            || $user->hasPermission(AccountPermission::ReviewProducts);
    }

    /**
     * Determine whether the given account owns this listing.
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    /**
     * Get the price in major units, e.g. 19.99.
     */
    public function priceInMajorUnits(): float
    {
        return $this->price_cents / 100;
    }

    /**
     * Get the price as a display string, or "Free".
     */
    public function formattedPrice(): string
    {
        if ($this->isFree()) {
            return __('Free');
        }

        return $this->currencySymbol().number_format($this->priceInMajorUnits(), 2);
    }

    /**
     * Get the currency symbol for the listing's currency.
     */
    public function currencySymbol(): string
    {
        return self::symbolFor($this->currency);
    }

    /**
     * Get the display symbol for an arbitrary ISO-4217 currency code.
     *
     * Shared with orders because a receipt renders the currency it was paid
     * in, not the one the listing carries today. A fixed map rather than
     * `NumberFormatter` because the `intl` extension is not guaranteed to be
     * installed, and a storefront that 500s when it is missing is worse than
     * one that shows `BDT 1,500.00`.
     */
    public static function symbolFor(string $currency): string
    {
        $code = strtoupper($currency);

        return match ($code) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'BDT' => '৳',
            'INR' => '₹',
            default => $code.' ',
        };
    }

    /**
     * Get the average rating from approved reviews, or null when unrated.
     *
     * Reads the `approved_reviews_avg_rating` aggregate when the query selected
     * it, so a catalogue of twenty cards costs one query rather than twenty.
     */
    public function averageRating(): ?float
    {
        $aggregate = array_key_exists('approved_reviews_avg_rating', $this->attributes)
            ? $this->attributes['approved_reviews_avg_rating']
            : $this->approvedReviews()->avg('rating');

        return $aggregate === null ? null : round((float) $aggregate, 1);
    }

    /**
     * Get the number of approved reviews.
     */
    public function reviewCount(): int
    {
        if (array_key_exists('approved_reviews_count', $this->attributes)) {
            return (int) $this->attributes['approved_reviews_count'];
        }

        return $this->approvedReviews()->count();
    }

    /**
     * Get the download count as a short label, e.g. "1.2k".
     */
    public function downloadLabel(): string
    {
        if ($this->download_count < 1000) {
            return (string) $this->download_count;
        }

        return rtrim(rtrim(number_format($this->download_count / 1000, 1), '0'), '.').'k';
    }
}
