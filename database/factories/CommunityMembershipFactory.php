<?php

namespace Database\Factories;

use App\Enums\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityMembership>
 */
class CommunityMembershipFactory extends Factory
{
    protected $model = CommunityMembership::class;

    /**
     * Define the model's default state.
     *
     * The table has no `updated_at` — a membership's only mutable field is its
     * role, which the action classes never rewrite — and the database enforces
     * one row per account per community, so a test building two memberships for
     * one account must give them two communities.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'community_id' => Community::factory(),
            'user_id' => User::factory(),
            'role' => CommunityRole::Member,
            'joined_at' => now(),
        ];
    }

    /**
     * Bind the membership to a community the test already has.
     */
    public function in(Community $community): static
    {
        return $this->state(fn (array $attributes) => ['community_id' => $community->id]);
    }

    /**
     * Bind the membership to an account the test already has.
     */
    public function heldBy(User $user): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => $user->id]);
    }

    public function as(CommunityRole $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    /**
     * The account that created the community, not an appointed moderator.
     */
    public function owner(): static
    {
        return $this->as(CommunityRole::Owner);
    }

    public function moderator(): static
    {
        return $this->as(CommunityRole::Moderator);
    }
}
