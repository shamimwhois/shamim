<?php

namespace App\Actions\Ideas;

use App\Models\Idea;
use App\Models\Topic;

/**
 * File an idea under the public topics its author chose, in the chosen order.
 *
 * Order is not cosmetic: the first pivot row is the primary topic, and it is what a
 * card badge and the structured-data keywords name when an idea carries more than
 * one, so the write goes through {@see Topic::syncRows()} rather than a plain
 * `sync()` of an unordered set.
 *
 * This is the layer that decides *which* topics become public for this idea. The
 * private folder in `idea_category_id` is untouched by it and stays private; a
 * reader browsing `/ideas?topic=…` is moving through the platform's list, never
 * through somebody else's filing cabinet.
 */
class SyncIdeaTopics
{
    /**
     * Replace the idea's topic set.
     *
     * @param  array<int, int|string>  $topicIds  Topic ids, primary first.
     */
    public function handle(Idea $idea, array $topicIds): void
    {
        $idea->topics()->sync(Topic::syncRows($topicIds));
        $idea->unsetRelation('topics');
    }
}
