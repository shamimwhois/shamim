<?php

namespace App\Concerns;

use App\Models\FeedPost;
use App\Models\FeedShare;
use App\Models\User;
use Livewire\Attributes\Computed;

/**
 * Sharing a commit.
 *
 * Sharing is its own concern for the same reason reporting is: the feed, a
 * profile and the starred list all draw the same row, so all three have to
 * answer the same button — and answer it from the same place.
 *
 * A share is a row, not a flag on the post. The count printed on the commit is
 * a row count, never a nudged column, which is the doctrine `feed_stars` and
 * `feed_poll_votes` already follow: a counter that can drift from its rows is
 * the one bug a log cannot survive.
 *
 * The difference from a star is what the button does *besides* recording. A
 * star is a private bookmark; a share is an act aimed at somewhere else — the
 * clipboard, or the OS share sheet — so the row records the intent and the
 * platform does the delivering. That is why the action takes a channel: the
 * ledger should be able to tell a copied link from a handed-off post.
 */
trait SharesCommits
{
    /**
     * Record that the reader passed this commit on.
     *
     * Idempotent by construction: `feed_shares` is unique on
     * `(feed_post_id, user_id)`, and the insert is a `firstOrCreate`, so a
     * double click — or sharing by the clipboard and then by the sheet — is the
     * same row and moves the count once.
     */
    public function shareCommit(int $id, string $channel = FeedShare::CHANNEL_LINK): void
    {
        $reader = $this->shareReader();

        if ($reader === null) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        if (! in_array($channel, [FeedShare::CHANNEL_LINK, FeedShare::CHANNEL_SHEET], true)) {
            $channel = FeedShare::CHANNEL_LINK;
        }

        $post = FeedPost::query()->visible()->find($id);

        if ($post === null) {
            return;
        }

        // Sharing your own commit is allowed — a quote-post of a correction is
        // a normal thing to do — so unlike reporting there is no author check
        // here. The unique index is the whole guard.
        $share = FeedShare::query()->firstOrCreate(
            ['feed_post_id' => $post->id, 'user_id' => $reader->id],
            ['channel' => $channel],
        );

        // A second share through the other route still updates the channel, so
        // the ledger reflects the most recent route rather than the first.
        if (! $share->wasRecentlyCreated && $share->channel !== $channel) {
            $share->forceFill(['channel' => $channel])->save();
        }

        unset($this->sharedCommitIds, $this->shareCounts);

        $this->commitStateChanged();
    }

    /**
     * The commits this reader has already shared.
     *
     * A lookup rather than a list, for the same reason the report lookup is:
     * the row asks one question and the answer has to be cheap for a page of
     * twelve.
     *
     * @return array<int, bool>
     */
    #[Computed]
    public function sharedCommitIds(): array
    {
        $reader = $this->shareReader();

        if ($reader === null) {
            return [];
        }

        return FeedShare::query()
            ->where('user_id', $reader->id)
            ->pluck('feed_post_id')
            ->mapWithKeys(fn (int|string $id): array => [(int) $id => true])
            ->all();
    }

    /**
     * How many accounts have shared each commit, keyed by post id.
     *
     * Read from the ledger rather than a column on the post: one query for the
     * page, and the number the row prints is the number of rows behind it.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function shareCounts(): array
    {
        return FeedShare::query()
            ->selectRaw('feed_post_id, count(*) as total')
            ->groupBy('feed_post_id')
            ->pluck('total', 'feed_post_id')
            ->mapWithKeys(fn (int|string $total, int|string $id): array => [(int) $id => (int) $total])
            ->all();
    }

    /** The signed-in reader, if there is one. */
    protected function shareReader(): ?User
    {
        $reader = auth()->user();

        return $reader instanceof User ? $reader : null;
    }
}
