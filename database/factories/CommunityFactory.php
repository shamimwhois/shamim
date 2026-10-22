<?php

namespace Database\Factories;

use App\Enums\CommunityKind;
use App\Models\Community;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Community>
 */
class CommunityFactory extends Factory
{
    protected $model = Community::class;

    /**
     * Define the model's default state.
     *
     * `topic` is the whole association to the feed: a community reads every
     * `FeedPost` carrying the same topic string, with no join table. Deriving it
     * from the name keeps a factory-made community able to match a factory-made
     * post when a test sets both to the same word.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = rtrim(fake()->sentence(3), '.');

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'name' => $name,
            'description' => fake()->sentence(18),
            'topic' => Str::slug($name, ' '),
            'glyph' => Str::upper(Str::substr(Str::slug($name), 0, 3)),
            'kind' => CommunityKind::Open,
            'rules' => null,
        ];
    }

    /**
     * A community whose members were admitted, not merely arrived.
     */
    public function inviteOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => CommunityKind::InviteOnly,
            'rules' => fake()->paragraph(1),
        ]);
    }

    /**
     * Bind the community to a feed topic the test controls.
     */
    public function topic(string $topic): static
    {
        return $this->state(fn (array $attributes) => ['topic' => $topic]);
    }
}
