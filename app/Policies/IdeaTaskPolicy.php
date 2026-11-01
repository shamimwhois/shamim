<?php

namespace App\Policies;

use App\Models\IdeaTask;
use App\Models\User;

/**
 * Access rules for tasks.
 *
 * A task has no owner column of its own, so every decision here is made by the plan
 * that lists it. That is not a convenience: it is the reason a task cannot end up
 * belonging to an account that never saw the plan, and why the rule for editing a
 * task and the rule for editing a plan can never disagree.
 */
class IdeaTaskPolicy
{
    private IdeaPlanPolicy $planPolicy;

    public function __construct()
    {
        $this->planPolicy = new IdeaPlanPolicy;
    }

    /**
     * Determine whether the user can read the task.
     */
    public function view(User $user, IdeaTask $task): bool
    {
        return $this->planPolicy->view($user, $task->plan);
    }

    /**
     * Determine whether the user can add a task to the given plan.
     */
    public function create(User $user, IdeaTask $task): bool
    {
        return $this->planPolicy->update($user, $task->plan);
    }

    /**
     * Determine whether the user can rewrite the task.
     */
    public function update(User $user, IdeaTask $task): bool
    {
        return $this->planPolicy->update($user, $task->plan);
    }

    /**
     * Determine whether the user can delete the task.
     *
     * Authors only. An operator who wanted to remove a plan's checklist would delete
     * the plan; reaching through to a single line is not a moderation decision.
     */
    public function delete(User $user, IdeaTask $task): bool
    {
        return $this->planPolicy->update($user, $task->plan);
    }
}
