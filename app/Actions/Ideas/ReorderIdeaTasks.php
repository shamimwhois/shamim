<?php

namespace App\Actions\Ideas;

use App\Actions\AiHub\SwapsSortOrder;
use App\Models\IdeaTask;
use InvalidArgumentException;

/**
 * Move one task up or down inside its own plan.
 *
 * The reorder is a pairwise swap rather than "set this row to position N", which is
 * the strategy the hub's ordered lists already use: two neighbours can always
 * exchange places without disturbing anything else in the group, so a stale position
 * sent by a second tab cannot corrupt the list.
 *
 * The group is the plan. A task may not be pushed past either end of its own
 * checklist, and may certainly not be reordered into somebody else's, so the
 * neighbour lookup is scoped to `plan_id` and the caller gets back the same row it
 * passed in when there is nothing to swap.
 */
class ReorderIdeaTasks
{
    use SwapsSortOrder;

    /**
     * Swap the task with its neighbour in the given direction.
     *
     * @return IdeaTask The task, carrying whatever position it ended up in.
     */
    public function handle(IdeaTask $task, string $direction): IdeaTask
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException(__('A task moves up or down.'));
        }

        $neighbour = $this->neighbourInGroup($task, 'plan_id', $direction);

        if ($neighbour instanceof IdeaTask) {
            /** @var IdeaTask */
            return $this->swap($task, $neighbour, 'plan_id');
        }

        return $task;
    }
}
