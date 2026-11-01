<?php

namespace App\Actions\AiHub;

use App\Models\AiTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Re-file a topic under a different parent.
 *
 * The taxonomy is expected to change as the field does, so re-parenting has to be
 * a single deliberate operation with its own guard rather than a side effect of
 * editing the name. The guard is what stops a topic being dropped into its own
 * subtree, which would render a navigation tree that never terminates.
 */
class MoveAiTopic
{
    /**
     * Place the topic under a new parent, at the end of that group.
     */
    public function handle(AiTopic $topic, ?int $parentId): AiTopic
    {
        if ($parentId === $topic->getKey()) {
            throw new InvalidArgumentException('A topic cannot be its own parent.');
        }

        if ($parentId !== null) {
            $target = AiTopic::query()->whereKey($parentId)->firstOrFail();

            $this->assertNotDescendantOf($topic, $target);

            $parentId = $target->id;
        }

        DB::transaction(function () use ($topic, $parentId): void {
            $next = (int) AiTopic::query()
                ->where(fn (Builder $query) => $parentId === null
                    ? $query->whereNull('parent_id')
                    : $query->where('parent_id', $parentId))
                ->whereKeyNot($topic->getKey())
                ->max('sort_order') + 1;

            $topic->parent_id = $parentId;
            $topic->sort_order = $next;
            $topic->save();
        });

        return $topic->refresh();
    }

    /**
     * Refuse to move a topic inside its own subtree.
     */
    protected function assertNotDescendantOf(AiTopic $topic, AiTopic $target): void
    {
        $cursor = $target;
        $guard = 0;

        while ($cursor !== null && $guard++ < 64) {
            if ($cursor->id === $topic->id) {
                throw new InvalidArgumentException('A topic cannot be moved underneath one of its own children.');
            }

            $cursor = $cursor->parent_id === null ? null : AiTopic::query()->whereKey($cursor->parent_id)->first();
        }
    }
}
