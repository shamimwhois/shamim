<?php

namespace App\Actions\AiHub;

use App\Models\AiLearningPathItem;

/**
 * Reorder two lessons inside one learning path.
 *
 * The sequence is the product: a path that says "start here" and then swaps two
 * lessons mid-publish has broken its own promise. Positions therefore move by
 * pairwise exchange within a single path, never by an absolute number typed into
 * a field, so two lessons can never end up claiming the same step.
 */
class ReorderAiLearningPathItem
{
    use SwapsSortOrder;

    /**
     * Exchange the position of the lesson with the one beside it.
     */
    public function handle(AiLearningPathItem $item, AiLearningPathItem $sibling): AiLearningPathItem
    {
        /** @var AiLearningPathItem */
        return $this->swap($item, $sibling, 'ai_learning_path_id');
    }

    /**
     * Get the lesson that sits immediately before or after in the sequence.
     */
    public function neighbour(AiLearningPathItem $item, string $direction): ?AiLearningPathItem
    {
        /** @var AiLearningPathItem|null */
        return $this->neighbourInGroup($item, 'ai_learning_path_id', $direction);
    }
}
