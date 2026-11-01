<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\PostTag;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostTag>
 */
class PostTagFactory extends Factory
{
    protected $model = PostTag::class;

    /**
     * Define the model's default state.
     *
     * The pivot exists as a factory because some tests need the row rather than
     * the relationship: `post_tag` is what a tag-count, a taxonomy deletion, or
     * the `syncPostsCount()` this model's own saved/deleted hooks call are about,
     * and reaching it through `Post::factory()->tags` would exercise the sync
     * path instead of the row.
     *
     * `Tag::$incrementing` is true but this pivot's primary key is the pair, so
     * the factory must never invent an `id`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'tag_id' => Tag::factory(),
        ];
    }

    public function forPost(Post $post): static
    {
        return $this->state(fn (array $attributes) => ['post_id' => $post->id]);
    }

    public function withTag(Tag $tag): static
    {
        return $this->state(fn (array $attributes) => ['tag_id' => $tag->id]);
    }
}
