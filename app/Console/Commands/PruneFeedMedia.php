<?php

namespace App\Console\Commands;

use App\Actions\Feed\AttachFeedMedia;
use App\Models\FeedAttachment;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reclaim feed media nobody is waiting for anymore.
 *
 * The composer stages an upload before a post exists, so the application
 * deliberately leaves a window in which files sit on disk attached to nothing.
 * That window is only safe if something closes it: an abandoned draft would
 * otherwise accumulate bytes forever, and a file whose row was deleted outright
 * would stay on the private disk with nothing left to describe it. This command
 * is the closer, and it is idempotent — every run reports what it finds and
 * removes it, so running it twice costs nothing.
 *
 * Two things can be stale, and they are different questions:
 *
 * A *row* is stale when it still carries a `temp_key` — it was staged and never
 * claimed by a post — and is older than the grace period. Its file goes with it,
 * because the row is the only record of where the bytes are.
 *
 * A *file* is stale when it sits under `posts/` and no row points at it any
 * more. That is the residue of a deletion that removed the row but not the
 * bytes, and it can only be found by walking the disk and asking the database —
 * which is why this belongs on a schedule rather than inline in a request.
 *
 * Fresh staging is never touched. A composer mid-upload is not garbage, and a
 * grace period measured in hours is what tells the two apart.
 */
class PruneFeedMedia extends Command
{
    protected $signature = 'feed:prune-media
                            {--hours= : Override the staging grace period, in hours}
                            {--dry-run : Report what would be removed, remove nothing}';

    protected $description = 'Delete abandoned staged feed uploads and media files left without a record';

    /** The folder every composer file lives under, and the only one this walks. */
    private const ROOT = 'posts';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $staged = $this->staleRows(now()->subHours($this->graceHours()));
        $orphaned = $this->orphanedFiles($staged->pluck('path')->all());

        if ($staged->isEmpty() && $orphaned === []) {
            $this->info('Nothing to prune — no staged upload is out of date and no media file is orphaned.');

            return self::SUCCESS;
        }

        foreach ($staged as $attachment) {
            $this->line(sprintf(
                '  staged  %s (%s, %s) — %s',
                $attachment->path,
                $attachment->mime,
                $attachment->humanSize(),
                $attachment->created_at?->diffForHumans() ?? 'uploaded at an unknown time',
            ));

            $this->remove($attachment->disk, $attachment->path, $attachment, $dryRun);
        }

        foreach ($orphaned as $path) {
            $this->line("  orphan  {$path}");
            $this->remove(AttachFeedMedia::DISK, $path, null, $dryRun);
        }

        $total = $staged->count() + count($orphaned);

        $this->info(sprintf(
            '%s %d %s.',
            $dryRun ? 'Would remove' : 'Removed',
            $total,
            $total === 1 ? 'file' : 'files',
        ));

        return self::SUCCESS;
    }

    /**
     * Rows staged for a composer that never came back to claim them.
     *
     * The cutoff is typed against `CarbonInterface` rather than a concrete class
     * because this application configures `Date::use(CarbonImmutable::class)`: the
     * object `now()` returns is immutable at runtime even though the framework
     * signature advertises the mutable one, and only the interface is true of
     * both.
     *
     * @return Collection<int, FeedAttachment>
     */
    private function staleRows(CarbonInterface $cutoff): Collection
    {
        return FeedAttachment::query()->unattached()->where('created_at', '<', $cutoff)->get();
    }

    /**
     * Files on the media disk that no row describes any more.
     *
     * The disk is listed once and checked against the table once, so the cost is
     * a listing plus a single query rather than a query per file. Paths about to
     * be removed as stale rows are excluded here so one file is never reported
     * twice. A path a row still claims is never deleted, which is what makes it
     * safe to run against a disk that also holds live media.
     *
     * @param  array<int, string>  $alreadyRemoving
     * @return array<int, string>
     */
    private function orphanedFiles(array $alreadyRemoving): array
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(AttachFeedMedia::DISK);

        if (! $disk->directoryExists(self::ROOT)) {
            return [];
        }

        $paths = array_values(array_diff($disk->allFiles(self::ROOT), $alreadyRemoving));

        if ($paths === []) {
            return [];
        }

        $known = FeedAttachment::query()->whereIn('path', $paths)->pluck('path')->all();

        return array_values(array_diff($paths, $known));
    }

    /**
     * Delete one file, and its row when the caller had one.
     *
     * The row goes first inside the same transaction as nothing: a row whose file
     * could not be deleted is left in place, because the row is the only way
     * anything will ever find that file again. Deleting the record first would
     * turn a transient disk error into a permanent orphan.
     */
    private function remove(string $diskName, string $path, ?FeedAttachment $attachment, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        try {
            Storage::disk($diskName)->delete($path);
        } catch (Throwable $exception) {
            // One unreadable file must not stop the other ninety-nine being
            // cleaned, but the operator has to know which one was not cleaned.
            $this->warn("  could not delete {$path}: {$exception->getMessage()}");

            return;
        }

        if ($attachment !== null) {
            DB::transaction(fn () => $attachment->delete());
        }
    }

    /**
     * How long a staged upload is allowed to sit unclaimed.
     */
    private function graceHours(): int
    {
        $hours = $this->option('hours');

        return $hours === null || $hours === ''
            ? max(1, (int) config('feed.publishing.temp_media_hours', 24))
            : max(1, (int) $hours);
    }
}
