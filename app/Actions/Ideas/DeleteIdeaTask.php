<?php

namespace App\Actions\Ideas;

use App\Models\IdeaTask;

/**
 * Delete one task from a plan.
 *
 * Final, because a checklist line is cheap to write again and a tombstone would be
 * the only way to see what used to be on it. The plan's progress ratio moves when a
 * task disappears, which is the honest behaviour: removing work you are not going to
 * do should not keep the plan looking half finished.
 */
class DeleteIdeaTask
{
    public function handle(IdeaTask $task): void
    {
        $task->delete();
    }
}
