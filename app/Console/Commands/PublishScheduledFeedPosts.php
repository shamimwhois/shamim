<?php

namespace App\Console\Commands;

use App\Enums\FeedPostStatus;
use App\Models\FeedPost;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Open the publish gate on posts whose scheduled minute has arrived.
 *
 * A scheduled post is already written and already visible to its author in the
 * drafts list; all that is waiting is the state. This command is the only thing
 * that turns `Scheduled` into `Published`, which makes it the single place the
 * schedule is enforced — the browser's promise of a future time is not a
 * constraint the application has to believe.
 *
 * Two properties matter more than speed here.
 *
 * *Idempotent*: a row is selected only while it still says `Scheduled` and is
 * flipped by a conditional write that repeats the same test, so a second run
 * finds nothing and a run overlapping another process cannot publish the same
 * post twice or overwrite a post someone else already published.
 *
 * *Bounded*: only rows whose `scheduled_at` is in the past are touched. A future
 * minute is not a due minute, however close it looks, so an author who set a
 * schedule for tomorrow is not surprised by it appearing today because of clock
 * drift or a manual run.
 *
 * A post its author removed before it came due is not published. `deleted` is
 * moderation's answer to "should this ever be seen", and the schedule is not a
 * licence to overrule it.
 */
class PublishScheduledFeedPosts extends Command
{
    protected $signature = 'feed:publish-scheduled {--dry-run : Report the posts that are due, change nothing}';

    protected $description = 'Publish community feed posts whose scheduled time has arrived';

    public function handle(): int
    {
        $due = $this->duePosts();

        if ($due->isEmpty()) {
            $this->info('No scheduled posts are due.');

            return self::SUCCESS;
        }

        $now = now();

        if ((bool) $this->option('dry-run')) {
            foreach ($due as $post) {
                $this->line(sprintf(
                    '  due     %s — %s by %s',
                    $post->slug,
                    $post->scheduled_at === null
                        ? 'no minute recorded'
                        : 'scheduled '.$post->scheduled_at->diffForHumans(['part' => true]),
                    $post->author,
                ));
            }

            $this->info(sprintf('Would publish %d scheduled %s.', $due->count(), $due->count() === 1 ? 'post' : 'posts'));

            return self::SUCCESS;
        }

        $published = DB::transaction(fn (): int => $this->publishAll($due, $now));

        $this->info(sprintf(
            'Published %d scheduled %s.',
            $published,
            $published === 1 ? 'post' : 'posts',
        ));

        return self::SUCCESS;
    }

    /**
     * Scheduled rows whose minute has arrived and that have not been removed.
     *
     * @return Collection<int, FeedPost>
     */
    private function duePosts(): Collection
    {
        return FeedPost::query()
            ->where('status', FeedPostStatus::Scheduled->value)
            ->where('deleted', false)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Flip every due row, and report how many actually moved.
     *
     * The write repeats the row's own condition — `whereKey` *and* still
     * `Scheduled` — so it is atomic against itself: a row somebody else published
     * between the read and the write matches zero rows and is counted zero, rather
     * than being overwritten with a fresh `posted_at`. That is why this needs no
     * locking, and why a repeated run is a no-op instead of a second publication.
     *
     * Every timestamp here is taken from one `now()`, so a post due at 09:00 and a
     * run that started at 09:00 agree about what "due" means for the whole batch
     * instead of drifting apart row by row.
     *
     * The parameter is typed against `CarbonInterface` rather than a concrete
     * Carbon class because this application configures `Date::use(CarbonImmutable::
     * class)`, so the object `now()` hands back is immutable at runtime while the
     * framework's own signature still advertises the mutable class. Asserting
     * either concrete name would be a claim one of the two would contradict.
     *
     * @param  Collection<int, FeedPost>  $due
     */
    private function publishAll(Collection $due, CarbonInterface $now): int
    {
        $published = 0;

        foreach ($due as $post) {
            $published += (int) FeedPost::query()
                ->whereKey($post->getKey())
                ->where('status', FeedPostStatus::Scheduled->value)
                ->update([
                    'status' => FeedPostStatus::Published->value,
                    // The community first saw it now, not at the minute the author
                    // typed into the picker — if this run is late, the timestamp
                    // tells the truth about when it actually went live.
                    'posted_at' => $post->posted_at ?? $now,
                    'updated_at' => $now,
                ]);
        }

        return $published;
    }
}
