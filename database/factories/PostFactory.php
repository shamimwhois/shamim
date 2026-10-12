<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use App\Support\MarkdownRenderer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->sentence(6), '.');
        $body = $this->makeBody($title);

        // Derive the rendered projections from the markdown body, exactly as
        // HydratePostContent does on the write path. A factory that invented an
        // unrelated body_html made every table-of-contents, heading-anchor and
        // reading-time assertion untestable, because the stored HTML and the
        // stored markdown disagreed.
        $renderer = app(MarkdownRenderer::class);
        $plainText = $renderer->toPlainText($body);
        $words = str_word_count(strip_tags($plainText));

        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'excerpt' => fake()->paragraph(2),
            'body' => $body,
            'body_html' => $renderer->render($body),
            'body_text' => $plainText,
            'status' => PostStatus::Draft,
            'is_featured' => false,
            // Mirrors HydratePostContent: 200 wpm, never less than a minute.
            'reading_time' => $words === 0 ? 1 : max(1, (int) ceil($words / 200)),
            'word_count' => $words,
            'view_count' => 0,
            'published_at' => null,
        ];
    }

    /**
     * Indicate that the post is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Published,
            'published_at' => fake()->dateTimeBetween('-10 months', 'now'),
        ]);
    }

    /**
     * Indicate that the post is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Archived,
            'published_at' => fake()->dateTimeBetween('-18 months', '-6 months'),
        ]);
    }

    /**
     * Indicate that the post is featured.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * Set the markdown body and re-derive every projection it feeds.
     *
     * Overriding `body` through the generic `create([...])` path would leave
     * `body_html`, `body_text`, `word_count` and `reading_time` describing the
     * discarded body — the stored HTML and the stored markdown would disagree,
     * which silently breaks any assertion about headings or the table of
     * contents. This state keeps them consistent.
     */
    public function body(string $markdown): static
    {
        return $this->state(function (array $attributes) use ($markdown): array {
            $renderer = app(MarkdownRenderer::class);
            $plainText = $renderer->toPlainText($markdown);
            $words = str_word_count(strip_tags($plainText));

            return [
                'body' => $markdown,
                'body_html' => $renderer->render($markdown),
                'body_text' => $plainText,
                'word_count' => $words,
                'reading_time' => $words === 0 ? 1 : max(1, (int) ceil($words / 200)),
            ];
        });
    }

    /**
     * Attach a set of existing tags to the created post.
     *
     * @param  array<int, int>  $tagIds
     */
    public function withTags(array $tagIds): static
    {
        return $this->afterCreating(function (Post $post) use ($tagIds): void {
            $post->tags()->sync($tagIds);
        });
    }

    /**
     * Build a representative markdown body for the seeded post.
     */
    protected function makeBody(string $title): string
    {
        $paragraph = fake()->paragraph(6);

        return <<<MARKDOWN
        # {$title}

        {$paragraph}

        ## The problem

        {$paragraph}

        ```php
        \$result = Post::query()->published()->recent()->paginate(9);
        ```

        ## The approach

        {$paragraph}

        ### Trade-offs

        {$paragraph}

        ## What I would do differently

        {$paragraph}
        MARKDOWN;
    }
}
