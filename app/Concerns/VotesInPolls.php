<?php

namespace App\Concerns;

use App\Models\FeedPollVote;
use App\Models\FeedPost;
use App\Models\User;
use Livewire\Attributes\Computed;

/**
 * Voting in a poll.
 *
 * A poll is the one commit a reader can answer rather than react to, so the
 * write lives beside the row that draws it: the feed, a profile and the
 * starred list all show the same poll and all have to accept the same vote.
 *
 * The tally is never written. The poll's JSON keeps the corpus baseline — the
 * anonymous votes that shipped with the content — and this table keeps the
 * votes cast here. What a reader sees is the two added together, so there is
 * no counter to drift from the rows behind it.
 */
trait VotesInPolls
{
    /**
     * Cast a vote in a commit's poll.
     *
     * One vote per account per poll: the unique index says so, and a second
     * click records nothing rather than adding a second ballot.
     */
    public function votePoll(int $id, string $option): void
    {
        $reader = $this->pollReader();

        if ($reader === null) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        $post = FeedPost::query()->visible()->find($id);

        if ($post === null) {
            return;
        }

        $poll = $post->poll;

        if (! is_array($poll) || ! is_array($poll['options'] ?? null)) {
            return;
        }

        $labels = array_map(
            fn (array $choice): string => (string) ($choice['label'] ?? ''),
            array_values(array_filter($poll['options'], 'is_array')),
        );

        // A vote for an option the poll does not offer is not a vote.
        if (! in_array($option, $labels, true)) {
            return;
        }

        FeedPollVote::query()->firstOrCreate(
            ['feed_post_id' => $post->id, 'user_id' => $reader->id],
            ['option' => $option],
        );

        unset($this->pollVotes);

        $this->commitStateChanged();
    }

    /**
     * The votes cast on this install, by poll and by option.
     *
     * Two things in one query: how many votes each option has, and which
     * option this reader chose. The row needs both, and a page of commits
     * should not ask for them twice.
     *
     * @return array<int, array{counts: array<string, int>, mine: string|null}>
     */
    #[Computed]
    public function pollVotes(): array
    {
        $reader = $this->pollReader();

        $votes = FeedPollVote::query()
            ->get(['feed_post_id', 'user_id', 'option']);

        $tally = [];

        foreach ($votes as $vote) {
            $post = (int) $vote->feed_post_id;
            $option = (string) $vote->option;

            $tally[$post]['counts'][$option] = ($tally[$post]['counts'][$option] ?? 0) + 1;

            if ($reader !== null && (int) $vote->user_id === $reader->id) {
                $tally[$post]['mine'] = $option;
            }
        }

        foreach ($tally as $post => $entry) {
            $tally[$post] = [
                'counts' => $entry['counts'] ?? [],
                'mine' => $entry['mine'] ?? null,
            ];
        }

        return $tally;
    }

    /** The signed-in reader, if there is one. */
    protected function pollReader(): ?User
    {
        $reader = auth()->user();

        return $reader instanceof User ? $reader : null;
    }
}
