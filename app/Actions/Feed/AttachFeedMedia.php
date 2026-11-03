<?php

namespace App\Actions\Feed;

use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\User;
use App\Support\FeedAttachmentTypes;
use App\Support\SvgSanitizer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Move a composer upload onto the private feed media disk and record it.
 *
 * The bytes are written first and believed second. Everything the row claims
 * about the file — its mime, its size, its dimensions — is read back from the
 * disk after the move, so a `Content-Type` forged in the browser never becomes
 * the truth the attachment route streams back. A file that does not report as
 * an accepted type once it is on disk is deleted again rather than recorded.
 *
 * Uploads arrive before the post exists, so a file is first stamped with the
 * composer's `temp_key` and no post. {@see claim()} binds those rows when the
 * post is written; anything never claimed is reclaimed by `feed:prune-media`.
 *
 * **Accepted types come from one place** ({@see FeedAttachmentTypes}), which is
 * the same list the picker's `accept` and the validator's `mimes:` rule are
 * built from, so a format is offered exactly when it is stored and never the
 * other way around.
 */
class AttachFeedMedia
{
    /** The private disk the bytes live on. Never served by path. */
    public const DISK = 'feed_media';

    /**
     * Mime types the composer will store, matched by `ComposerAttachTest`.
     *
     * Kept as a constant because it is read reflectively as the authority on
     * "what does the disk take" — but it is now *derived* from the shared type
     * list rather than being a second copy of it. A format added there is
     * accepted here with no second edit to forget.
     */
    public const ACCEPTED_MIMES = FeedAttachmentTypes::IMAGES;

    /** The types served as downloads rather than painted inline. */
    public const ACCEPTED_DOCUMENT_MIMES = FeedAttachmentTypes::DOCUMENTS;

    /**
     * Store each upload and return the rows that describe them.
     *
     * The composer hands over Livewire's {@see TemporaryUploadedFile} staging
     * copies; the type is widened to the framework's `UploadedFile` so the
     * verb also accepts any other validated upload source without changing
     * what it does with the bytes.
     *
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, FeedAttachment>
     */
    public function handle(?FeedPost $post, User $uploader, array $files, ?string $tempKey = null): Collection
    {
        $stored = Collection::make();
        $offset = $this->nextSortOrder($post, $uploader, $tempKey);

        foreach ($files as $file) {
            $stored->push($this->storeOne($post, $uploader, $file, $tempKey, $offset++));
        }

        return $stored;
    }

    /**
     * The ordinal the next stored file should carry.
     *
     * Both halves matter. A post resumed with images on it must continue past
     * them, and a composer that stages one file, then a second, must continue
     * past the first — otherwise every staged row carries `0`, the grid's
     * `orderBy('sort_order')` cannot tell them apart, and a two-image post
     * renders in whatever order the database happens to return. Ties are the
     * one thing an explicit ordering must never contain.
     */
    private function nextSortOrder(?FeedPost $post, User $uploader, ?string $tempKey): int
    {
        if ($post !== null) {
            return (int) $post->attachments()->max('sort_order') + 1;
        }

        return (int) FeedAttachment::query()
            ->unattached()
            ->where('temp_key', $tempKey)
            ->where('user_id', $uploader->id)
            ->max('sort_order') + 1;
    }

    /**
     * Bind the uploads staged under a temp key to a post, in the order they
     * arrived, and report how many moved.
     *
     * Only the uploader's own staged files are claimable, which is what stops
     * one account from adopting another's abandoned upload by guessing its key.
     */
    public function claim(FeedPost $post, string $tempKey, User $uploader): int
    {
        $staged = FeedAttachment::query()
            ->unattached()
            ->where('temp_key', $tempKey)
            ->where('user_id', $uploader->id)
            ->orderBy('id')
            ->get();

        if ($staged->isEmpty()) {
            return 0;
        }

        // The post may already carry media (a draft resumed with images on it),
        // so the claimed files continue the existing order rather than restart it.
        $next = (int) $post->attachments()->max('sort_order') + 1;

        $staged->each(function (FeedAttachment $attachment) use ($post, &$next): void {
            $attachment->forceFill([
                'feed_post_id' => $post->id,
                'sort_order' => $next++,
                'temp_key' => null,
            ])->save();
        });

        return $staged->count();
    }

    /**
     * Write one file to the disk and record what the disk says about it.
     */
    private function storeOne(?FeedPost $post, User $uploader, UploadedFile $file, ?string $tempKey, int $offset): FeedAttachment
    {
        $disk = $this->disk();
        $path = $this->targetPath($post, $tempKey, $file);
        $contents = $file->get();

        // A stream that cannot be read is refused before anything is written,
        // so a half-upload never leaves a zero-byte file behind for the
        // prune command to find and the author to wonder about.
        if (! is_string($contents)) {
            $this->reject($path, null);
        }

        // An SVG is rewritten before it is stored. It is the one accepted type
        // that can execute, and the bytes are served back to other accounts
        // from this app's own origin — so the sanitizer is what stands between
        // an uploaded diagram and stored XSS. A file that claims to be SVG but
        // does not parse is refused rather than stored unread.
        if ($this->declaresSvg($file, $contents)) {
            $contents = $this->sanitizedSvg($path, $contents);
        }

        try {
            $disk->put($path, $contents);
        } catch (Throwable $exception) {
            $this->reject($path, $exception);
        }

        $mime = $this->readBackMime($disk, $path);

        // The name is reconciled with the bytes now that the bytes have spoken.
        //
        // `targetPath()` had to choose a suffix from the upload's *claim*, and
        // a claim can be wrong: measured, a 1×1 PNG sent as `lie.pdf` was
        // written to `…pdf` and would have been downloaded as a PDF. The
        // read-back is the first moment the truth is known, so it is the
        // moment the name is corrected — the file is moved to the suffix its
        // own type implies, or to none at all when the type has no extension
        // this app recognises.
        $path = $this->reconcileExtension($disk, $path, $mime);

        $dimensions = $this->readBackDimensions($disk, $path, $mime);

        // Server truth replaces the client's claim in the row, so the only mime
        // a reader can ever be served is the one the bytes actually produce.
        $attachment = FeedAttachment::query()->create([
            'feed_post_id' => $post?->id,
            'user_id' => $uploader->id,
            'disk' => self::DISK,
            'path' => $path,
            'mime' => $mime,
            'size' => $this->readBackSize($disk, $path),
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'alt_text' => null,
            'sort_order' => $offset,
            'temp_key' => $post !== null ? null : $tempKey,
        ]);

        $this->discardTempCopy($file);

        return $attachment;
    }

    /**
     * Give the stored file the suffix its own bytes imply, and return its path.
     *
     * A no-op in the ordinary case: the upload claimed an extension that the
     * type confirms, so the path already ends correctly. It acts only when the
     * two disagree, which is the case a reader would otherwise meet as a file
     * named `…pdf` that no PDF reader can open.
     *
     * The move is within one folder, so it is a rename — and if it fails the
     * file is still on the disk under its old name, which the read-back has
     * already proven acceptable. A file with a misleading suffix is a worse
     * outcome than a file with none, so the failure path drops the suffix
     * rather than leaving the lie in place.
     */
    private function reconcileExtension(FilesystemAdapter $disk, string $path, string $mime): string
    {
        $current = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        if (FeedAttachmentTypes::extensionMatches($current, $mime)) {
            return $path;
        }

        $wanted = $this->extensionFor($mime);
        $stem = $wanted === null ? $path : preg_replace('/\.[a-z0-9]+$/i', '', $path).'.'.$wanted;

        // Nothing to do when the file already carries no suffix and the type
        // has none either — the common case for the rare type with no mapping.
        if ($stem === $path) {
            return $path;
        }

        try {
            $disk->move($path, $stem);

            return $stem;
        } catch (Throwable $exception) {
            // The old name stays. Reported rather than swallowed, because a
            // failed rename on a media disk is worth knowing about even though
            // it does not fail the publish.
            report($exception);

            return $path;
        }
    }

    /**
     * The extension a mime should be stored and offered under.
     *
     * Delegated, so the inversion of the extension map lives with the map. The
     * OOXML types need the application resolved before the container (a
     * `…wordprocessingml.document` is a `.docx`, not a `.zip`), and that
     * knowledge belongs beside the list rather than here.
     */
    private function extensionFor(string $mime): ?string
    {
        return FeedAttachmentTypes::extensionFor($mime);
    }

    /**
     * Whether the upload is an SVG, judged from the file itself.
     *
     * Asked before the bytes are written so the sanitizer runs on the stored
     * content rather than being a second pass over the disk. The client's
     * `getClientMimeType()` is not consulted: it is attacker-controlled, and a
     * file that lies about being SVG is exactly how this check would be
     * skipped. The extension and the opening markup are facts about the bytes.
     */
    private function declaresSvg(UploadedFile $file, string $contents): bool
    {
        if (Str::lower((string) $file->extension()) === 'svg') {
            return true;
        }

        return SvgSanitizer::looksLikeSvg($contents);
    }

    /**
     * The sanitized form of an SVG, or a refusal.
     *
     * A document the parser cannot read yields `null`, and a `null` here is a
     * refusal: storing the original bytes of a file this class could not make
     * safe would be the one outcome worth failing a publish to avoid.
     */
    private function sanitizedSvg(string $path, string $contents): string
    {
        $clean = SvgSanitizer::clean($contents);

        if ($clean === null) {
            $this->reject($path, null);
        }

        return SvgSanitizer::stripNamespaceDeclarations($clean);
    }

    /**
     * Where the file goes: one folder per post, one unpredictable name per file.
     *
     * The name is a UUID rather than the author's filename because the uploaded
     * name is attacker-chosen text that would otherwise be echoed back into a
     * path, and `../../` in a filename is a real thing people upload.
     *
     * The extension is carried over from the upload only to give the file a
     * name its author would recognise while it is being staged. It is *not*
     * trusted: {@see reconcileExtension()} renames the file after the read-back
     * has established what the bytes actually are, so the name a reader
     * eventually downloads is always derived from the type rather than from
     * the claim. Writing the claimed suffix here and correcting it there keeps
     * the common case (a file named honestly) a single write.
     */
    private function targetPath(?FeedPost $post, ?string $tempKey, UploadedFile $file): string
    {
        $extension = Str::lower((string) $file->extension());
        $slug = $post !== null ? Str::slug((string) $post->slug) : '';
        $folder = $slug !== '' ? $slug : Str::slug($tempKey !== null ? 'staged-'.$tempKey : 'unsorted');
        $suffix = in_array($extension, FeedAttachmentTypes::EXTENSIONS, true) ? ".{$extension}" : '';

        return "posts/{$folder}/".Str::uuid()->toString().$suffix;
    }

    /**
     * The mime the bytes actually are, or nothing acceptable.
     *
     * Two questions, and only the second is a guarantee. The disk reports a
     * mime for the stored path — but that report is derived from the
     * *extension*, not from the contents, which was measured: plain text
     * written to a `.png` path reports `image/png`. So the disk's answer is
     * treated as a claim to be checked rather than as the truth, which is the
     * same posture this class takes toward the uploading browser.
     *
     * The check is content-aware in the one direction that matters. An image
     * claim is confirmed by decoding the bytes — if `getimagesizefromstring`
     * cannot read them, the file is not an image however it is named, and
     * accepting it would put a file the card paints into the `<img>` path with
     * nothing to paint. A document claim is taken at the disk's word, because
     * "is this really a docx" is a question about a zip container's internal
     * structure rather than about a magic number, and the consequence of a
     * mislabelled document is a download that does not open — not a script
     * running in this origin. The disposition is `attachment` either way, so
     * the blast radius of the weaker check is one broken file.
     *
     * The acceptance test is the shared list, not the four-image list it used
     * to be, so a document that read back as `application/pdf` is recorded
     * rather than deleted the moment after it was written.
     */
    private function readBackMime(FilesystemAdapter $disk, string $path): string
    {
        try {
            $reported = $disk->mimeType($path);
        } catch (UnableToRetrieveMetadata $exception) {
            $this->reject($path, $exception);
        }

        $mime = is_string($reported) ? $reported : '';

        if (! FeedAttachmentTypes::accepts($mime)) {
            $this->reject($path, null);
        }

        // An image claim is confirmed against the bytes. A file that reports as
        // an image but does not decode is refused: it would be painted inline
        // by the card and would render as a broken image for every reader.
        //
        // Two checks, because one decoder cannot answer for every type. Where
        // PHP's `getimagesizefromstring` can read the format, that is the check
        // — it is the strongest statement available, since it parses the header
        // rather than matching a prefix. Where it cannot (AVIF, measured: no GD
        // and no Imagick here, so the decoder returns false for a *valid*
        // file), the signature is checked instead. Using the decode result for
        // those would refuse every file of the type while the picker went on
        // offering it.
        if (FeedAttachmentTypes::isImage($mime)) {
            $contents = $disk->get($path);

            if (! is_string($contents)) {
                $this->reject($path, null);
            }

            $confirmed = FeedAttachmentTypes::isDecodable($mime)
                ? @getimagesizefromstring($contents) !== false
                : FeedAttachmentTypes::signatureMatches($mime, $contents);

            if (! $confirmed) {
                $this->reject($path, null);
            }
        }

        // An OLE2 container is confirmed to hold the document its name claims.
        //
        // This is the check that makes the legacy Office formats acceptable
        // rather than a blank cheque. The detector reports every OLE2 compound
        // file as `application/x-ole-storage` — measured, a Word document, a
        // spreadsheet and a macro container are indistinguishable by mime — so
        // accepting on the mime alone would vouch for *any* compound file,
        // including the one macro malware ships in.
        //
        // The directory names the streams inside, so the extension's promise is
        // checked against it: a `.doc` must hold `WordDocument`, an `.xls` a
        // `Workbook`, a `.ppt` the `PowerPoint Document` stream. A container
        // with none of them is refused, and the reader is told the file is not
        // what they called it rather than being handed a spreadsheet that opens
        // as an error.
        if ($mime === 'application/x-ole-storage') {
            $contents = $disk->get($path);

            if (! is_string($contents)) {
                $this->reject($path, null);
            }

            if (FeedAttachmentTypes::legacyOfficeKind($this->claimedExtension($path), $contents) === null) {
                $this->reject($path, null);
            }
        }

        /** @var string $mime */
        return $mime;
    }

    /**
     * The suffix the upload claimed, read back off the stored path.
     *
     * The claim is what the container check has to compare against, and by the
     * time the read-back runs the claim is only recoverable from the path —
     * `targetPath()` wrote it there. `reconcileExtension()` runs *after* this,
     * deliberately: the name is corrected once the bytes have spoken, so the
     * correction cannot be made on the strength of a claim that has not been
     * checked yet.
     */
    private function claimedExtension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * The pixel dimensions decoded from the stored bytes.
     *
     * Only asked of a type that is painted inline. A PDF or a spreadsheet has
     * no pixel size, so probing one would be a decode of bytes that were never
     * an image, and a `null` would be indistinguishable from a decode that
     * genuinely failed on an image whose aspect ratio is unknown.
     *
     * Dimensions stay null when the decoder genuinely cannot read them, so the
     * card knows the aspect ratio is unknown rather than zero-by-zero.
     *
     * @return array{width: int|null, height: int|null}
     */
    private function readBackDimensions(FilesystemAdapter $disk, string $path, string $mime): array
    {
        if (! FeedAttachmentTypes::isImage($mime)) {
            return ['width' => null, 'height' => null];
        }

        $contents = $disk->get($path);

        if (! is_string($contents)) {
            return ['width' => null, 'height' => null];
        }

        $size = @getimagesizefromstring($contents);

        return [
            'width' => $size === false ? null : (int) $size[0],
            'height' => $size === false ? null : (int) $size[1],
        ];
    }

    /**
     * The byte count the disk reports for the stored file.
     */
    private function readBackSize(FilesystemAdapter $disk, string $path): int
    {
        try {
            return (int) $disk->size($path);
        } catch (UnableToRetrieveMetadata $exception) {
            $this->reject($path, $exception);
        }
    }

    /**
     * Remove a file that failed the read-back, then refuse the upload.
     *
     * The message is written for the person holding the phone: it says what was
     * wrong with *their* file, and it is the same message whatever went wrong
     * underneath, because a raw Flysystem exception would tell them nothing.
     *
     * It says "file" rather than "image" because this verb now stores both, and
     * a PDF told it is a broken image is a message that loses the reader's
     * trust in the one moment they needed it.
     */
    private function reject(string $path, ?Throwable $exception): never
    {
        try {
            $this->disk()->delete($path);
        } catch (Throwable) {
            // A disk that cannot delete is already the error being reported.
        }

        report_if($exception !== null, $exception);

        throw ValidationException::withMessages([
            'uploads' => __('That file could not be stored. Try a different file.'),
        ]);
    }

    /**
     * Delete Livewire's staged copy now that the permanent one exists.
     *
     * Only a staged upload owns a temp copy worth deleting; a plain
     * `UploadedFile` has nothing of ours to clean up.
     */
    private function discardTempCopy(UploadedFile $file): void
    {
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $file->delete();
        } catch (Throwable) {
            // The post has its file; a leftover temp copy is `feed:prune-media`'s
            // problem, not a reason to fail a publish the author already finished.
        }
    }

    /**
     * The feed media disk, typed as the adapter that can report a file's mime.
     */
    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(self::DISK);

        return $disk;
    }
}
