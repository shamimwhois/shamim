<?php

namespace Database\Factories;

use App\Actions\AiHub\HydrateAiArticleContent;
use App\Enums\AiArticleFormat;
use App\Enums\AiDifficulty;
use App\Enums\AiPublicationStatus;
use App\Models\AiArticle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiArticle>
 */
class AiArticleFactory extends Factory
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

        // The derived projections come from the same hydrator the write path
        // uses, so a factory-made article is indistinguishable from a console
        // one: the stored HTML, plain text, word count, and reading time all
        // describe the stored markdown.
        $derived = app(HydrateAiArticleContent::class)->handle(['body' => $body]);

        return [
            'user_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'summary' => fake()->sentence(10),
            'format' => AiArticleFormat::Article,
            'difficulty' => AiDifficulty::Beginner,
            // New hub content is a draft until explicitly published.
            'status' => AiPublicationStatus::Draft,
            'is_featured' => false,
            'cover_image' => null,
            'prerequisites' => null,
            'meta_title' => null,
            'meta_description' => null,
            'view_count' => 0,
            'published_at' => null,
        ] + $derived;
    }

    /**
     * Indicate that the article is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AiPublicationStatus::Published,
            'published_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ]);
    }

    /**
     * Indicate that the article is waiting for technical review.
     */
    public function review(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AiPublicationStatus::Review,
        ]);
    }

    /**
     * Indicate that the article is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AiPublicationStatus::Archived,
            'published_at' => fake()->dateTimeBetween('-18 months', '-6 months'),
        ]);
    }

    /**
     * Indicate that the article is featured on the hub landing page.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * Set the markdown body and re-derive every projection it feeds.
     */
    public function body(string $markdown): static
    {
        return $this->state(fn (array $attributes) => app(HydrateAiArticleContent::class)->handle([
            'body' => $markdown,
        ]));
    }

    /**
     * Use a specific content format.
     */
    public function format(AiArticleFormat $format): static
    {
        return $this->state(fn (array $attributes) => [
            'format' => $format,
        ]);
    }

    /**
     * Use a specific difficulty rung.
     */
    public function difficulty(AiDifficulty $difficulty): static
    {
        return $this->state(fn (array $attributes) => [
            'difficulty' => $difficulty,
        ]);
    }

    /**
     * File the created article under a set of existing topics.
     *
     * @param  array<int, int>  $topicIds
     */
    public function withTopics(array $topicIds): static
    {
        return $this->afterCreating(function (AiArticle $article) use ($topicIds): void {
            $article->topics()->sync($topicIds);
        });
    }

    /**
     * Build a representative markdown body for the seeded article.
     */
    protected function makeBody(string $title): string
    {
        $paragraph = fake()->paragraph(5);

        return <<<MARKDOWN
        # {$title}

        {$paragraph}

        ## Why it matters

        {$paragraph}

        ```php
        \$articles = AiArticle::query()->readableOrder()->paginate(9);
        ```

        ## How to apply it

        {$paragraph}

        ### Common pitfall

        {$paragraph}
        MARKDOWN;
    }
}
