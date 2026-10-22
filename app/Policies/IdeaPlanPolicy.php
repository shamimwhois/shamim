<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\IdeaPlan;
use App\Models\User;

/**
 * Access rules for plans.
 *
 * The same ownership-first shape as an idea, with one difference worth stating: a
 * plan is never public, so `view` has no published branch. An account may read its
 * own plans, and an operator may read anybody's to run the console, and nobody else
 * on the platform can see one at all.
 */
class IdeaPlanPolicy
{
    /**
     * Determine whether the user can list plans in their own workspace.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(AccountPermission::UseIdeas);
    }

    /**
     * Determine whether the user can read the plan.
     */
    public function view(User $user, IdeaPlan $plan): bool
    {
        return $plan->isVisibleTo($user);
    }

    /**
     * Determine whether the user can write plans.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can rewrite the plan.
     *
     * Authors only, for the reason `IdeaPolicy` gives: an operator moderates, they do
     * not do other people's planning.
     */
    public function update(User $user, IdeaPlan $plan): bool
    {
        return $plan->isOwnedBy($user);
    }

    /**
     * Determine whether the user can delete the plan.
     */
    public function delete(User $user, IdeaPlan $plan): bool
    {
        return $plan->isOwnedBy($user)
            || $user->hasPermission(AccountPermission::AccessAdminPanel);
    }
}
