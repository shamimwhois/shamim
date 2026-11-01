<?php

namespace Database\Factories;

use App\Enums\AiLinkKind;
use App\Models\AiArticle;
use App\Models\AiArticleLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiArticleLink>
 */
class AiArticleLinkFactory extends Factory
{
    protected $model = AiArticleLink::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $host = fake()->domainName();

        return [
            'ai_article_id' => AiArticle::factory(),
            'label' => rtrim(fake()->sentence(3), '.'),
            'url' => 'https://'.$host.'/'.fake()->slug(2),
            'kind' => AiLinkKind::Reference,
            'note' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * A link of one kind, for a console that groups or filters by it.
     */
    public function kind(AiLinkKind $kind): static
    {
        return $this->state(fn (array $attributes) => ['kind' => $kind]);
    }

    /**
     * A repository link, the shape `host()` is most often read for.
     */
    public function repository(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => AiLinkKind::Repository,
            'url' => 'https://github.com/'.fake()->userName().'/'.fake()->slug(1),
        ]);
    }
}
