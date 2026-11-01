<?php

namespace App\Actions\AiHub;

use App\Models\AiTopic;
use Illuminate\Support\Facades\DB;

/**
 * Remove a topic from the taxonomy.
 *
 * Deleting a topic must not delete what it filed. Articles keep their other
 * markings, a topic-less article is still an article; child topics are promoted
 * to the root rather than orphaned, because the database nulls their parent and a
 * null parent left un-promoted would hide them from the root-level listing.
 */
class DeleteAiTopic
{
    /**
     * Delete the topic, promoting its children and un-filing its articles.
     */
    public function handle(AiTopic $topic): void
    {
        DB::transaction(function () use ($topic): void {
            AiTopic::query()
                ->where('parent_id', $topic->getKey())
                ->update(['parent_id' => $topic->parent_id]);

            $topic->articles()->detach();
            $topic->delete();
        });
    }

    /**
     * Count the articles that will lose this filing.
     */
    public function articleCount(AiTopic $topic): int
    {
        return $topic->articles()->count();
    }

    /**
     * Count the topics that will be promoted to the vacated level.
     */
    public function childCount(AiTopic $topic): int
    {
        return $topic->children()->count();
    }
}
