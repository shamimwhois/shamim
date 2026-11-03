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
 * Stream one composer attachment out of the private feed media disk.
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
 * the preview the composer promises would 404 on every draft file.
 *
 * Failure answers 404 rather than 403 wherever the post is the reason: a private
 * post that exists must be indistinguishable from a post that does not.
 *
 * **Two kinds, two dispositions.** An image is painted in the page; anything
 * else is handed to the reader as a download. That split matters more than
 * presentation: a PDF rendering in the app's own origin is a document that gets
 * to run inside it, and this route is the one place the private bytes surface.
 * Served as `attachment`, the browser saves the file and no renderer ever gets
 * the app's origin to run scripts in.
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
            $attachment->isImage() ? basename($attachment->path) : $attachment->displayName(),
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
     * `Content-Disposition` is stated here rather than left to the adapter
     * because the default is not one rule but a guess: the adapter decides
     * between inline and attachment from the mime, and a type it does not
     * recognise lands on whichever way its heuristic falls. For an image the
     * inline disposition is what makes the `<img>` on the card work, so it is
     * kept explicitly. For everything else the decision is made here — a
     * document is always a download, so an unrecognised type fails toward
     * saving the file rather than toward rendering it in the app's origin.
     *
     * The filename in that header is built from the row, never from the upload;
     * the stored path is a UUID precisely so an attacker-chosen name has no
     * route into a response header.
     *
     * @return array<string, string>
     */
    private function headers(FeedAttachment $attachment): array
    {
        $disposition = $attachment->isImage() ? 'inline' : 'attachment';

        return [
            'Content-Type' => $attachment->mime,
            'Content-Length' => (string) $attachment->size,
            'Content-Disposition' => $disposition.'; filename="'.$attachment->displayName().'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
        ];
    }
}
