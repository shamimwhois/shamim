<?php

namespace App\Actions\AiHub;

use App\Models\AiArticle;
use Illuminate\Support\Facades\DB;

/**
 * Remove a hub article for good.
 *
 * The hub holds no tombstones, so this is a real delete and the database does the
 * cleanup with it: topic filings, reference links, and "read next" rows go with
 * the article. The row that outlives the article author is a learning-path slot,
 * which also cascades — deleting a lesson silently shortens every path that
 * teaches it, which is why the console confirms against the lesson count rather
 * than the article title alone.
 */
class DeleteAiArticle
{
    /**
     * Delete the article and detach it from the hub.
     */
    public function handle(AiArticle $article): void
    {
        DB::transaction(function () use ($article): void {
            $article->topics()->detach();
            $article->relatedArticles()->detach();
            $article->links()->delete();
            $article->delete();
        });
    }

    /**
     * Count the learning paths that would lose a lesson.
     */
    public function pathCount(AiArticle $article): int
    {
        return $article->learningPathItems()->distinct('ai_learning_path_id')->count('ai_learning_path_id');
    }
}
