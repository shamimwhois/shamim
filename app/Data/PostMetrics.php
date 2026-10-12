<?php

namespace App\Data;

use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregate content metrics for the author dashboard.
 *
 * Computed from persisted data rather than a third-party analytics service.
 */
readonly class PostMetrics
{
    public function __construct(
        public int $totalPosts,
        public int $publishedPosts,
        public int $draftPosts,
        public int $archivedPosts,
        public int $totalViews,
        public int $totalComments,
        public int $pendingComments,
    ) {}

    /**
     * Build the metrics for a given author.
     */
    public static function forAuthor(User $author): self
    {
        return self::fromQuery(Post::query()->authoredBy($author));
    }

    /**
     * Build metrics across the whole site, for guests.
     *
     * The public landing page has no authenticated user, so author scoping is
     * meaningless there. Only published content is counted: a visitor should
     * never see totals that include drafts or archived posts.
     */
    public static function forSite(): self
    {
        return self::fromQuery(Post::query());
    }

    /**
     * @param  Builder<Post>  $posts
     */
    protected static function fromQuery(Builder $posts): self
    {
        $postIds = (clone $posts)->pluck('id');

        return new self(
            totalPosts: (clone $posts)->count(),
            publishedPosts: (clone $posts)->where('status', PostStatus::Published)->count(),
            draftPosts: (clone $posts)->where('status', PostStatus::Draft)->count(),
            archivedPosts: (clone $posts)->where('status', PostStatus::Archived)->count(),
            totalViews: (int) (clone $posts)->sum('view_count'),
            totalComments: $postIds->isEmpty() ? 0 : Comment::query()->whereIn('post_id', $postIds)->count(),
            pendingComments: $postIds->isEmpty()
                ? 0
                : Comment::query()->whereIn('post_id', $postIds)->where('status', CommentStatus::Pending)->count(),
        );
    }

    /**
     * Get the dashboard tiles as a label/value list.
     *
     * @return array<int, array{label: string, value: int, icon: string}>
     */
    public function tiles(): array
    {
        return [
            ['label' => __('Total posts'), 'value' => $this->totalPosts, 'icon' => 'document-text'],
            ['label' => __('Published'), 'value' => $this->publishedPosts, 'icon' => 'globe-alt'],
            ['label' => __('Drafts'), 'value' => $this->draftPosts, 'icon' => 'pencil-square'],
            ['label' => __('Total views'), 'value' => $this->totalViews, 'icon' => 'eye'],
            ['label' => __('Comments'), 'value' => $this->totalComments, 'icon' => 'chat-bubble-left-right'],
            ['label' => __('Pending moderation'), 'value' => $this->pendingComments, 'icon' => 'shield-exclamation'],
        ];
    }
}
