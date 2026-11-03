<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\LabSnippet;
use App\Models\User;

/**
 * Access rules for snippets.
 *
 * Ownership decides writing, publication decides reading, and `admin:access` is the
 * one exemption on both sides. An operator reads across accounts to run the console
 * and may take down a snippet that should not have been published, but cannot edit
 * anybody's code: a rewrite of somebody else's work would read as an intrusion even
 * when it was meant as a favour, and moderation is a delete decision.
 *
 * The read rule delegates to {@see LabSnippet::isVisibleTo()} rather than restating
 * it, so the gallery, the public page, the workspace and the console all answer the
 * same question with the same function.
 */
class LabSnippetPolicy
{
    /**
     * Determine whether the user can list snippets in their own workspace.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(AccountPermission::UseDeveloperLab);
    }

    /**
     * Determine whether the user can read the snippet.
     */
    public function view(User $user, LabSnippet $snippet): bool
    {
        return $snippet->isVisibleTo($user);
    }

    /**
     * Determine whether the user can save snippets.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can rewrite the snippet.
     *
     * Authors only.
     */
    public function update(User $user, LabSnippet $snippet): bool
    {
        return $snippet->isOwnedBy($user);
    }

    /**
     * Determine whether the user can delete the snippet.
     */
    public function delete(User $user, LabSnippet $snippet): bool
    {
        return $snippet->isOwnedBy($user)
            || $user->hasPermission(AccountPermission::AccessAdminPanel);
    }

    /**
     * Determine whether the user can decide whether the snippet is public.
     */
    public function publish(User $user, LabSnippet $snippet): bool
    {
        return $this->update($user, $snippet);
    }
}
