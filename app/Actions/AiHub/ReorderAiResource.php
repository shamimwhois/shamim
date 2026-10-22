<?php

namespace App\Actions\AiHub;

use App\Models\AiResource;

/**
 * Reorder two resources on the same shelf.
 */
class ReorderAiResource
{
    use SwapsSortOrder;

    /**
     * Exchange the position of the resource with its neighbour.
     */
    public function handle(AiResource $resource, AiResource $sibling): AiResource
    {
        /** @var AiResource */
        return $this->swap($resource, $sibling, 'ai_topic_id');
    }

    /**
     * Get the resource that sits immediately before or after on the shelf.
     */
    public function neighbour(AiResource $resource, string $direction): ?AiResource
    {
        /** @var AiResource|null */
        return $this->neighbourInGroup($resource, 'ai_topic_id', $direction);
    }
}
