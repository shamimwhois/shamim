<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductMedia;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream one listing screenshot out of the private marketplace media disk.
 *
 * `marketplace_media` is configured with `'serve' => false`, so the bytes have no
 * path they can be reached by and this is the only door. Two locks stand in front
 * of it, and they answer different questions:
 *
 * The signature proves the *link* is genuine and unexpired. It says nothing about
 * the reader, so it is checked first because it is cheapest, and it is what stops
 * someone from minting a URL to a media id they picked.
 *
 * The visibility rule proves the *listing* may be seen. A screenshot belongs to a
 * listing, so a published listing answers to anyone while a draft answers to its
 * seller alone. Neither check is remembered from the moment the link was made: a
 * listing withdrawn after its URL was handed around stops serving immediately.
 *
 * Failure answers 404 rather than 403 in both cases. A draft that exists must be
 * indistinguishable from a draft that does not, which is the same rule the listing
 * editor already follows, and it means this route leaks nothing about what other
 * accounts have sitting in their shelves.
 */
class MarketplaceMediaController extends Controller
{
    /**
     * Serve the screenshot's bytes to a reader entitled to the parent listing.
     */
    public function show(Request $request, MarketplaceProduct $product, MarketplaceProductMedia $media): StreamedResponse
    {
        abort_unless(URL::hasValidSignature($request), 400);

        // The signed link names a listing and a file; they must actually be the
        // pair the row describes before either one is trusted.
        abort_unless($media->marketplace_product_id === $product->id, 404);

        abort_unless(
            $product->status->isPubliclyVisible() || $product->isOwnedBy($request->user()),
            404
        );

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($media->disk);

        abort_unless($disk->exists($media->path), 404);

        return $disk->response(
            $media->path,
            basename($media->path),
            $this->headers($media),
        );
    }

    /**
     * Response headers that describe the file the way the bytes actually are.
     *
     * `Content-Type` comes from the column, which was filled by asking the disk
     * what it stored — never from anything the uploading browser said. The two
     * security headers are the reason that matters: a file served from the same
     * origin as the app can otherwise read the app's cookies, so the response is
     * fenced into being an inert document that no script may run inside.
     *
     * @return array<string, string>
     */
    private function headers(MarketplaceProductMedia $media): array
    {
        return [
            'Content-Type' => $media->mime,
            'Content-Length' => (string) $media->size,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
        ];
    }
}
