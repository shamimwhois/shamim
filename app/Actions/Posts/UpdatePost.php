<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

class UpdatePost
{
    public function __construct(
        protected HydratePostContent $hydrateContent,
        protected SyncPostTags $syncTags,
    ) {}

    /**
     * Persist authored changes to a post.
     *
     * Status is not written here. A requested status change is delegated to the
     * transition action that owns it, so the invariant between `status` and
     * `published_at` holds regardless of the entry point.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>|null  $tagIds
     */
    public function handle(Post $post, array $data, ?array $tagIds = null): Post
    {
        return DB::transaction(function () use ($post, $data, $tagIds): Post {
            $derived = $this->hydrateContent->handle([
                'body' => $data['body'] ?? $post->body,
                'excerpt' => $data['excerpt'] ?? $post->excerpt,
            ]);

            $post->fill([
                'category_id' => array_key_exists('category_id', $data) ? $data['category_id'] : $post->category_id,
                'title' => $data['title'] ?? $post->title,
                'body' => $data['body'] ?? $post->body,
                'excerpt' => $derived['excerpt'],
                'is_featured' => array_key_exists('is_featured', $data)
                    ? (bool) $data['is_featured']
                    : $post->is_featured,
            ]);

            if (array_key_exists('slug', $data) && filled($data['slug'])) {
                $slug = $data['slug'];

                // The slug is part of the public URL, so it must be unique
                // across the whole table — published posts and soft-deleted
                // tombstones both hold the UNIQUE index. Suffixing preserves
                // the author's wording; assigning it verbatim would throw on
                // the index or silently break the other post's link.
                if (Post::withTrashed()
                    ->where('slug', $slug)
                    ->whereKeyNot($post->getKey())
                    ->exists()) {
                    $slug = Post::generateUniqueSlug($slug, $post->getKey());
                }

                $post->slug = $slug;
            }

            $post->forceFill([
                'body_html' => $derived['body_html'],
                'body_text' => $derived['body_text'],
                'word_count' => $derived['word_count'],
                'reading_time' => $derived['reading_time'],
            ]);

            $post->save();

            if ($tagIds !== null) {
                $this->syncTags->handle($post, $tagIds);
            }

            if (array_key_exists('status', $data)) {
                $status = $data['status'] instanceof PostStatus
                    ? $data['status']
                    : PostStatus::from($data['status']);

                if ($status !== $post->status) {
                    match ($status) {
                        PostStatus::Published => app(PublishPost::class)->handle($post),
                        PostStatus::Draft => app(UnpublishPost::class)->handle($post),
                        PostStatus::Archived => app(ArchivePost::class)->handle($post),
                    };
                }
            }

            return $post->refresh();
        });
    }
}
