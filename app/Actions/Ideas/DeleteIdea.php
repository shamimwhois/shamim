<?php

namespace App\Actions\Ideas;

use App\Models\Idea;

/**
 * Delete an idea for good.
 *
 * These rows carry no trash column, so this is the only kind of removal the module
 * offers and the screen is expected to make the cost visible before calling it.
 *
 * An idea that was converted into a plan leaves that plan standing: the link
 * severs, because `idea_plans.idea_id` is nullable and nulls on delete, and a plan
 * is the larger artefact. `docs/Idea.md` requires conversion to preserve the
 * original idea, which is a rule about converting; nothing in it promises an idea
 * cannot be deleted afterwards.
 */
class DeleteIdea
{
    public function handle(Idea $idea): void
    {
        $idea->delete();
    }
}
