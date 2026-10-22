<?php

namespace Database\Factories;

use App\Enums\LabLanguage;
use App\Models\LabExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LabExecution>
 */
class LabExecutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A factory-made run is one the account performed a moment ago in the browser's
     * own language. The label is a sentence rather than a relation on purpose: a
     * test that wants history to survive the deletion of its source needs a row that
     * does not depend on one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'language' => LabLanguage::Html,
            'label' => rtrim(fake()->sentence(4), '.'),
            'created_at' => Carbon::now(),
        ];
    }

    /**
     * Move the run to a given moment.
     *
     * The daily allowance is counted by day, so the boundary cases are a row at
     * 23:59 yesterday and a row at 00:01 today. This is how both get written without
     * anyone reaching for `forceFill` in a test file.
     */
    public function at(Carbon $moment): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $moment,
        ]);
    }

    /**
     * Move the run to a day the account has already used up.
     */
    public function yesterday(): static
    {
        return $this->at(Carbon::now()->subDay());
    }

    /**
     * Label the run with the name of the thing that was run.
     */
    public function labelled(string $label): static
    {
        return $this->state(fn (array $attributes) => [
            'label' => $label,
        ]);
    }

    /**
     * Record the run in a given language.
     *
     * A run history row keeps the language it was made in rather than pointing at a
     * snippet, so the language is the column the history screen filters on and the one
     * a test needs to set by name.
     */
    public function language(LabLanguage $language): static
    {
        return $this->state(fn (array $attributes) => [
            'language' => $language,
        ]);
    }
}
