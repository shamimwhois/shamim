<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\AiArticle;
use App\Models\User;

/**
 * Access rules for hub articles.
 *
 * Hub content is operator-authored rather than per-user, so ownership is not the
 * deciding fact — the `ai-hub:manage` permission is. The author is given a
 * narrow exemption on reads only, so an operator can look at their own work in
 * progress without holding the whole console.
 */
class AiArticlePolicy
{
    /**
     * Determine whether the reader can see the article on a public surface.
     */
    public function viewPublic(?User $user, AiArticle $article): bool
    {
        return $article->isVisibleTo($user);
    }

    /**
     * Determine whether the user can list articles in the console.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(AccountPermission::ManageAiHub);
    }

    /**
     * Determine whether the user can open the article in the console.
     */
    public function view(User $user, AiArticle $article): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can author hub articles.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can rewrite the article.
     */
    public function update(User $user, AiArticle $article): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can delete the article.
     */
    public function delete(User $user, AiArticle $article): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can move the article through the workflow.
     */
    public function publish(User $user, AiArticle $article): bool
    {
        return $this->viewAny($user);
    }
}
