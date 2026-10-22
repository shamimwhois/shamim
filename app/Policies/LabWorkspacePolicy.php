<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\LabWorkspace;
use App\Models\User;

/**
 * Access rules for workspaces.
 *
 * The strictest policy in the module, and the reason it is separate from
 * {@see LabSnippetPolicy}: a workspace is whatever was in the panes when somebody
 * pressed Save, including the half-written experiment they would not show anybody.
 * There is no published state to earn, and no admin exemption, because an operator
 * has nothing to moderate in another account's scratch buffer. The console that
 * reads across accounts lists snippets and runs, which are the two objects with a
 * reason to be seen.
 */
class LabWorkspacePolicy
{
    /**
     * Determine whether the user can list workspaces in their own workspace.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(AccountPermission::UseDeveloperLab);
    }

    /**
     * Determine whether the user can open the workspace.
     */
    public function view(User $user, LabWorkspace $workspace): bool
    {
        return $workspace->isOwnedBy($user);
    }

    /**
     * Determine whether the user can save workspaces.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can rewrite the workspace.
     */
    public function update(User $user, LabWorkspace $workspace): bool
    {
        return $workspace->isOwnedBy($user);
    }

    /**
     * Determine whether the user can delete the workspace.
     *
     * Authors only, for the same reason an operator cannot read one. A scratch
     * buffer is not content on the site, so there is nothing to take down.
     */
    public function delete(User $user, LabWorkspace $workspace): bool
    {
        return $workspace->isOwnedBy($user);
    }
}
