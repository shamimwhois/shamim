<?php

namespace App\Models;

use App\Concerns\HasSlug;
use App\Enums\AccountPermission;
use App\Enums\PlanStatus;
use App\Enums\WorkPriority;
use Database\Factories\IdeaPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * An idea written down as something a person could actually start.
 *
 * The plan is the second half of Module B in `docs/Idea.md`: an idea says what
 * somebody thinks, a plan says what will be done about it, in what order, and by
 * when. Everything the spec asks a plan to hold is a nullable column, because a
 * plan is meant to be filled in gradually and a form that demands the whole
 * document on day one is how people stop writing plans.
 *
 * A plan is never public. Publication in this module is a decision about an idea or
 * a snippet, and `docs/Idea.md` puts the plan inside the account that made it, so
 * there is no `visibility` column here and no public route that reads one. What an
 * outsider may infer is only what the linked idea already says about itself.
 *
 * Ownership is a single column, and every read of a task below it resolves through
 * this row, so a task cannot belong to a different account than the plan that lists
 * it.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $idea_id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property string|null $problemStatement
 * @property string|null $proposedSolution
 * @property string|null $scope
 * @property string|null $outOfScope
 * @property string|null $goals
 * @property string|null $notes
 * @property PlanStatus $status
 * @property WorkPriority|null $priority
 * @property string|null $estimatedEffort
 * @property Carbon|null $targetStartDate
 * @property Carbon|null $targetEndDate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Idea|null $idea
 * @property-read Collection<int, IdeaTask> $tasks
 */
#[Fillable([
    'user_id',
    'idea_id',
    'title',
    'slug',
    'summary',
    'problem_statement',
    'proposed_solution',
    'scope',
    'out_of_scope',
    'goals',
    'notes',
    'status',
    'priority',
    'estimated_effort',
    'target_start_date',
    'target_end_date',
])]
class IdeaPlan extends Model
{
    /**
     * @var int The longest title the form will accept, matching an idea so a
     *          converted plan does not have to be shortened by hand.
     */
    public const TITLE_MAX = 160;

    /**
     * @var int The length of the teaser shown on the plan list.
     */
    public const SUMMARY_MAX = 500;

    /**
     * @var int The ceiling on the free-form notes, in kilobytes. A plan section is
     *          prose somebody has to re-read; past this it is a document, and the
     *          module has no viewer for one.
     */
    public const NOTES_MAX_KILOBYTES = 64;

    /**
     * @var int How long a free-text section may run. The sections are paragraphs a
     *          reviewer reads on one screen, not an appendix.
     */
    public const SECTION_MAX = 5000;

    /**
     * @var int The ceiling on the estimated-effort label, which is prose the author
     *          chooses ("a sprint", "two weeks") rather than a number the module can
     *          check.
     */
    public const EFFORT_MAX = 60;

    /** @use HasFactory<IdeaPlanFactory> */
    use HasFactory, HasSlug;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'priority' => WorkPriority::class,
            'target_start_date' => 'date',
            'target_end_date' => 'date',
        ];
    }

    /**
     * Get the attribute used for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the account that wrote the plan.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the idea this plan was converted from, when there was one.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * Get the tasks that break this plan down.
     *
     * @return HasMany<IdeaTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(IdeaTask::class, 'plan_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Scope to the plans one account wrote.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function authoredBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Scope to plans in the given status.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inStatus(Builder $query, PlanStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope to plans that still want attention.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNotIn('status', [PlanStatus::Completed->value, PlanStatus::Cancelled->value]);
    }

    /**
     * Scope to the most recently touched plans.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function recentlyTouched(Builder $query): void
    {
        $query->orderByDesc('updated_at')->orderByDesc('id');
    }

    /**
     * Scope to plans matching a free-text term.
     *
     * `LIKE` over the fields a plan is found by: the title, the one-line summary,
     * and the problem statement, which is where a plan's subject actually lives.
     * There is no full-text index behind this, because the default connection is
     * SQLite.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            $query->where('title', 'like', $pattern)
                ->orWhere('summary', 'like', $pattern)
                ->orWhere('problem_statement', 'like', $pattern);
        });
    }

    /**
     * Determine whether the given account may read this plan.
     *
     * Author, or an account that may open the admin panel: the console lists across
     * accounts so an operator can see how much planning the platform is doing, which
     * is the only reason a plan is readable by anybody but its author.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->user_id === $user->id
            || $user->hasPermission(AccountPermission::AccessAdminPanel);
    }

    /**
     * Determine whether the given account may write to this plan.
     *
     * Ownership only, exactly as with an idea. An operator may read and remove, not
     * rewrite.
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    /**
     * Get the one-line teaser used by the plan list.
     *
     * Falls back to the problem statement, which is the closest thing a plan has to
     * a summary when nobody typed one.
     */
    public function excerpt(int $limit = 200): string
    {
        if (filled($this->summary)) {
            return (string) $this->summary;
        }

        return str(strip_tags((string) ($this->problem_statement ?: $this->notes)))->limit($limit)->value();
    }
}
