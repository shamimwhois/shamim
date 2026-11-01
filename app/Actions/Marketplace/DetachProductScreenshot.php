<?php

namespace App\Actions\Marketplace;

use App\Models\MarketplaceProductMedia;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Remove one screenshot, the row and the bytes together.
 *
 * Both halves go in one call, and the row goes first: a seller who deletes an image
 * while a reader has the listing open should see the tile disappear rather than a
 * broken image. A file deleted with its row still referenced would strand a tile
 * that 404s, and a row deleted with its bytes still on disk leaks storage on every
 * listing the seller tidies.
 *
 * Ownership is re-checked here rather than trusted from the screen, because the
 * media id arrives from a form field and any listing's screenshot answers to the
 * same route.
 */
class DetachProductScreenshot
{
    /**
     * Delete the screenshot and its stored file.
     */
    public function handle(User $seller, MarketplaceProductMedia $media): bool
    {
        if (! $media->product->isOwnedBy($seller)) {
            throw new InvalidArgumentException(__('You may not remove screenshots from this listing.'));
        }

        $deleted = (bool) $media->delete();

        $this->forgetFile($media);

        return $deleted;
    }

    /**
     * Remove the bytes from the disk they were written to.
     */
    private function forgetFile(MarketplaceProductMedia $media): void
    {
        try {
            Storage::disk($media->disk)->delete($media->path);
        } catch (Throwable $exception) {
            // The row is gone, so nothing can reach the file again. Failing the
            // seller's remove click over a leftover byte would be the worse
            // outcome here.
            report($exception);
        }
    }
}
