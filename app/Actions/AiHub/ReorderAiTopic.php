<?php

namespace App\Actions\AiHub;

use App\Models\AiTopic;

/**
 * Reorder two sibling topics.
 */
class ReorderAiTopic
{
    use SwapsSortOrder;

    /**
     * Exchange the position of the topic with its sibling.
     */
    public function handle(AiTopic $topic, AiTopic $sibling): AiTopic
    {
        /** @var AiTopic */
        return $this->swap($topic, $sibling, 'parent_id');
    }

    /**
     * Get the sibling that sits immediately before or after the topic.
     */
    public function neighbour(AiTopic $topic, string $direction): ?AiTopic
    {
        /** @var AiTopic|null */
        return $this->neighbourInGroup($topic, 'parent_id', $direction);
    }
}
