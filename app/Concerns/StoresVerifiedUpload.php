<?php

namespace App\Concerns;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Write an upload to a private disk and record only what the disk confirms.
 *
 * The bytes are written first and believed second. Everything a row ends up
 * claiming about a file — its mime, its size, its dimensions — is read back from
 * the disk after the move, so a Content-Type forged in the browser never becomes
 * the truth a route streams back. A file that is not what it was said to be is
 * deleted again rather than recorded, so a rejected upload leaves neither a row nor
 * a byte behind.
 *
 * Both listing writers need this — a screenshot and a release's deliverable are the
 * same problem with different allow-lists — and security code that exists twice
 * drifts, so the read-back lives here once.
 */
trait StoresVerifiedUpload
{
    /**
     * Put the bytes on the disk, then report what the disk says about them.
     *
     * @param  array<int, string>  $acceptedMimes
     * @return array{mime: string, size: int, width: int|null, height: int|null}
     */
    protected function storeAndInspect(string $diskName, string $path, UploadedFile $file, array $acceptedMimes, string $rejection, string $errorKey = 'uploads'): array
    {
        $disk = $this->verifiedUploadDisk($diskName);
        $contents = $file->get();

        // A stream that cannot be read is refused before anything is written, so a
        // half-upload never leaves a zero-byte file behind.
        if (! is_string($contents)) {
            $this->rejectUpload($disk, $path, null, $rejection, $errorKey);
        }

        try {
            $disk->put($path, $contents);
        } catch (Throwable $exception) {
            $this->rejectUpload($disk, $path, $exception, $rejection, $errorKey);
        }

        $mime = $this->readBackMime($disk, $path, $acceptedMimes, $rejection, $errorKey);

        return [
            'mime' => $mime,
            'size' => $this->readBackSize($disk, $path, $rejection, $errorKey),
            ...$this->readBackDimensions($disk, $path),
        ];
    }

    /**
     * The mime the disk reports for the stored bytes, or nothing acceptable.
     *
     * @param  array<int, string>  $acceptedMimes
     */
    private function readBackMime(FilesystemAdapter $disk, string $path, array $acceptedMimes, string $rejection, string $errorKey): string
    {
        try {
            $reported = $disk->mimeType($path);
        } catch (UnableToRetrieveMetadata $exception) {
            $this->rejectUpload($disk, $path, $exception, $rejection, $errorKey);
        }

        $mime = is_string($reported) ? $reported : '';

        if (! in_array($mime, $acceptedMimes, true)) {
            $this->rejectUpload($disk, $path, null, $rejection, $errorKey);
        }

        /** @var string $mime */
        return $mime;
    }

    /**
     * The pixel dimensions decoded from the stored bytes.
     *
     * They stay null when the decoder genuinely cannot read them — which is every
     * deliverable, and any image whose format PHP was not built for — so a reader
     * knows the aspect ratio is unknown rather than zero-by-zero.
     *
     * @return array{width: int|null, height: int|null}
     */
    private function readBackDimensions(FilesystemAdapter $disk, string $path): array
    {
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
    private function readBackSize(FilesystemAdapter $disk, string $path, string $rejection, string $errorKey): int
    {
        try {
            return (int) $disk->size($path);
        } catch (UnableToRetrieveMetadata $exception) {
            $this->rejectUpload($disk, $path, $exception, $rejection, $errorKey);
        }
    }

    /**
     * Remove the bytes that failed the read-back, then refuse the upload.
     *
     * The message is written for the person holding the phone: it says what was
     * wrong with *their* file, because a raw Flysystem exception would tell them
     * nothing.
     */
    private function rejectUpload(FilesystemAdapter $disk, string $path, ?Throwable $exception, string $rejection, string $errorKey): never
    {
        try {
            $disk->delete($path);
        } catch (Throwable) {
            // A disk that cannot delete is already the error being reported.
        }

        report_if($exception !== null, $exception);

        throw ValidationException::withMessages([$errorKey => $rejection]);
    }

    /**
     * Delete Livewire's staged copy now that the permanent one exists.
     */
    protected function discardStagedCopy(UploadedFile $file): void
    {
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $file->delete();
        } catch (Throwable) {
            // The listing has its file; a leftover temp copy is a cleaner's
            // problem, not a reason to fail a save the seller already finished.
        }
    }

    /**
     * The named disk, typed as the adapter that can report a file's mime.
     */
    private function verifiedUploadDisk(string $diskName): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        return $disk;
    }
}
