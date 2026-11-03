<?php

namespace App\Models;

use Database\Factories\AiLearningPathItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One lesson slot in a learning path.
 *
 * The row is an entity rather than a bare pivot because a path is allowed to
 * re-label a lesson: `title` overrides the article's own title for readers who
 * meet it inside this sequence, and `note` explains why it comes here. The
 * article itself is never edited to fit a path.
 *
 * @property int $id
 * @property int $ai_learning_path_id
 * @property int $ai_article_id
 * @property string|null $title
 * @property string|null $note
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiLearningPath $path
 * @property-read AiArticle $article
 */
#[Fillable(['ai_learning_path_id', 'ai_article_id', 'title', 'note', 'sort_order'])]
class AiLearningPathItem extends Model
{
    /** @use HasFactory<AiLearningPathItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the path the lesson belongs to.
     *
     * @return BelongsTo<AiLearningPath, $this>
     */
    public function path(): BelongsTo
    {
        return $this->belongsTo(AiLearningPath::class, 'ai_learning_path_id');
    }

    /**
     * Get the article behind the lesson.
     *
     * @return BelongsTo<AiArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(AiArticle::class, 'ai_article_id');
    }

    /**
     * Get the title to display: the path's override, else the article's own.
     */
    public function displayTitle(): string
    {
        return $this->title ?: $this->article->title;
    }
}
