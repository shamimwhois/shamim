<?php

namespace App\Models;

use App\Enums\AccountPermission;
use App\Enums\LabLanguage;
use Database\Factories\LabExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One press of the Run button, recorded.
 *
 * The table answers the two questions `docs/lab.md` actually asks of an execution
 * log: what have I run, and how much may I still run today. It is a row per event
 * and nothing else, which is why there is no output column. The console pane is
 * the browser's, the frame is torn down on the next keystroke-free Run, and
 * storing a copy of somebody's `console.log` would turn a history into an archive
 * of things nobody reads twice.
 *
 * The label is denormalised. A run against a workspace called "Nav experiment"
 * keeps saying so after that workspace is renamed or deleted, which is the
 * difference between a log and a broken link.
 *
 * @property int $id
 * @property int $user_id
 * @property LabLanguage $language
 * @property string|null $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'language', 'label'])]
class LabExecution extends Model
{
    /**
     * @var int The longest label the writer will keep. The source column is wider
     *          on purpose than the strings it copies, so that a name truncated in
     *          the log is the rare case rather than the default.
     */
    public const LABEL_MAX = 160;

    /** @use HasFactory<LabExecutionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'language' => LabLanguage::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the account that pressed Run.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to the runs one account performed.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function authoredBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Scope to the runs performed on a given day.
     *
     * The daily allowance is counted through this scope rather than compared
     * against a timestamp written in the screen, so the rule the button enforces
     * and the number the history shows are the same query.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function onDay(Builder $query, Carbon $day): void
    {
        $query->whereBetween('created_at', [
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        ]);
    }

    /**
     * Scope to the runs performed in the given language.
     *
     * The console filters by it because PHP rows are the interesting ones: nothing
     * in this module executes PHP, so a stored language appearing in the log is a
     * request that got in through something other than the button.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inLanguage(Builder $query, LabLanguage $language): void
    {
        $query->where('language', $language);
    }

    /**
     * Scope to the newest runs first.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Determine whether the given account may read this run.
     *
     * A run is a fact about one account's own session. The operator console lists
     * them platform-wide to see how the module is used, which is the only reason
     * the admin exemption exists here.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->id === $this->user_id
            || $user->hasPermission(AccountPermission::AccessAdminPanel);
    }
}
