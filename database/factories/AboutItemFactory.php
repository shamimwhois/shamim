<?php

namespace Database\Factories;

use App\Enums\AboutItemType;
use App\Models\AboutItem;
use App\Models\AboutSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AboutItem>
 */
class AboutItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Built from single words rather than words(3): the latter is typed
        // array|string, which static analysis cannot narrow.
        $tags = implode(', ', [fake()->word(), fake()->word(), fake()->word()]);

        return [
            'about_section_id' => AboutSection::factory(),
            'parent_id' => null,
            'type' => AboutItemType::Entry,
            'title' => fake()->sentence(4),
            'subtitle' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'value' => null,
            'tags' => $tags,
            'icon' => null,
            'url' => null,
            'url_label' => null,
            'period' => fake()->numberBetween(2015, 2026).'-present',
            'sort_order' => fake()->numberBetween(0, 20),
            'is_visible' => true,
        ];
    }

    /**
     * Indicate that the row is hidden from the public page.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_visible' => false,
        ]);
    }

    /**
     * Attach the row as a child of another item.
     */
    public function childOf(AboutItem $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'about_section_id' => $parent->about_section_id,
            'parent_id' => $parent->id,
        ]);
    }
}
