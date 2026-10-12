<?php

namespace App\Actions\Posts;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

/**
 * Reconciles the denormalised published-post counters on categories and tags.
 *
 * Counts are recalculated rather than incremented or decremented. A recount is
 * idempotent, so a transition that runs twice — or a future code path that
 * forgets to touch counters — self-heals on the next write instead of drifting.
 */
class SyncTaxonomyCounts
{
    /**
     * Recount published posts for the post's category and each of its tags.
     */
    public function handle(Post $post): void
    {
        $categoryId = $post->category_id;

        if ($categoryId !== null) {
            $category = Category::query()->find($categoryId);

            if ($category !== null) {
                $category->forceFill([
                    'posts_count' => $category->publishedPosts()->count(),
                ])->save();
            }
        }

        foreach ($post->tags()->get() as $tag) {
            $this->recountTag($tag);
        }
    }

    /**
     * Recount the published posts carrying a tag.
     */
    public function recountTag(Tag $tag): void
    {
        $tag->forceFill([
            'posts_count' => $tag->posts()->published()->count(),
        ])->save();
    }

    /**
     * Recount every category and tag. Used to reconcile drift.
     */
    public function handleAll(): void
    {
        Category::query()->each(function (Category $category): void {
            $category->forceFill([
                'posts_count' => $category->publishedPosts()->count(),
            ])->save();
        });

        Tag::query()->each(fn (Tag $tag) => $this->recountTag($tag));
    }
}
