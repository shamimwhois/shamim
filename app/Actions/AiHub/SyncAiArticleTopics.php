<?php

namespace App\Actions\AiHub;

use App\Models\AiArticle;
use App\Models\AiTopic;

/**
 * File an article under the operator's chosen topics, in the chosen order.
 *
 * Order matters here and is not cosmetic: the first row is the article's primary
 * topic, which is what the breadcrumb and the card badge name. A plain `sync()`
 * would lose that, so the pivot is written with explicit sort values.
 */
class SyncAiArticleTopics
{
    /**
     * Replace the article's topic set.
     *
     * @param  array<int, int|string>  $topicIds  Topic ids, primary first.
     */
    public function handle(AiArticle $article, array $topicIds): void
    {
        $ids = collect($topicIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $valid = AiTopic::query()->whereIn('id', $ids)->pluck('id')->all();

        $rows = [];
        $order = 0;

        foreach ($ids as $id) {
            if (! in_array($id, $valid, true)) {
                continue;
            }

            $rows[$id] = ['sort_order' => $order++];
        }

        $article->topics()->sync($rows);
        $article->unsetRelation('topics');
    }
}
