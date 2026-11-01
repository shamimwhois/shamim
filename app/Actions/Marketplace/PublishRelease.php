<?php

namespace App\Actions\Marketplace;

use App\Concerns\StoresVerifiedUpload;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Record a release of a listing, and the file that comes with it.
 *
 * Two invariants live here rather than in the editor, because the editor is one of
 * several possible callers and an invariant stated twice is an invariant that can
 * be broken by the third:
 *
 *   - exactly one release of a listing is current. A new release takes the flag and
 *     every sibling loses it, in one transaction, so the storefront can never read
 *     two current releases or none.
 *   - what the row says about a deliverable is what the disk says about the bytes,
 *     not what the uploading browser claimed. See {@see StoresVerifiedUpload}.
 *
 * A release with no file is a complete thing on its own: a listing installed with
 * `composer require` has nothing to stream, and the buyer's screen says so.
 */
class PublishRelease
{
    use StoresVerifiedUpload;

    /**
     * Publish the release, optionally carrying a deliverable file.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $seller, MarketplaceProduct $product, array $data, ?UploadedFile $file = null): MarketplaceProductVersion
    {
        if (! $product->isOwnedBy($seller)) {
            throw new InvalidArgumentException(__('You may not publish releases for this listing.'));
        }

        $version = Str::squish((string) ($data['version'] ?? ''));

        if ($version === '' || Str::length($version) > 32) {
            throw new InvalidArgumentException(__('A release needs a version number, up to 32 characters.'));
        }

        if ($product->versions()->where('version', $version)->exists()) {
            throw new InvalidArgumentException(__('Version :version is already published for this listing.', ['version' => $version]));
        }

        $releasedAt = $this->releasedAt($data['released_at'] ?? null);
        $isCurrent = (bool) ($data['is_current'] ?? true);

        return DB::transaction(function () use ($product, $version, $data, $file, $releasedAt, $isCurrent): MarketplaceProductVersion {
            $deliverable = $file instanceof UploadedFile
                ? $this->storeDeliverable($product, $version, $file)
                : ['disk' => null, 'path' => null, 'mime' => null, 'size' => null, 'original_name' => null];

            $release = MarketplaceProductVersion::query()->create($deliverable + [
                'marketplace_product_id' => $product->id,
                'version' => $version,
                'release_notes' => $this->notes($data['release_notes'] ?? null),
                'is_current' => $isCurrent,
                'released_at' => $releasedAt,
            ]);

            if ($isCurrent) {
                $this->makeCurrent($product, $release, $releasedAt);
            }

            return $release;
        });
    }

    /**
     * Swap the file a release carries, keeping its notes and its place in history.
     *
     * The bytes go first and the row follows: a release whose old file is still on
     * disk while the row points at a new one is a release that streams the wrong
     * package to a buyer who already paid for it.
     */
    public function replaceDeliverable(User $seller, MarketplaceProductVersion $release, UploadedFile $file): MarketplaceProductVersion
    {
        $product = $release->product;

        if (! $product->isOwnedBy($seller)) {
            throw new InvalidArgumentException(__('You may not change files for this listing.'));
        }

        $previous = $release->path;
        $stored = $this->storeDeliverable($product, $release->version, $file);

        $release->forceFill($stored)->save();

        if ($previous !== null && $previous !== $stored['path']) {
            try {
                Storage::disk($release->disk)->delete($previous);
            } catch (Throwable) {
                // Nothing points at the old bytes any more, so a leftover is a
                // storage tidy-up problem rather than a reason to fail the swap.
            }
        }

        return $release->refresh();
    }

    /**
     * Write the package and report what the disk says about it.
     *
     * @return array{disk: string|null, path: string|null, mime: string|null, size: int|null, original_name: string|null}
     */
    private function storeDeliverable(MarketplaceProduct $product, string $version, UploadedFile $file): array
    {
        $diskName = (string) config('marketplace.deliverable.disk', AttachProductScreenshot::DISK);
        $path = $this->targetPath($product, $version, $file);

        $facts = $this->storeAndInspect(
            $diskName,
            $path,
            $file,
            (array) config('marketplace.deliverable.accepted_mimes', ['application/zip']),
            __('That file could not be stored. Try a different package.'),
            'releaseFile',
        );

        $this->assertWithinSizeLimit($facts['size']);
        $this->discardStagedCopy($file);

        return [
            'disk' => $diskName,
            'path' => $path,
            'mime' => $facts['mime'],
            'size' => $facts['size'],
            'original_name' => $this->displayName($file, $version),
        ];
    }

    /**
     * Where the package goes: one folder per listing, one unpredictable name.
     */
    private function targetPath(MarketplaceProduct $product, string $version, UploadedFile $file): string
    {
        $extension = Str::lower((string) $file->extension());
        $suffix = in_array($extension, ['zip', 'gz', 'tgz', 'tar', '7z'], true) ? ".{$extension}" : '';

        return "deliverables/{$product->id}/".Str::slug($version, '-').'-'.Str::uuid()->toString().$suffix;
    }

    /**
     * The name a buyer's browser is offered, stripped of anything a header could
     * be tricked into carrying.
     *
     * The seller's filename is attacker-chosen text, and this one is echoed into a
     * Content-Disposition header — so the quotes, the path separators and the
     * control characters are gone before it is ever stored.
     */
    private function displayName(UploadedFile $file, string $version): string
    {
        $name = Str::of($file->getClientOriginalName())
            ->replaceMatches('/[^A-Za-z0-9._ -]+/', '-')
            ->trim('-_. ')
            ->limit(120, '')
            ->value();

        return $name !== '' ? $name : Str::slug($version, '-').'.zip';
    }

    /**
     * The cap on a deliverable, measured on the bytes that actually landed.
     */
    private function assertWithinSizeLimit(int $size): void
    {
        $limit = (int) config('marketplace.deliverable.max_upload_kilobytes', 20480) * 1024;

        if ($size > $limit) {
            throw new InvalidArgumentException(__('A deliverable may be up to :max.', [
                'max' => rtrim(number_format($limit / 1048576, 1, '.', ''), '.0').' MB',
            ]));
        }
    }

    /**
     * The release date, defaulting to now when the seller left it blank.
     */
    private function releasedAt(mixed $value): CarbonInterface
    {
        if ($value === null || trim((string) $value) === '') {
            return now();
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            throw new InvalidArgumentException(__('That release date could not be read.'));
        }
    }

    /**
     * The notes as written, or null when the seller left the field blank.
     */
    private function notes(mixed $value): ?string
    {
        $notes = is_string($value) ? trim($value) : '';

        return $notes === '' ? null : Str::limit($notes, 10000, '');
    }

    /**
     * Give one release the current flag, and the listing the version it advertises.
     */
    private function makeCurrent(MarketplaceProduct $product, MarketplaceProductVersion $release, CarbonInterface $releasedAt): void
    {
        MarketplaceProductVersion::query()
            ->where('marketplace_product_id', $product->id)
            ->whereKeyNot($release->id)
            ->update(['is_current' => false]);

        $product->forceFill([
            'version' => $release->version,
            'last_released_at' => max($releasedAt, $product->last_released_at ?? $releasedAt),
        ])->save();
    }
}
