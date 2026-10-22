<?php

namespace App\Actions\AiHub;

use App\Models\AiArticle;

/**
 * Curate the "read next" list that sits under an article.
 *
 * Related articles are chosen by hand rather than computed from shared topics,
 * because the useful pairing in a knowledge base is the one that answers the
 * question the reader is about to ask, which a topic overlap cannot know.
 */
class SyncAiRelatedArticles
{
    /**
     * Replace the article's related set.
     *
     * @param  array<int, int|string>  $relatedIds  Article ids, in display order.
     */
    public function handle(AiArticle $article, array $relatedIds): void
    {
        $ids = collect($relatedIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === $article->id || $id <= 0)
            ->unique()
            ->values();

        $valid = AiArticle::query()->whereIn('id', $ids)->pluck('id')->all();

        $rows = [];
        $order = 0;

        foreach ($ids as $id) {
            if (! in_array($id, $valid, true)) {
                continue;
            }

            $rows[$id] = ['sort_order' => $order++];
        }

        $article->relatedArticles()->sync($rows);
        $article->unsetRelation('relatedArticles');
    }
}
