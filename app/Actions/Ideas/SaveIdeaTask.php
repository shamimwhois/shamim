<?php

namespace App\Actions\Ideas;

use App\Enums\TaskStatus;
use App\Enums\WorkPriority;
use App\Models\IdeaPlan;
use App\Models\IdeaTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Add or edit one task on a plan.
 *
 * The plan is the argument rather than the task's id, because ownership of a task
 * derives from the plan that lists it: nothing here can attach a task to a plan the
 * caller has no claim on, and there is no `user_id` on the row that could drift away
 * from its parent.
 *
 * `completed_at` is written here and nowhere else. The status says whether the work
 * is finished; the timestamp says when, and keeping the two in step is what lets the
 * plan screen show a real date instead of a dash next to every tick.
 */
class SaveIdeaTask
{
    /**
     * Create a task on the given plan.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?IdeaTask $task = null, ?IdeaPlan $plan = null): IdeaTask
    {
        return DB::transaction(function () use ($data, $task, $plan): IdeaTask {
            $task ??= new IdeaTask;

            if ($plan !== null) {
                if ($task->exists && $task->plan_id !== $plan->id) {
                    throw new InvalidArgumentException(__('A task cannot be moved to another plan.'));
                }

                $task->plan_id = $plan->id;
            }

            if (! $task->plan_id) {
                throw new InvalidArgumentException(__('A task needs a plan before it can be saved.'));
            }

            /** @var IdeaPlan $owningPlan */
            $owningPlan = IdeaPlan::query()->findOrFail($task->plan_id);

            $title = trim((string) ($data['title'] ?? $task->title ?? ''));

            if ($title === '') {
                throw new InvalidArgumentException(__('A task needs something to do before it can be saved.'));
            }

            if (mb_strlen($title) > IdeaTask::TITLE_MAX) {
                throw new InvalidArgumentException(__('A task has to stay under :count characters.', [
                    'count' => IdeaTask::TITLE_MAX,
                ]));
            }

            if (! $task->exists && $owningPlan->tasks()->count() >= IdeaTask::MAX_PER_PLAN) {
                throw new InvalidArgumentException(__(
                    'This plan already has :count tasks. Split the plan rather than the list.',
                    ['count' => IdeaTask::MAX_PER_PLAN]
                ));
            }

            $status = $this->resolveStatus($data['status'] ?? $task->status) ?? TaskStatus::Todo;

            $task->fill([
                'title' => $title,
                'description' => $this->nullableText($data, 'description', $task->description),
                'priority' => $this->resolvePriority($data['priority'] ?? $task->priority),
                'due_date' => $this->resolveDate($data, 'due_date', $task->due_date),
            ]);

            if (! $task->exists) {
                $task->forceFill(['sort_order' => $this->nextSortOrder($owningPlan)]);
            }

            $task->forceFill(['status' => $status]);

            $task->completed_at = $status->isDone()
                ? ($task->completed_at ?? Carbon::now())
                : null;

            $task->save();

            return $task->refresh();
        });
    }

    /**
     * Get the position a new task lands in: last, in the order the account added them.
     */
    protected function nextSortOrder(IdeaPlan $plan): int
    {
        $highest = $plan->tasks()->max('sort_order');

        return $highest === null ? 0 : (int) $highest + 1;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function nullableText(array $data, string $key, ?string $current): ?string
    {
        if (! array_key_exists($key, $data)) {
            return $current;
        }

        $value = $data[$key];

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveDate(array $data, string $key, mixed $current): ?string
    {
        if (! array_key_exists($key, $data)) {
            return $current === null ? null : (string) $current;
        }

        $value = $data[$key];

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    protected function resolveStatus(mixed $value): ?TaskStatus
    {
        if ($value instanceof TaskStatus) {
            return $value;
        }

        return $value === null || $value === '' ? null : TaskStatus::tryFrom((string) $value);
    }

    protected function resolvePriority(mixed $value): ?WorkPriority
    {
        if ($value instanceof WorkPriority) {
            return $value;
        }

        return $value === null || $value === '' ? null : WorkPriority::tryFrom((string) $value);
    }
}
