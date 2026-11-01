<?php

namespace App\Concerns;

use App\Models\FeedPost;
use App\Models\FeedReport;
use App\Models\User;
use Livewire\Attributes\Computed;

/**
 * Reporting a commit.
 *
 * Reporting is its own concern because it is the one verb a reader has on a
 * commit they did not write: the feed, a profile and the starred list all draw
 * the same row, so all three have to answer the same button.
 *
 * A report is a row, not a flag on the post — the same shape as the star, so
 * the count printed on the commit and the rows behind it cannot drift. An
 * account reports a commit once: the unique index says so, and the row says so
 * before the button is pressed.
 *
 * The reason is required. "Reported" on its own is not a triage, and a report
 * with no reason is one nobody can act on.
 */
trait ReportsCommits
{
    /**
     * Report a commit to the moderators.
     */
    public function reportCommit(int $id, string $reason): void
    {
        $reader = $this->reportReader();

        if ($reader === null) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        if (! in_array($reason, FeedReport::reasons(), true)) {
            return;
        }

        $post = FeedPost::query()->visible()->find($id);

        // Nobody reports their own commit, and nobody reports a commit that is
        // not there to be reported.
        if ($post === null || $post->user_id === $reader->id) {
            return;
        }

        FeedReport::query()->firstOrCreate(
            ['feed_post_id' => $post->id, 'user_id' => $reader->id],
            ['reason' => $reason],
        );

        // The counter on the post is denormalised, so it is written from the
        // rows rather than nudged by one — a report that was already on file
        // must not count twice.
        $post->forceFill([
            'reports' => FeedReport::query()->where('feed_post_id', $post->id)->count(),
        ])->save();

        // The lookup is a computed property, and a computed property survives
        // the action that invalidated it until something says otherwise.
        unset($this->reportedCommitIds);

        $this->commitStateChanged();
    }

    /**
     * The commits this reader has already reported.
     *
     * A lookup rather than a list: the row asks one question — "has this
     * reader reported this commit?" — and the answer has to be cheap for a
     * page of twelve.
     *
     * @return array<int, bool>
     */
    #[Computed]
    public function reportedCommitIds(): array
    {
        $reader = $this->reportReader();

        if ($reader === null) {
            return [];
        }

        return FeedReport::query()
            ->where('user_id', $reader->id)
            ->pluck('feed_post_id')
            ->mapWithKeys(fn (int|string $id): array => [(int) $id => true])
            ->all();
    }

    /** The signed-in reader, if there is one. */
    protected function reportReader(): ?User
    {
        $reader = auth()->user();

        return $reader instanceof User ? $reader : null;
    }

    /** Hook: the page forgets whatever it cached about these rows. */
    protected function commitStateChanged(): void {}
}
