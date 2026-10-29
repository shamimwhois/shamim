<?php

namespace App\Concerns;

use App\Actions\Feed\NotifyCommitActivity;
use App\Models\FeedPost;
use App\Models\FeedReply;
use App\Models\FeedStar;
use App\Models\User;

/**
 * The four things a reader can do to a commit, shared by every surface that
 * draws one.
 *
 * The feed, a profile and the starred list all render the same row, so they all
 * have to answer the same button. The writes live here; the cache invalidation
 * is a hook (`commitStateChanged`) because only the page knows which of its own
 * computed properties went stale.
 *
 * Reporting is a separate concern ({@see ReportsCommits}) because the feed
 * carries its own copy of these actions and must not inherit a second one.
 */
trait HasCommitActions
{
    use ReportsCommits;
    use VotesInPolls;

    /** Star or unstar a commit. */
    public function toggleStar(int $id): void
    {
        $reader = $this->commitReader();

        if ($reader === null) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        if (! FeedPost::query()->visible()->whereKey($id)->exists()) {
            return;
        }

        $star = FeedStar::query()
            ->where('feed_post_id', $id)
            ->where('user_id', $reader->id)
            ->first();

        if ($star !== null) {
            $star->delete();
        } else {
            FeedStar::query()->create([
                'feed_post_id' => $id,
                'user_id' => $reader->id,
            ]);

            $post = FeedPost::query()->find($id);

            if ($post !== null) {
                app(NotifyCommitActivity::class)->starred($post, $reader);
            }
        }

        $this->commitStateChanged();
    }

    /** Expand or collapse a commit's thread. */
    public function toggleThread(int $id): void
    {
        $this->threadId = $this->threadId === $id ? null : $id;
        $this->replyTo = null;
    }

    /** Open the inline reply composer under a commit. */
    public function openReply(int $id): void
    {
        $this->threadId = $id;
        $this->replyTo = $id;
        $this->replyDraft = '';
    }

    /** Close the reply composer without discarding the thread. */
    public function cancelReply(): void
    {
        $this->replyTo = null;
        $this->replyDraft = '';
    }

    /** Add a reply to a commit's thread. */
    public function postReply(int $id): void
    {
        $reader = $this->commitReader();

        if ($reader === null) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        $this->validate([
            'replyDraft' => ['required', 'string', 'max:1000'],
        ], attributes: ['replyDraft' => __('reply')]);

        $post = FeedPost::query()->visible()->find($id);

        if ($post === null) {
            return;
        }

        $reply = FeedReply::query()->create([
            'feed_post_id' => $post->id,
            'user_id' => $reader->id,
            'body' => trim($this->replyDraft),
        ]);

        app(NotifyCommitActivity::class)->replied($post, $reply, $reader);

        $this->threadId = $post->id;
        $this->replyTo = null;
        $this->replyDraft = '';

        $this->commitStateChanged();
    }

    /**
     * The signed-in reader, if there is one.
     */
    protected function commitReader(): ?User
    {
        $reader = auth()->user();

        return $reader instanceof User ? $reader : null;
    }

    /** Hook: the page forgets whatever it cached about these rows. */
    protected function commitStateChanged(): void {}
}
