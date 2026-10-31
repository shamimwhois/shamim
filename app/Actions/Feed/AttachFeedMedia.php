<?php

namespace App\Actions\Feed;

use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\User;
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
 * an image once it is on disk is deleted again rather than recorded.
 *
 * Uploads arrive before the post exists, so a file is first stamped with the
 * composer's `temp_key` and no post. {@see claim()} binds those rows when the
 * post is written; anything never claimed is reclaimed by `feed:prune-media`.
 */
class AttachFeedMedia
{
    /** The private disk the bytes live on. Never served by path. */
    public const DISK = 'feed_media';

    /** Mime types the composer will decode dimensions from, and accept. */
    private const ACCEPTED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

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

        try {
            $disk->put($path, $contents);
        } catch (Throwable $exception) {
            $this->reject($path, $exception);
        }

        $mime = $this->readBackMime($disk, $path);
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
     * Where the file goes: one folder per post, one unpredictable name per file.
     *
     * The name is a UUID rather than the author's filename because the uploaded
     * name is attacker-chosen text that would otherwise be echoed back into a
     * path, and `../../` in a filename is a real thing people upload.
     */
    private function targetPath(?FeedPost $post, ?string $tempKey, UploadedFile $file): string
    {
        $extension = Str::lower((string) $file->extension());
        $slug = $post !== null ? Str::slug((string) $post->slug) : '';
        $folder = $slug !== '' ? $slug : Str::slug($tempKey !== null ? 'staged-'.$tempKey : 'unsorted');
        $suffix = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? ".{$extension}" : '';

        return "posts/{$folder}/".Str::uuid()->toString().$suffix;
    }

    /**
     * The mime the disk reports for the stored bytes, or nothing acceptable.
     */
    private function readBackMime(FilesystemAdapter $disk, string $path): string
    {
        try {
            $reported = $disk->mimeType($path);
        } catch (UnableToRetrieveMetadata $exception) {
            $this->reject($path, $exception);
        }

        $mime = is_string($reported) ? $reported : '';

        if (! in_array($mime, self::ACCEPTED_MIMES, true)) {
            $this->reject($path, null);
        }

        /** @var string $mime */
        return $mime;
    }

    /**
     * The pixel dimensions decoded from the stored bytes.
     *
     * Dimensions stay null when the decoder genuinely cannot read them, so the
     * card knows the aspect ratio is unknown rather than zero-by-zero.
     *
     * @return array{width: int|null, height: int|null}
     */
    private function readBackDimensions(FilesystemAdapter $disk, string $path, string $mime): array
    {
        if (! in_array($mime, self::ACCEPTED_MIMES, true)) {
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
            'uploads' => __('That image could not be stored. Try a different file.'),
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
