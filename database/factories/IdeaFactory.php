<?php

namespace Database\Factories;

use App\Enums\ContentVisibility;
use App\Enums\IdeaPotential;
use App\Enums\IdeaStatus;
use App\Enums\WorkPriority;
use App\Models\Idea;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Idea>
 */
class IdeaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A factory-made idea is an inbox capture: no stage has been chosen, nothing
     * has been ranked, and nothing is public. That is the state a real account
     * starts in, and it keeps a test that wants a published idea from getting one
     * by accident.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->sentence(6), '.');

        return [
            'user_id' => User::factory(),
            'idea_category_id' => null,
            'title' => $title,
            'slug' => Str::slug($title),
            'summary' => fake()->sentence(12),
            'content' => fake()->paragraphs(2, true),
            'status' => IdeaStatus::Inbox,
            'priority' => null,
            'potential' => null,
            'visibility' => ContentVisibility::Private,
            'is_pinned' => false,
            'view_count' => 0,
            'published_at' => null,
        ];
    }

    /**
     * Indicate that the idea is approved and readable without an account.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IdeaStatus::Approved,
            'visibility' => ContentVisibility::Public,
            'published_at' => fake()->dateTimeBetween('-3 months', 'now'),
        ]);
    }

    /**
     * Indicate that the idea is approved but still private.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IdeaStatus::Approved,
        ]);
    }

    /**
     * Indicate that the idea is waiting for review.
     */
    public function underReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IdeaStatus::UnderReview,
        ]);
    }

    /**
     * Indicate that the idea has been closed.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IdeaStatus::Archived,
        ]);
    }

    /**
     * Indicate that the idea is pinned to the top of its owner's workspace.
     */
    public function pinned(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_pinned' => true,
        ]);
    }

    /**
     * Give the idea a public flag without the timestamp that completes publication.
     *
     * The state exists to be tested against: a row whose visibility says public
     * but whose `published_at` is null must stay invisible, and that distinction
     * needs a name in the factory rather than a hand-written column list in
     * whoever is writing the test.
     */
    public function unpublishedPublic(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IdeaStatus::Approved,
            'visibility' => ContentVisibility::Public,
            'published_at' => null,
        ]);
    }

    /**
     * Rank the idea.
     */
    public function priority(WorkPriority $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
    }

    /**
     * Estimate the idea's potential.
     */
    public function potential(IdeaPotential $potential): static
    {
        return $this->state(fn (array $attributes) => [
            'potential' => $potential,
        ]);
    }

    /**
     * File the created idea under an existing category.
     */
    public function inCategory(int $categoryId): static
    {
        return $this->state(fn (array $attributes) => [
            'idea_category_id' => $categoryId,
        ]);
    }

    /**
     * File the created idea under existing public topics.
     *
     * Written through {@see Topic::syncRows()} rather than a bare sync, so a
     * factory row carries the same pivot order the save action would give it and a
     * facet count is tested against the shape the product actually writes.
     *
     * @param  array<int, int>  $topicIds
     */
    public function withTopics(array $topicIds): static
    {
        return $this->afterCreating(function (Idea $idea) use ($topicIds): void {
            $idea->topics()->sync(Topic::syncRows($topicIds));
        });
    }
}
