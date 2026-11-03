<?php

namespace Database\Factories;

use App\Models\AiTopic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiTopic>
 */
class AiTopicFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Built from two single words rather than words(2, true): the latter is
        // typed array|string, which static analysis cannot narrow.
        $name = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(10),
            'icon' => 'bookmark',
            'sort_order' => fake()->numberBetween(0, 50),
            'is_active' => true,
        ];
    }

    /**
     * File the topic underneath an existing parent.
     */
    public function childOf(AiTopic $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->id,
        ]);
    }

    /**
     * Indicate that the topic has been deactivated by the operator.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
