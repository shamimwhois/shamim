<?php

namespace App\Actions\Marketplace;

use App\Concerns\StoresVerifiedUpload;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Move a seller's upload onto the private listing media disk and record it.
 *
 * What the row ends up claiming about a file is read back from the disk, never
 * taken from the request — see {@see StoresVerifiedUpload} for that discipline and
 * for why a rejected upload leaves neither a row nor a byte behind.
 *
 * The cap is enforced here rather than in the editor: the screen limits what it
 * offers, but only the action sees every write, so the count that decides whether a
 * screenshot may exist at all is decided where the screenshot is made.
 *
 * Unlike a composer upload there is no staged state — the editor only offers the
 * uploader to a listing that already has an id — so every row written here has a
 * product from the start and no orphaned file can accumulate.
 */
class AttachProductScreenshot
{
    use StoresVerifiedUpload;

    /** The private disk the bytes live on. Never served by path. */
    public const DISK = 'marketplace_media';

    /**
     * Store each upload and return the rows that describe them.
     *
     * The seller hands over Livewire's `TemporaryUploadedFile` staging copies; the
     * type is widened to the framework's `UploadedFile` so the verb also accepts any
     * other validated upload source without changing what it does with the bytes.
     *
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, MarketplaceProductMedia>
     */
    public function handle(MarketplaceProduct $product, User $seller, array $files): Collection
    {
        if (! $product->isOwnedBy($seller)) {
            throw ValidationException::withMessages([
                'uploads' => __('You may not add screenshots to this listing.'),
            ]);
        }

        if ($files === []) {
            return Collection::make();
        }

        $this->assertRoomInGallery($product, count($files));

        $stored = Collection::make();

        foreach ($files as $file) {
            $stored->push($this->storeOne($product, $file, $stored->count()));
        }

        return $stored;
    }

    /**
     * Write one file to the disk and record what the disk says about it.
     */
    private function storeOne(MarketplaceProduct $product, UploadedFile $file, int $offset): MarketplaceProductMedia
    {
        $path = $this->targetPath($product, $file);

        $facts = $this->storeAndInspect(
            $this->diskName(),
            $path,
            $file,
            $this->acceptedMimes(),
            __('That image could not be stored. Try a different file.')
        );

        $media = MarketplaceProductMedia::query()->create([
            'marketplace_product_id' => $product->id,
            'disk' => $this->diskName(),
            'path' => $path,
            'mime' => $facts['mime'],
            'size' => $facts['size'],
            'width' => $facts['width'],
            'height' => $facts['height'],
            'alt_text' => null,
            'sort_order' => (int) $product->media()->max('sort_order') + $offset + 1,
        ]);

        $this->discardStagedCopy($file);

        return $media;
    }

    /**
     * Where the file goes: one folder per listing, one unpredictable name per file.
     *
     * The name is a UUID rather than the seller's filename because the uploaded name
     * is attacker-chosen text that would otherwise be echoed into a path, and `../`
     * in a filename is a real thing people upload.
     */
    private function targetPath(MarketplaceProduct $product, UploadedFile $file): string
    {
        $extension = Str::lower((string) $file->extension());
        $suffix = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? ".{$extension}" : '';

        return "listings/{$product->id}/".Str::uuid()->toString().$suffix;
    }

    /**
     * The gallery has to fit the plan's cap, counted before any byte is written.
     */
    private function assertRoomInGallery(MarketplaceProduct $product, int $incoming): void
    {
        $limit = (int) config('marketplace.media.max_per_listing', 8);
        $existing = $product->media()->count();

        if ($existing + $incoming > $limit) {
            throw ValidationException::withMessages([
                'uploads' => __('A listing shows :limit screenshots, and this one already has :existing.', [
                    'limit' => $limit,
                    'existing' => $existing,
                ]),
            ]);
        }
    }

    /**
     * The image formats a screenshot may be, shared with the editor's file rules.
     *
     * @return array<int, string>
     */
    private function acceptedMimes(): array
    {
        return (array) config('marketplace.media.accepted_mimes', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    /**
     * The disk name, read from config so the media table and the filesystem agree.
     */
    private function diskName(): string
    {
        return (string) config('marketplace.media.disk', self::DISK);
    }
}
