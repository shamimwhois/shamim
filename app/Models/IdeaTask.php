<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\WorkPriority;
use Database\Factories\IdeaTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line on a plan's checklist.
 *
 * The smallest object in the module, and deliberately the least governed: no
 * workflow gate, no slug, no visibility, no owner of its own. A task belongs to a
 * plan ({@see IdeaTask::$plan}) and inherits its account from it, which is why
 * there is no `user_id` column here. Two rows that could disagree about who owns
 * them is one row too many, and every screen that writes a task re-asserts the
 * parent plan's author before it lets the write happen.
 *
 * `completed_at` is a timestamp rather than a boolean because the plan screen shows
 * when work actually finished, and {@see TaskStatus::isDone()} is the only thing
 * that decides whether it is set.
 *
 * @property int $id
 * @property int $plan_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property WorkPriority|null $priority
 * @property Carbon|null $due_date
 * @property int $sort_order
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IdeaPlan $plan
 */
#[Fillable([
    'plan_id',
    'title',
    'description',
    'status',
    'priority',
    'due_date',
    'sort_order',
])]
class IdeaTask extends Model
{
    /**
     * @var int The longest task line the board will accept. A task is one sentence;
     *          anything longer is a plan, and the account should write one.
     */
    public const TITLE_MAX = 200;

    /**
     * @var int How many tasks one plan may hold. A checklist past this stops being
     *          a checklist and becomes an import.
     */
    public const MAX_PER_PLAN = 200;

    /** @use HasFactory<IdeaTaskFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => WorkPriority::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the plan this task belongs to.
     *
     * @return BelongsTo<IdeaPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(IdeaPlan::class, 'plan_id');
    }

    /**
     * Scope to tasks that are not finished.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', '!=', TaskStatus::Done);
    }

    /**
     * Scope to tasks in the given status.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inStatus(Builder $query, TaskStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope to tasks whose due date has passed without being finished.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->whereNotNull('due_date')
            ->whereDate('due_date', '<', Carbon::today())
            ->open();
    }

    /**
     * Determine whether the task is finished.
     */
    public function isDone(): bool
    {
        return $this->status->isDone();
    }

    /**
     * Determine whether the task is late.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! $this->isDone()
            && $this->due_date->isBefore(Carbon::today());
    }
}
