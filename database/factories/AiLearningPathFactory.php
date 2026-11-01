<?php

namespace Database\Factories;

use App\Enums\AiDifficulty;
use App\Enums\AiPublicationStatus;
use App\Models\AiLearningPath;
use App\Models\AiTopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiLearningPath>
 */
class AiLearningPathFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->sentence(5), '.');

        return [
            'ai_topic_id' => null,
            'user_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => fake()->paragraph(2),
            'difficulty' => AiDifficulty::Beginner,
            // New hub content is a draft until explicitly published.
            'status' => AiPublicationStatus::Draft,
            'is_featured' => false,
            'prerequisites' => null,
            'meta_title' => null,
            'meta_description' => null,
            'published_at' => null,
        ];
    }

    /**
     * Indicate that the path is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AiPublicationStatus::Published,
            'published_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ]);
    }

    /**
     * Indicate that the path is featured on the hub landing page.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * File the path under an existing topic.
     */
    public function topic(AiTopic $topic): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_topic_id' => $topic->id,
        ]);
    }

    /**
     * Attach ordered lesson slots pointing at the given articles.
     *
     * Slots are written straight onto the pivot model rather than through a
     * factory because the item rows are an ordered list, not standalone
     * content: the sort order the tests assert *is* the product.
     *
     * @param  array<int, int>  $articleIds
     */
    public function withLessons(array $articleIds): static
    {
        return $this->afterCreating(function (AiLearningPath $path) use ($articleIds): void {
            foreach (array_values($articleIds) as $order => $articleId) {
                $path->items()->create([
                    'ai_article_id' => $articleId,
                    'title' => null,
                    'note' => null,
                    'sort_order' => $order,
                ]);
            }
        });
    }
}
