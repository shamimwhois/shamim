<?php

namespace App\Actions\Ideas;

use App\Models\Idea;

/**
 * Pin or unpin an idea in its owner's workspace.
 *
 * A pin is a reading order, not a status: it says "keep this near the top of what
 * I am looking at" and deliberately does not move the idea through the workflow or
 * make it more publishable. That is why it is a boolean on the row rather than a
 * sixth `IdeaStatus` case, and why the workspace's default ordering is
 * pinned-then-newest rather than a separate "pinned" queue.
 */
class ToggleIdeaPin
{
    /**
     * Flip the pin and return the idea carrying its new value.
     */
    public function handle(Idea $idea): Idea
    {
        $idea->forceFill(['is_pinned' => ! $idea->is_pinned])->save();

        return $idea->refresh();
    }
}
