<?php

namespace App\Actions\Ideas;

use App\Models\IdeaCategory;

/**
 * Remove a category, keeping the ideas filed under it.
 *
 * The column nulls on delete rather than cascading, and that is the whole argument:
 * a folder is metadata about a thought, and deleting the folder has never been a
 * reason to lose the thought. The screen says so in the confirmation copy, because
 * an account that expects a cascade would be surprised by the surviving ideas.
 */
class DeleteIdeaCategory
{
    public function handle(IdeaCategory $category): void
    {
        $category->delete();
    }
}
