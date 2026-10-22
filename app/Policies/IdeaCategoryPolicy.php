<?php

namespace App\Policies;

use App\Actions\Ideas\DeleteIdeaCategory;
use App\Enums\AccountPermission;
use App\Models\IdeaCategory;
use App\Models\User;

/**
 * Access rules for idea categories.
 *
 * A category is a folder inside one account's workspace, so there is no public
 * reading rule and no admin exemption: an operator running the ideas console reads
 * ideas, not somebody's filing system. Deleting a category is therefore the only
 * act that needs care, and {@see DeleteIdeaCategory} nulls the
 * link rather than taking the ideas with it.
 */
class IdeaCategoryPolicy
{
    /**
     * Determine whether the user can list and manage their own categories.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(AccountPermission::UseIdeas);
    }

    /**
     * Determine whether the user can read the category.
     */
    public function view(User $user, IdeaCategory $category): bool
    {
        return $category->user_id === $user->id;
    }

    /**
     * Determine whether the user can create categories.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can rename the category.
     */
    public function update(User $user, IdeaCategory $category): bool
    {
        return $category->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the category.
     */
    public function delete(User $user, IdeaCategory $category): bool
    {
        return $category->user_id === $user->id;
    }
}
