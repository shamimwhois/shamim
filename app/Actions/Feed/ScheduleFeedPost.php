<?php

namespace App\Actions\Feed;

use App\Enums\FeedPostStatus;
use App\Models\FeedPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Promise a post to the community at a time that has not happened yet.
 *
 * Scheduling is a publication decision made in advance, so it goes through the
 * same content write as publishing and then changes only the state. What it owns
 * is the one rule the schedule depends on and the browser cannot be trusted to
 * enforce: a scheduled post must be due *later*. A `scheduled_at` in the past
 * would be immediately published by the next `feed:publish-scheduled` run — or,
 * if that command had not run yet, it would already be sitting in the table
 * looking like a future post while actually being overdue. Refusing the past
 * date is what keeps `Scheduled` meaning "not yet visible" rather than "whatever
 * minute the picker happened to be showing".
 */
class ScheduleFeedPost
{
    public function __construct(
        protected SaveFeedDraft $saveDraft,
    ) {}

    /**
     * Write the post and set it to go live at the requested moment.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, array $data, mixed $scheduledAt, ?FeedPost $post = null): FeedPost
    {
        // Parsed before anything is written: a rejected schedule should leave the
        // author's draft exactly where it was, not half-committed.
        $due = $this->dueAt($scheduledAt);

        return DB::transaction(function () use ($author, $data, $due, $post): FeedPost {
            $scheduled = $this->saveDraft->handle($author, $data, $post);

            // Setting a schedule is publishing with the moment delegated to the
            // scheduler, so it needs the same grant the instant-publish path needs.
            Gate::forUser($author)->authorize('publish', $scheduled);

            $scheduled->forceFill([
                'status' => FeedPostStatus::Scheduled,
                'scheduled_at' => $due,
                // No post has been seen yet, so it has no publication date. The
                // scheduler stamps `posted_at` when the row actually goes live.
                'posted_at' => null,
            ])->save();

            return $scheduled;
        });
    }

    /**
     * Accept only a real, future moment.
     *
     * `now()` is the boundary the scheduler itself compares against, so the check
     * is written against the same clock the command will use — under test-time
     * travel, both move together.
     */
    private function dueAt(mixed $scheduledAt): CarbonImmutable
    {
        $due = $this->parse($scheduledAt);

        if (! $due->greaterThan(now())) {
            throw ValidationException::withMessages([
                'scheduled_at' => __('Pick a time in the future. A scheduled post that is already due would publish immediately.'),
            ]);
        }

        return $due;
    }

    /**
     * Coerce whatever the composer sent into an immutable timestamp.
     */
    private function parse(mixed $scheduledAt): CarbonImmutable
    {
        if ($scheduledAt instanceof CarbonInterface) {
            return CarbonImmutable::instance($scheduledAt);
        }

        if (is_string($scheduledAt) && trim($scheduledAt) !== '') {
            try {
                return CarbonImmutable::parse(trim($scheduledAt));
            } catch (Throwable) {
                // Falls through to the one message below, which is about the
                // author's input rather than about which parser stage gave up.
            }
        }

        throw ValidationException::withMessages([
            'scheduled_at' => __('Choose when this post should go live.'),
        ]);
    }
}
