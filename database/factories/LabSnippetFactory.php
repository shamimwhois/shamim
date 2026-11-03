<?php

namespace Database\Factories;

use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabSnippet>
 */
class LabSnippetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A factory-made snippet is private HTML: saved, not shown to anybody. Every
     * surface that lists public work filters on the publish pair, so a default that
     * leaked would make an unreadable gallery look green.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->sentence(5), '.');

        return [
            'user_id' => User::factory(),
            'language' => LabLanguage::Html,
            'title' => $title,
            'slug' => Str::slug($title),
            'code' => "<section>\n  <h2>".$title."</h2>\n  <p>".fake()->sentence(8)."</p>\n</section>",
            'description' => null,
            'visibility' => ContentVisibility::Private,
            'view_count' => 0,
            'published_at' => null,
        ];
    }

    /**
     * Make the snippet readable without an account.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => ContentVisibility::Public,
            'published_at' => fake()->dateTimeBetween('-3 months', 'now'),
        ]);
    }

    /**
     * Give the snippet a public flag without the timestamp that completes publication.
     *
     * The pair is the publish rule, so the half-state needs a name here rather than
     * a hand-written column list in whoever is testing it.
     */
    public function unpublishedPublic(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => ContentVisibility::Public,
            'published_at' => null,
        ]);
    }

    /**
     * Write the snippet in the given language.
     *
     * The default `code` moves with the language, because a PHP snippet full of
     * markup would make the escape tests prove nothing about the columns they
     * claim to cover. HTML keeps whatever code the caller already supplied.
     */
    public function language(LabLanguage $language): static
    {
        return $this->state(function (array $attributes) use ($language): array {
            $code = match ($language) {
                LabLanguage::Html => null,
                LabLanguage::Css => ".card {\n  border-radius: 1rem;\n  box-shadow: 0 1rem 2rem rgb(0 0 0 / 0.06);\n}",
                LabLanguage::Javascript => "function debounce(fn, wait = 200) {\n  let timer;\n  return (...args) => {\n    clearTimeout(timer);\n    timer = setTimeout(() => fn(...args), wait);\n  };\n}",
                LabLanguage::Php => "<?php\n\nfunction celsiusToFahrenheit(float \$celsius): float\n{\n    return \$celsius * 9 / 5 + 32;\n}",
            };

            return $code === null
                ? ['language' => $language]
                : ['language' => $language, 'code' => $code];
        });
    }

    /**
     * Write the snippet in PHP, the language this lab stores but will not run.
     */
    public function php(): static
    {
        return $this->language(LabLanguage::Php);
    }

    /**
     * Describe the snippet, so a gallery card has a teaser rather than a code dump.
     */
    public function described(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => fake()->sentence(12),
        ]);
    }

    /**
     * File the created snippet under existing public topics.
     *
     * Written through {@see Topic::syncRows()} so the pivot order a facet renders
     * is the order the save action writes.
     *
     * @param  array<int, int>  $topicIds
     */
    public function withTopics(array $topicIds): static
    {
        return $this->afterCreating(function (LabSnippet $snippet) use ($topicIds): void {
            $snippet->topics()->sync(Topic::syncRows($topicIds));
        });
    }
}
