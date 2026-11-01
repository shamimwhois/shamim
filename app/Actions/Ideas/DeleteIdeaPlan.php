<?php

namespace App\Actions\Ideas;

use App\Models\IdeaPlan;

/**
 * Delete a plan, and with it the tasks under it.
 *
 * These rows carry no trash column, so this is the only kind of removal the module
 * offers. `idea_tasks.plan_id` cascades, which is the correct direction for a
 * checklist: a task that describes nothing has no reason to be kept, and keeping it
 * would mean a row whose ownership is derived from a plan that no longer exists.
 *
 * The idea a plan was converted from is untouched. The link is one-way and nullable,
 * so removing the plan cannot reach backwards into the inbox.
 */
class DeleteIdeaPlan
{
    public function handle(IdeaPlan $plan): void
    {
        $plan->delete();
    }
}
