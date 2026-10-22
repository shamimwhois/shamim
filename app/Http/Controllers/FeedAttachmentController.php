<?php

namespace App\Http\Controllers;

use App\Models\FeedAttachment;
use App\Models\FeedPost;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream one composer image out of the private feed media disk.
 *
 * The file's bytes are never reachable by path — `feed_media` is configured with
 * `'serve' => false`, so no stock storage route exists for it — which makes this
 * the only door. Two locks stand in front of it, and they are different locks:
 *
 * The signature proves the *link* is genuine and unexpired. It says nothing about
 * the reader, so it is checked first and cheapest, and it is what stops someone
 * from minting a URL to a file id they picked.
 *
 * The policy proves the *reader* may see the post the file is attached to. A
 * signed URL outliving its ten minutes, being forwarded, or outliving a change of
 * audience all fail here, because the visibility rule is re-run against the
 * request, not remembered from the moment the link was made.
 *
 * A file with no post yet — staged by an open composer — answers to its uploader
 * alone, which is the only claim that exists on it before the row is written.
 * The class string is passed to Gate so the policy still resolves when that post
 * is null; without it there would be no model to infer `FeedPostPolicy` from and
 * the preview the composer promises would 404 on every draft image.
 *
 * Failure answers 404 rather than 403 wherever the post is the reason: a private
 * post that exists must be indistinguishable from a post that does not.
 */
class FeedAttachmentController extends Controller
{
    /**
     * Serve the attachment's bytes to a reader entitled to the parent post.
     */
    public function show(Request $request, FeedAttachment $attachment): StreamedResponse
    {
        abort_unless(URL::hasValidSignature($request), 400);

        abort_unless(Gate::allows('viewAttachment', [FeedPost::class, $attachment->post, $attachment]), 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response(
            $attachment->path,
            basename($attachment->path),
            $this->headers($attachment),
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
     * Content-Disposition is left to the filesystem adapter, which builds it from
     * the name passed above; the stored path is a UUID, so there is no uploaded
     * filename in the response for a quoting bug to live in.
     *
     * @return array<string, string>
     */
    private function headers(FeedAttachment $attachment): array
    {
        return [
            'Content-Type' => $attachment->mime,
            'Content-Length' => (string) $attachment->size,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
        ];
    }
}
