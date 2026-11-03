<?php

namespace App\Actions\Ideas;

use App\Enums\ContentVisibility;
use App\Enums\IdeaStatus;
use App\Models\Idea;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The single writer of an idea's visibility and publish timestamp.
 *
 * `visibility` and `published_at` are an invariant rather than two fields: an idea
 * is publicly readable only when *both* say it is. Giving one class that pair is
 * what makes "nothing reaches the public site before it is approved" a fact of the
 * code instead of a habit of the form.
 *
 * The timestamp is stamped once and never rewritten, and it is not cleared when an
 * idea goes back to private. An account unpublishing work is hiding it, not erasing
 * when it was first worth reading, and `docs/` describes publication as a decision
 * an account takes rather than a log it edits.
 */
class SyncIdeaVisibility
{
    /**
     * Move an idea to the requested visibility, persisting the pair.
     *
     * The action writes rather than mutates, so `visibility` and `published_at`
     * can never be separated by a caller that forgot a `save()` between them.
     */
    public function handle(Idea $idea, ContentVisibility $target): Idea
    {
        if ($target->isPublic()) {
            $this->assertReadyToPublish($idea);

            $idea->published_at ??= Carbon::now();
        }

        $idea->visibility = $target;
        $idea->save();

        return $idea;
    }

    /**
     * Publish an idea, refusing when the workflow is not satisfied.
     */
    public function publish(Idea $idea): Idea
    {
        return $this->handle($idea, ContentVisibility::Public);
    }

    /**
     * Return an idea to its author only.
     */
    public function hide(Idea $idea): Idea
    {
        return $this->handle($idea, ContentVisibility::Private);
    }

    /**
     * Determine whether the idea satisfies the rules for going public.
     *
     * An inbox capture is not a finished thought, and publishing one would put an
     * account's unfinished notes on a page a stranger can read. `Approved` is the
     * stage that means the account decided otherwise.
     */
    protected function assertReadyToPublish(Idea $idea): void
    {
        if ($idea->status !== IdeaStatus::Approved) {
            throw new InvalidArgumentException(
                __('An idea has to be approved before it can be published. It is currently :status.', [
                    'status' => $idea->status->label(),
                ])
            );
        }
    }
}
