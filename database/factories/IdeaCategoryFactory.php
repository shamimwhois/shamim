<?php

namespace Database\Factories;

use App\Models\IdeaCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdeaCategory>
 */
class IdeaCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(rtrim(fake()->unique()->sentence(2), '.'));

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
        ];
    }

    /**
     * Give the category a description.
     */
    public function described(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => fake()->sentence(10),
        ]);
    }
}
