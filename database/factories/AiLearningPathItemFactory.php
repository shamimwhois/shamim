<?php

namespace Database\Factories;

use App\Models\AiArticle;
use App\Models\AiLearningPath;
use App\Models\AiLearningPathItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiLearningPathItem>
 */
class AiLearningPathItemFactory extends Factory
{
    protected $model = AiLearningPathItem::class;

    /**
     * Define the model's default state.
     *
     * A step normally carries no title of its own: `displayTitle()` falls back
     * to the article's, which is the behaviour a test needs to be able to reach.
     * The unique index on (path, article) means two items in one path cannot
     * point at the same article, so `for()` is the way to build a sequence.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_learning_path_id' => AiLearningPath::factory(),
            'ai_article_id' => AiArticle::factory(),
            'title' => null,
            'note' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Place this step in a given path, after an optional number of steps.
     *
     * Named `inside()` rather than `for()`: the base factory already owns
     * `for($parent)`, and overriding it would silently break the
     * `Item::factory()->for($path)` form every other factory supports.
     */
    public function inside(AiLearningPath $path, int $position = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_learning_path_id' => $path->id,
            'sort_order' => $position,
        ]);
    }

    /**
     * Give the step its own title, so it no longer inherits the article's.
     */
    public function titled(string $title): static
    {
        return $this->state(fn (array $attributes) => ['title' => $title]);
    }
}
