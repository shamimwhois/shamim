<?php

namespace Database\Factories;

use App\Enums\AboutSectionType;
use App\Models\AboutSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AboutSection>
 */
class AboutSectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $heading = Str::title(fake()->sentence(3));

        return [
            'key' => 'about-'.Str::snake(fake()->unique()->word()),
            'type' => fake()->randomElement(AboutSectionType::cases()),
            'ordinal' => fake()->numberBetween(1, 20),
            'label' => Str::title(fake()->word()),
            'heading' => rtrim($heading, '.'),
            'lead' => fake()->sentence(14),
            'body' => null,
            'icon' => fake()->randomElement([
                'user-circle',
                'sparkles',
                'clipboard-document-list',
                'circle-stack',
                'briefcase',
                'code-bracket',
                'puzzle-piece',
                'envelope',
            ]),
            'sort_order' => fake()->numberBetween(0, 20),
            'is_visible' => true,
        ];
    }

    /**
     * Indicate that the section is hidden from the public page.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_visible' => false,
        ]);
    }
}
