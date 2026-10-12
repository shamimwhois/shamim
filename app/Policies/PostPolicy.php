<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    /**
     * Determine whether the user can view the post in the dashboard.
     *
     * Authoring is a single-author capability: any authenticated user reaches
     * the dashboard, and per-post access is governed by ownership.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the reader can see the post on the public site.
     *
     * This is the *public readability* question, which is distinct from
     * ownership: a published post is readable by anyone including guests,
     * while a draft or archived post is readable only by its author.
     *
     * `?User` is nullable because the caller is frequently a guest.
     */
    public function viewPublic(?User $user, Post $post): bool
    {
        return $post->isVisibleTo($user);
    }

    /**
     * Determine whether the user can open the post in the dashboard editor.
     *
     * Owner-only. A published post is publicly readable but is not therefore
     * editable by the reader.
     */
    public function view(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    /**
     * Determine whether the user can create posts.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the post.
     */
    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    /**
     * Determine whether the user can delete the post.
     */
    public function delete(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    /**
     * Determine whether the user can restore the post.
     */
    public function restore(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    /**
     * Determine whether the user can permanently delete the post.
     */
    public function forceDelete(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    /**
     * Determine whether the user can publish or unpublish the post.
     */
    public function publish(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }
}
