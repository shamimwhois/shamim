<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;

class ArchivePost
{
    public function __construct(protected SyncTaxonomyCounts $syncCounts) {}

    /**
     * Archive the post, preserving its historical publish timestamp.
     */
    public function handle(Post $post): Post
    {
        if ($post->status === PostStatus::Archived) {
            return $post;
        }

        $post->forceFill(['status' => PostStatus::Archived])->save();

        if ($post->status !== PostStatus::Published) {
            app(SyncTaxonomyCounts::class)->handle($post);
        }

        return $post->refresh();
    }
}
