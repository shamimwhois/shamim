<?php

namespace App\Enums;

use App\Models\IdeaTask;

/**
 * Where a single task on a plan has got to.
 *
 * Four states, and deliberately no workflow gate between them. A task is the
 * smallest thing an account writes down, and the point of a checklist is that a
 * tick can be undone: an account that starts something, gets stuck, un-starts it,
 * and finishes it in one afternoon should not be told by the database that it
 * skipped a stage. The transitions an idea and a plan both declare are for objects
 * whose state change means something to somebody else; here the state is a note to
 * self, so it moves freely.
 */
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Done = 'done';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Todo => __('To do'),
            self::InProgress => __('In Progress'),
            self::Blocked => __('Blocked'),
            self::Done => __('Done'),
        };
    }

    /**
     * Get the Flux badge colour token for the status.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Todo => 'zinc',
            self::InProgress => 'teal',
            self::Blocked => 'red',
            self::Done => 'green',
        };
    }

    /**
     * Determine whether the task is finished.
     *
     * The one fact the plan screen derives rather than asks for: a completion is
     * recorded with a timestamp ({@see IdeaTask::$completed_at}) only
     * when this says so, which is what makes a progress ratio mean something.
     */
    public function isDone(): bool
    {
        return $this === self::Done;
    }

    /**
     * Get every status as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()])
            ->values()
            ->toArray();
    }
}
