<?php

namespace Database\Factories;

use App\Enums\PlanStatus;
use App\Enums\WorkPriority;
use App\Models\Idea;
use App\Models\IdeaPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdeaPlan>
 */
class IdeaPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A factory-made plan is an empty document somebody has titled: no problem
     * statement, no scope, no dates, and `Draft` because nothing has been decided
     * about it yet. That is the state a real plan starts in, and a test that wants a
     * planned-out document should say so through a state rather than get one by
     * accident.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->sentence(5), '.');

        return [
            'user_id' => User::factory(),
            'idea_id' => null,
            'title' => $title,
            'slug' => Str::slug($title),
            'summary' => fake()->sentence(10),
            'problem_statement' => null,
            'proposed_solution' => null,
            'scope' => null,
            'out_of_scope' => null,
            'goals' => null,
            'notes' => null,
            'status' => PlanStatus::Draft,
            'priority' => null,
            'estimated_effort' => null,
            'target_start_date' => null,
            'target_end_date' => null,
        ];
    }

    /**
     * Give the plan the sections a reviewer would expect before agreeing to it.
     */
    public function documented(): static
    {
        return $this->state(fn (array $attributes) => [
            'problem_statement' => fake()->paragraph(),
            'proposed_solution' => fake()->paragraph(),
            'scope' => fake()->sentence(14),
            'out_of_scope' => fake()->sentence(10),
            'goals' => fake()->sentence(8),
            'notes' => fake()->paragraphs(2, true),
            'estimated_effort' => fake()->randomElement(['2 days', '1 week', 'a sprint', 'two weeks']),
            'target_start_date' => fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
            'target_end_date' => fake()->dateTimeBetween('+1 month', '+3 months')->format('Y-m-d'),
        ]);
    }

    /**
     * Indicate that the plan is being worked on.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlanStatus::InProgress,
        ]);
    }

    /**
     * Indicate that the plan is agreed and waiting to start.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlanStatus::Ready,
        ]);
    }

    /**
     * Indicate that the plan is finished.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlanStatus::Completed,
        ]);
    }

    /**
     * Indicate that the plan was called off.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PlanStatus::Cancelled,
        ]);
    }

    /**
     * Say which idea this plan was converted from.
     */
    public function fromIdea(Idea $idea): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $idea->user_id,
            'idea_id' => $idea->id,
            'title' => $idea->title,
            'slug' => $idea->slug.'-plan',
        ]);
    }

    /**
     * Rank the plan.
     */
    public function priority(WorkPriority $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
    }
}
