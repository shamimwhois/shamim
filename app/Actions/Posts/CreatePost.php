<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatePost
{
    public function __construct(
        protected HydratePostContent $hydrateContent,
        protected SyncPostTags $syncTags,
    ) {}

    /**
     * Create a post for the given author.
     *
     * The row is always written as a draft. Publishing is delegated to
     * {@see PublishPost}, which owns the status/published_at invariant, so this
     * action cannot produce an inconsistently published post.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $tagIds
     */
    public function handle(User $author, array $data, array $tagIds = []): Post
    {
        return DB::transaction(function () use ($author, $data, $tagIds): Post {
            $derived = $this->hydrateContent->handle([
                'body' => $data['body'] ?? '',
                'excerpt' => $data['excerpt'] ?? null,
            ]);

            // Read `status` defensively: it is optional, and accessing it before
            // the null-coalesce guard throws when the caller omits the key.
            $requestedStatus = ($data['status'] ?? null) instanceof PostStatus
                ? $data['status']
                : PostStatus::from($data['status'] ?? PostStatus::Draft->value);

            $post = new Post;

            $post->fill([
                'user_id' => $author->id,
                'category_id' => $data['category_id'] ?? null,
                'title' => $data['title'],
                'body' => $data['body'] ?? '',
                'slug' => $this->resolveSlug($data['slug'] ?? null, $data['title']),
                'excerpt' => $derived['excerpt'],
                'status' => PostStatus::Draft,
                'is_featured' => (bool) ($data['is_featured'] ?? false),
                'published_at' => null,
            ]);

            $post->forceFill([
                'body_html' => $derived['body_html'],
                'body_text' => $derived['body_text'],
                'word_count' => $derived['word_count'],
                'reading_time' => $derived['reading_time'],
            ]);

            $post->save();

            $this->syncTags->handle($post, $tagIds);

            if ($requestedStatus === PostStatus::Published) {
                app(PublishPost::class)->handle($post);
            }

            return $post->refresh();
        });
    }

    /**
     * Choose the slug to persist, keeping the author's wording where possible.
     *
     * The editor's slug field is auto-filled from the title but is editable, and
     * the author's choice is the one that ends up in the public URL — so it is
     * preserved rather than regenerated. It still has to be made unique, because
     * `slug` carries a UNIQUE index and a second post genuinely cannot reuse it.
     * The check covers every row in the table, published posts included: a
     * published post colliding with this one would otherwise surface as a
     * database error on save, not as a suffixed URL.
     * Duplicating the slug silently would be worse than suffixing it: the author
     * would believe two posts share a URL when only one can.
     */
    protected function resolveSlug(?string $requested, string $title): string
    {
        $requested = trim((string) $requested);

        if ($requested === '') {
            return Post::generateUniqueSlug($title);
        }

        $taken = Post::withTrashed()->where('slug', $requested)->exists();

        return $taken ? Post::generateUniqueSlug($requested) : $requested;
    }
}
