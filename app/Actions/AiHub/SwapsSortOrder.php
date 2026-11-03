<?php

namespace App\Actions\AiHub;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pairwise ordinal swap shared by the hub's ordered lists.
 *
 * Two rows can always exchange their sort values without disturbing the rest of
 * the group, which is not true of a naive "set this one to N" write. Both sides
 * are saved inside one transaction so an interrupted reorder cannot leave two
 * rows claiming the same position.
 */
trait SwapsSortOrder
{
    /**
     * Exchange the position of two rows that belong to the same group.
     *
     * @param  Model  $other  The row to swap with.
     */
    protected function swap(Model $subject, Model $other, string $groupColumn): Model
    {
        if ($subject->is($other)) {
            return $subject;
        }

        if ($subject->getAttribute($groupColumn) !== $other->getAttribute($groupColumn)) {
            throw new InvalidArgumentException('Only items in the same group can be swapped.');
        }

        DB::transaction(function () use ($subject, $other): void {
            $position = $subject->getAttribute('sort_order');

            $subject->setAttribute('sort_order', $other->getAttribute('sort_order'));
            $other->setAttribute('sort_order', $position);

            $subject->save();
            $other->save();
        });

        return $subject->refresh();
    }

    /**
     * Get the row immediately before or after the subject inside its own group.
     *
     * Callers use this to find what to swap with: the console renders one button
     * per direction and the server decides whether a neighbour actually exists,
     * so a reordered list can never be pushed past either end.
     */
    protected function neighbourInGroup(Model $subject, string $groupColumn, string $direction): ?Model
    {
        $group = $subject->getAttribute($groupColumn);

        return $subject->newQuery()
            ->where(fn (Builder $query) => $group === null
                ? $query->whereNull($groupColumn)
                : $query->where($groupColumn, $group))
            ->whereKeyNot($subject->getKey())
            ->when(
                $direction === 'up',
                fn (Builder $query) => $query->where('sort_order', '<', $subject->getAttribute('sort_order'))->orderByDesc('sort_order'),
                fn (Builder $query) => $query->where('sort_order', '>', $subject->getAttribute('sort_order'))->orderBy('sort_order')
            )
            ->first();
    }
}
