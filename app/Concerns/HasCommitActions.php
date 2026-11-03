<?php

namespace App\Concerns;

use App\Actions\Feed\NotifyCommitActivity;
use App\Models\FeedPost;
use App\Models\FeedReply;
use App\Models\FeedStar;
use App\Models\User;

/**
 * The things a reader can do to a commit, shared by every surface that draws
 * one.
 *
 * The feed, a profile and the starred list all render the same row, so they all
 * have to answer the same button. The writes live here; the cache invalidation
 * is a hook (`commitStateChanged`) because only the page knows which of its own
 * computed properties went stale.
 *
 * Deleting lives here for the same reason as the rest: `feed/commit.blade.php`
 * draws the delete strip for any `isOwn` row, and a profile or a starred list
 * is full of the reader's own commits. The method was defined only on the feed
 * page once, so every delete button on `/starred` and `/@{handle}` POSTed to a
 * method that did not exist — Livewire answered "Public method [deleteCommit]
 * not found", and the button did nothing.
 *
 * Reporting is a separate concern ({@see ReportsCommits}) because the feed
 * carries its own copy of these actions and must not inherit a second one.
 */
trait HasCommitActions
{
    use ReportsCommits;
    use VotesInPolls;

    /**
     * The commit the last delete removed, while its undo is still on offer.
     *
     * A page that draws the delete strip needs somewhere to remember this, and
     * the trait cannot know the page's own property, so it declares it here and
     * every consumer inherits one. `?int` and `null` mean "nothing to undo".
     */
    public ?int $undoableId = null;

    /**
     * Delete a commit the reader wrote.
     *
     * Deletion is a tombstone, not a row disappearing: the conversation under
     * the commit has to survive. The row stays in the stream, authorless.
     *
     * The row is re-read through `visible()` **and** the reader's own id, so a
     * second account cannot delete someone else's commit by posting an id at
     * this method directly — the confirm strip in the markup is a convenience,
     * not the guard.
     */
    public function deleteCommit(int $id): void
    {
        $reader = $this->commitReader();

        if ($reader === null) {
            return;
        }

        $post = FeedPost::query()->visible()->where('user_id', $reader->id)->find($id);

        if ($post === null) {
            return;
        }

        $post->forceFill(['deleted' => true])->save();

        $this->undoableId = $post->id;

        $this->commitStateChanged();
    }

    /**
     * Put back the commit the last delete removed.
     *
     * A delete is the one irreversible-looking action on the page, so it is
     * reversible for as long as the toast stands. The row is restored rather
     * than re-created, which keeps its stars, its thread and its id — the
     * conversation under it never knew it was gone.
     */
    public function undoDelete(): void
    {
        $reader = $this->commitReader();

        if ($this->undoableId === null || $reader === null) {
            $this->undoableId = null;

            return;
        }

        $post = FeedPost::query()
            ->where('user_id', $reader->id)
            ->find($this->undoableId);

        $this->undoableId = null;

        if ($post === null) {
            return;
        }

        $post->forceFill(['deleted' => false])->save();

        $this->commitStateChanged();
    }

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
