<?php

namespace App\Actions\Posts;

use App\Models\Post;
use Illuminate\Database\Eloquent\Collection;

/**
 * Selects further reading for a post.
 *
 * Ranking is a weighted comparison (shared tag count, then shared category,
 * then recency) that reads far more clearly in PHP than as portable SQL. The
 * candidate set is therefore bounded in the database first, and ranked second,
 * so this does not degrade as the corpus grows.
 */
class ResolveRelatedPosts
{
    /**
     * Maximum number of tags considered when building the candidate pool.
     */
    protected const int TAG_FANOUT = 8;

    /**
     * Get up to $limit related published posts.
     *
     * @return Collection<int, Post>
     */
    public function handle(Post $post, int $limit = 3): Collection
    {
        $tagSlugs = $post->tags
            ->take(self::TAG_FANOUT)
            ->pluck('slug');

        if ($tagSlugs->isEmpty() && $post->category_id === null) {
            return new Collection;
        }

        $candidates = Post::query()
            ->published()
            ->with(['category', 'tags'])
            ->whereKeyNot($post->getKey())
            ->where(function ($query) use ($post, $tagSlugs): void {
                if ($tagSlugs->isNotEmpty()) {
                    $query->whereHas('tags', fn ($query) => $query->whereIn('slug', $tagSlugs));
                }

                if ($post->category_id !== null) {
                    $method = $tagSlugs->isNotEmpty() ? 'orWhere' : 'where';

                    $query->{$method}('category_id', $post->category_id);
                }
            })
            ->limit($limit * 12)
            ->get();

        return $this->rank($post, $candidates, $tagSlugs->all())->take($limit);
    }

    /**
     * Rank candidates by tag overlap, then category match, then recency.
     *
     * @param  Collection<int, Post>  $candidates
     * @param  array<int, string>  $tagSlugs
     * @return Collection<int, Post>
     */
    protected function rank(Post $post, Collection $candidates, array $tagSlugs): Collection
    {
        return $candidates
            ->sortByDesc(function (Post $candidate) use ($post, $tagSlugs): array {
                $sharedTags = $candidate->tags->whereIn('slug', $tagSlugs)->count();
                $sameCategory = $post->category_id !== null && $candidate->category_id === $post->category_id;

                return [
                    $sharedTags,
                    $sameCategory ? 1 : 0,
                    $candidate->published_at?->getTimestamp() ?? 0,
                ];
            })
            ->values();
    }
}
