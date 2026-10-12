<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
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
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(12),
            'color' => fake()->randomElement(['teal', 'amber', 'violet', 'rose', 'cyan', 'lime']),
            'posts_count' => 0,
        ];
    }
}
