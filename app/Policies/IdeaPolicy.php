<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Idea;
use App\Models\User;

/**
 * Access rules for ideas.
 *
 * Ownership is the deciding fact, which is what separates this module from the AI
 * Hub: an idea is one account's thinking, and no permission makes somebody else's
 * draft theirs to read. `admin:access` is the one exemption, and it is an exemption
 * on reading and on removal only. An operator can see across accounts to run the
 * console, and can take down work that should not be on the public site, but cannot
 * rewrite somebody's plan for them.
 */
class IdeaPolicy
{
    /**
     * Determine whether the user can list ideas in their own workspace.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(AccountPermission::UseIdeas);
    }

    /**
     * Determine whether the user can read the idea.
     */
    public function view(User $user, Idea $idea): bool
    {
        return $idea->isVisibleTo($user);
    }

    /**
     * Determine whether the user can capture ideas.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can rewrite the idea.
     *
     * Authors only. The admin console changes status through a screen that does not
     * exist in this build, so there is nothing for an operator to edit here that
     * would not read as an intrusion.
     */
    public function update(User $user, Idea $idea): bool
    {
        return $idea->isOwnedBy($user);
    }

    /**
     * Determine whether the user can delete the idea.
     */
    public function delete(User $user, Idea $idea): bool
    {
        return $idea->isOwnedBy($user)
            || $user->hasPermission(AccountPermission::AccessAdminPanel);
    }

    /**
     * Determine whether the user can decide whether the idea is public.
     */
    public function publish(User $user, Idea $idea): bool
    {
        return $this->update($user, $idea);
    }

    /**
     * Determine whether the user can pin the idea.
     */
    public function pin(User $user, Idea $idea): bool
    {
        return $this->update($user, $idea);
    }
}
