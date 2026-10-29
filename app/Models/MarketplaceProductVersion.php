<?php

namespace App\Models;

use Database\Factories\MarketplaceProductVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One release of a marketplace product.
 *
 * The deliverable columns are all nullable, and that is the point: a release whose
 * install command is the whole installation has nothing to stream, and the buyer's
 * screen has to be able to tell the difference between "no file" and "a file you
 * are not entitled to yet".
 *
 * @property int $id
 * @property int $marketplace_product_id
 * @property string $version
 * @property string|null $release_notes
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $mime
 * @property int|null $size
 * @property string|null $original_name
 * @property bool $is_current
 * @property Carbon $released_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MarketplaceProduct|null $product
 */
#[Fillable([
    'marketplace_product_id',
    'version',
    'release_notes',
    'disk',
    'path',
    'mime',
    'size',
    'original_name',
    'is_current',
    'released_at',
])]
class MarketplaceProductVersion extends Model
{
    /** @use HasFactory<MarketplaceProductVersionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'released_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    /**
     * Determine whether this release has a file a buyer may download.
     */
    public function hasDeliverable(): bool
    {
        return $this->path !== null && trim($this->path) !== '';
    }

    /**
     * The filename the buyer's browser is offered for the deliverable.
     */
    public function deliverableName(): string
    {
        if ($this->original_name !== null && trim($this->original_name) !== '') {
            return $this->original_name;
        }

        // See MarketplaceProductMedia::alt() — a deleted listing is
        // soft-deleted, so the release row outlives it and the relation
        // resolves to null.
        return ($this->product->slug ?? 'release').'-'.$this->version.'.zip';
    }

    /**
     * The deliverable's size formatted for the seller's release panel.
     */
    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max(1, (int) $this->size);
        $power = min(count($units) - 1, (int) floor(log($bytes, 1024)));

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }

    /**
     * Get the product the release belongs to.
     *
     * @return BelongsTo<MarketplaceProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketplaceProduct::class, 'marketplace_product_id');
    }

    /**
     * Get the release notes split into lines, for list rendering.
     *
     * @return array<int, string>
     */
    public function notes(): array
    {
        if ($this->release_notes === null || trim($this->release_notes) === '') {
            return [];
        }

        return collect(preg_split('/\R/', $this->release_notes) ?: [])
            ->map(fn (string $line) => trim(ltrim($line, "-* \t")))
            ->filter()
            ->values()
            ->all();
    }
}
