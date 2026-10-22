<?php

namespace App\Actions\Feed;

use App\Models\FeedAttachment;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Remove one attached image, the row and the bytes together.
 *
 * A composer that deletes the record and leaves the file behind leaks storage on
 * every abandoned draft, and a file deleted with its row still referenced would
 * leave the card rendering a broken image. Both halves go in one call, row first
 * so a reader who races the delete sees no image rather than a missing one.
 */
class DetachFeedMedia
{
    /**
     * Delete the attachment and its stored file.
     *
     * Returns whether the row existed to be deleted, so a caller acting on a
     * stale composer state (the file was already removed in another tab) can
     * report honestly instead of claiming a deletion that never happened.
     */
    public function handle(FeedAttachment $attachment): bool
    {
        $deleted = (bool) $attachment->delete();

        $this->forgetFile($attachment);

        return $deleted;
    }

    /**
     * Remove the bytes from the disk they were written to.
     */
    private function forgetFile(FeedAttachment $attachment): void
    {
        try {
            Storage::disk($attachment->disk)->delete($attachment->path);
        } catch (Throwable $exception) {
            // The row is gone, so nothing can reach the file again and the prune
            // command treats it as an orphan. Failing the user's remove click
            // over a leftover byte would be the worse outcome here.
            report($exception);
        }
    }
}
