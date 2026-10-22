<?php

namespace Database\Factories;

use App\Models\LabWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabWorkspace>
 */
class LabWorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A factory-made workspace has been written but never run: the three panes hold
     * something, and `last_run_at` is null because nobody has pressed the button.
     * That is the state a saved scratch buffer is actually in most of the time, and
     * it keeps a test that wants a run history from getting one by accident.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => rtrim(fake()->sentence(3), '.'),
            'html' => '<h1>'.fake()->sentence(4)."</h1>\n<p>".fake()->sentence(10).'</p>',
            'css' => 'body { font-family: system-ui; padding: 2rem; }',
            'js' => "console.log('".fake()->word()."');",
            'last_run_at' => null,
        ];
    }

    /**
     * Record that the workspace has been run.
     */
    public function ran(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_run_at' => fake()->dateTimeBetween('-1 week', 'now'),
        ]);
    }

    /**
     * Empty the panes, leaving the name.
     *
     * Exists to be tested against: a workspace with nothing in it is what the
     * sandbox shows a signed-in account with no saved work, and the frame must not
     * render a document nobody wrote.
     */
    public function emptied(): static
    {
        return $this->state(fn (array $attributes) => [
            'html' => null,
            'css' => null,
            'js' => null,
        ]);
    }
}
