<?php

namespace App\Models;

use Database\Factories\LabWorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One saved set of HTML, CSS and JavaScript in the Developer Lab editor.
 *
 * A workspace is the scratch state of the sandbox: what was in the three panes
 * when somebody pressed Save. It is deliberately not addressable by a human word,
 * which is why this is the only model in the module that keeps the numeric route
 * key. A workspace is never public ({@see LabSnippet} is the public object), so a
 * slug would be a name nobody could link to, with a UNIQUE index and a reserved
 * word list to maintain for it.
 *
 * The three panes are plain text. Nothing in the application parses them, and the
 * frame that renders them is built by the browser from an opaque origin, so the
 * columns are a storage concern rather than a security one. The security boundary
 * is the `sandbox` attribute on the frame, described in `docs/lab.md`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $html
 * @property string|null $css
 * @property string|null $js
 * @property Carbon|null $last_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'name', 'html', 'css', 'js'])]
class LabWorkspace extends Model
{
    /**
     * @var int The longest name the form will accept. A workspace name is a shelf
     *          label in a dropdown, not a title, so it is short on purpose.
     */
    public const NAME_MAX = 120;

    /** @use HasFactory<LabWorkspaceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * Get the account that saved this workspace.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to the workspaces one account saved.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function authoredBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Scope to the most recently touched workspaces.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function recentlyTouched(Builder $query): void
    {
        $query->orderByDesc('updated_at')->orderByDesc('id');
    }

    /**
     * Determine whether the given account may read this workspace.
     *
     * There is no published state and no admin exemption here. A workspace is a
     * draft of a page nobody has approved for viewing, and the console that reads
     * across accounts lists snippets and runs, not other people's scratch buffers.
     */
    public function isVisibleTo(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    /**
     * Determine whether the given account may write to this workspace.
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }
}
