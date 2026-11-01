<?php

namespace Database\Factories;

use App\Enums\AiPublicationStatus;
use App\Enums\AiResourceKind;
use App\Models\AiResource;
use App\Models\AiTopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiResource>
 */
class AiResourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_topic_id' => null,
            'user_id' => User::factory(),
            'title' => rtrim(fake()->sentence(4), '.'),
            'description' => fake()->sentence(10),
            'url' => fake()->url(),
            'kind' => AiResourceKind::Tool,
            'provider' => fake()->company(),
            // New hub content is a draft until explicitly published.
            'status' => AiPublicationStatus::Draft,
            'is_featured' => false,
            'sort_order' => fake()->numberBetween(0, 50),
            'published_at' => null,
        ];
    }

    /**
     * Indicate that the resource is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AiPublicationStatus::Published,
            'published_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ]);
    }

    /**
     * Indicate that the resource is featured on the resources shelf.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * Use a specific resource kind.
     */
    public function kind(AiResourceKind $kind): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => $kind,
        ]);
    }

    /**
     * File the resource under an existing topic.
     */
    public function topic(AiTopic $topic): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_topic_id' => $topic->id,
        ]);
    }
}
