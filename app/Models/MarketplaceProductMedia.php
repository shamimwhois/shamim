<?php

namespace App\Models;

use Database\Factories\MarketplaceProductMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * One screenshot attached to a marketplace listing.
 *
 * The bytes live on a private disk and are never reachable by path: the only way
 * to read a file is the signed `marketplace.media.show` route, which re-runs the
 * listing's visibility rule before it opens the stream. Everything the row claims
 * about the file — mime, size, dimensions — was read back from the disk after the
 * move, so a Content-Type forged in the upload never becomes the truth a browser
 * is served.
 *
 * Unlike a composer upload, a screenshot always has a listing. The editor only
 * offers the uploader once the draft has an id, so there is no staged, unclaimed
 * state to prune here.
 *
 * @property int $id
 * @property int $marketplace_product_id
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MarketplaceProduct $product
 */
#[Fillable([
    'marketplace_product_id',
    'disk',
    'path',
    'mime',
    'size',
    'width',
    'height',
    'alt_text',
    'sort_order',
])]
class MarketplaceProductMedia extends Model
{
    /** @use HasFactory<MarketplaceProductMediaFactory> */
    use HasFactory;

    /**
     * How long a signed media URL stays valid.
     *
     * Short on purpose: it is a permission to stream one image, not a link a
     * seller puts in their own documentation. A gallery that outlives it re-signs
     * on the next page load.
     */
    public const URL_TTL_MINUTES = 10;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The listing this screenshot belongs to.
     *
     * @return BelongsTo<MarketplaceProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketplaceProduct::class, 'marketplace_product_id');
    }

    /**
     * The signed URL a viewer may load the file from.
     */
    public function url(): string
    {
        return URL::temporarySignedRoute(
            'marketplace.media.show',
            now()->addMinutes(self::URL_TTL_MINUTES),
            ['product' => $this->marketplace_product_id, 'media' => $this->id],
        );
    }

    /**
     * The rendered alt text, never blank in a way that strands a screen reader.
     */
    public function alt(): string
    {
        return $this->alt_text !== null && trim($this->alt_text) !== ''
            ? $this->alt_text
            : __('Screenshot of :name', ['name' => $this->product?->name ?? __('this listing')]);
    }

    /**
     * The file size formatted for the seller's upload list.
     */
    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max(1, (int) $this->size);
        $power = min(count($units) - 1, (int) floor(log($bytes, 1024)));

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }
}
