<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Enums\WorkPriority;
use App\Models\IdeaPlan;
use App\Models\IdeaTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaTask>
 */
class IdeaTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * An untouched line at the top of somebody's checklist: no priority, no due
     * date, nothing completed. `sort_order` follows the sequence of the factory so a
     * list made with `->count(4)` arrives in the order it was asked for.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => IdeaPlan::factory(),
            'title' => rtrim(fake()->sentence(5), '.'),
            'description' => null,
            'status' => TaskStatus::Todo,
            'priority' => null,
            'due_date' => null,
            'sort_order' => fake()->numberBetween(0, 50),
            'completed_at' => null,
        ];
    }

    /**
     * Indicate that the task is finished, with the timestamp that proves it.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Done,
            'completed_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ]);
    }

    /**
     * Indicate that the task is being worked on.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::InProgress,
        ]);
    }

    /**
     * Indicate that the task is stuck on something outside itself.
     */
    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Blocked,
            'description' => fake()->sentence(10),
        ]);
    }

    /**
     * Give the task a due date in the past, unfinished.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Todo,
            'due_date' => fake()->dateTimeBetween('-3 weeks', '-1 day')->format('Y-m-d'),
        ]);
    }

    /**
     * Rank the task.
     */
    public function priority(WorkPriority $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
    }
}
