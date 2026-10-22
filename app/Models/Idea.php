<?php

namespace App\Models;

use App\Actions\Ideas\ConvertIdeaToPlan;
use App\Concerns\HasSlug;
use App\Enums\AccountPermission;
use App\Enums\ContentVisibility;
use App\Enums\IdeaPotential;
use App\Enums\IdeaStatus;
use App\Enums\WorkPriority;
use Database\Factories\IdeaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A thought someone wrote down before it became a plan.
 *
 * The idea is Module B of `docs/Idea.md`: deliberately lightweight, deliberately
 * unfinished. A capture must not demand a problem statement, a scope or a
 * technology stack, because the point of the module is to catch the thought while
 * it is still cheap to lose. Everything richer than `title` and `summary` is
 * nullable, and the workspace's job is to help an account move a row from
 * `Inbox` toward `Approved` at its own pace.
 *
 * Two columns do the structural work, and neither is a text field:
 * `status` is the thinking stage ({@see IdeaStatus}), and `visibility` is the
 * publish decision ({@see ContentVisibility}). Publication is the conjunction of
 * `visibility` being public and `published_at` being set, which is how
 * `AiArticle` and `MarketplaceProduct` already define it, so an idea can never be
 * readable by a guest before the moment it was meant to go live.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $idea_category_id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property string|null $content
 * @property IdeaStatus $status
 * @property WorkPriority|null $priority
 * @property IdeaPotential|null $potential
 * @property ContentVisibility $visibility
 * @property bool $is_pinned
 * @property int $view_count
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read IdeaCategory|null $category
 * @property-read IdeaPlan|null $plan
 * @property-read Collection<int, Topic> $topics
 */
#[Fillable([
    'user_id',
    'idea_category_id',
    'title',
    'slug',
    'summary',
    'content',
    'status',
    'priority',
    'potential',
    'visibility',
    'is_pinned',
    'published_at',
])]
class Idea extends Model
{
    /**
     * @var int The longest title the form will accept. The column is wider on
     *          purpose: a rejected capture is a lost idea, so the limit is what
     *          a card can display in two lines, not what the database can store.
     */
    public const TITLE_MAX = 160;

    /**
     * @var int The length of the teaser shown on cards and in meta tags.
     */
    public const SUMMARY_MAX = 500;

    /**
     * @var int The ceiling on the Markdown body, in kilobytes. `longText` could
     *          hold more; a workspace screen cannot read more.
     */
    public const CONTENT_MAX_KILOBYTES = 64;

    /**
     * @var int How many categories one account may keep before the picker stops
     *          being a picker. Filing is meant to be a decision, not a chore.
     */
    public const CATEGORY_MAX_PER_ACCOUNT = 120;

    /** @use HasFactory<IdeaFactory> */
    use HasFactory, HasSlug;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdeaStatus::class,
            'priority' => WorkPriority::class,
            'potential' => IdeaPotential::class,
            'visibility' => ContentVisibility::class,
            'is_pinned' => 'boolean',
            'view_count' => 'integer',
            'published_at' => 'datetime',
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
     * Get the account that captured the idea.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category the idea is filed under, if any.
     *
     * @return BelongsTo<IdeaCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(IdeaCategory::class, 'idea_category_id');
    }

    /**
     * Get the public topics the author chose, in the order they chose them.
     *
     * These are not the category above. `category` is one account's private filing
     * and is never a public facet; `topics` is the platform's own list, which is why
     * a stranger may browse by it and may not learn anything about the folders
     * somebody else keeps.
     *
     * @return BelongsToMany<Topic, $this>
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'idea_topic')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * Get the plan this idea was converted into, when there is one.
     *
     * A relation and nothing more. Conversion is a decision an account takes through
     * {@see ConvertIdeaToPlan}, because `docs/Idea.md` asks that the
     * idea survives it: the row below is evidence of what the thought became, not a
     * copy of the thought.
     *
     * @return HasOne<IdeaPlan, $this>
     */
    public function plan(): HasOne
    {
        return $this->hasOne(IdeaPlan::class);
    }

    /**
     * Scope to ideas readable without an account.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('visibility', ContentVisibility::Public)
            ->whereNotNull('published_at');
    }

    /**
     * Scope to the ideas one account captured.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function authoredBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Scope to ideas in the given stage.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inStatus(Builder $query, IdeaStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope to ideas carrying the given public topic.
     *
     * The caller composes this with `published()`; the scope itself says nothing
     * about visibility, because the facet count and the facet's own list must reach
     * the same rows through the same words.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inTopic(Builder $query, string $topicSlug): void
    {
        $query->whereHas('topics', fn (Builder $query) => $query->where('slug', $topicSlug));
    }

    /**
     * Scope to ideas captured under the given author handle.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function byAuthor(Builder $query, string $handle): void
    {
        $query->whereHas('user', fn (Builder $query) => $query->where('handle', $handle));
    }

    /**
     * Scope to ideas that are not closed.
     *
     * The workspace shows live work by default. Archived is a decision the
     * account made, so it is kept out of the way rather than deleted.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNot('status', IdeaStatus::Archived);
    }

    /**
     * Scope to pinned ideas first, then newest.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function pinned(Builder $query): void
    {
        $query->orderByDesc('is_pinned')->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Scope to the most recently touched ideas.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function recentlyTouched(Builder $query): void
    {
        $query->orderByDesc('updated_at')->orderByDesc('id');
    }

    /**
     * Scope to ideas ranked by declared priority, unranked last.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function prioritised(Builder $query): void
    {
        $query->orderByRaw(WorkPriority::orderBySql())
            ->orderByDesc('created_at');
    }

    /**
     * Scope to ideas matching a free-text term.
     *
     * The three columns are searched with `LIKE`. There is no full-text index
     * behind this: the default connection is SQLite, so the scope is written for
     * what the application actually runs against.
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

        $pattern = '%'.$this->escapeLikeValue($term).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            $query->where('title', 'like', $pattern)
                ->orWhere('summary', 'like', $pattern)
                ->orWhere('content', 'like', $pattern);
        });
    }

    /**
     * Scope to ideas in the given visibility.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function withVisibility(Builder $query, ContentVisibility $visibility): void
    {
        $query->where('visibility', $visibility);
    }

    /**
     * Escape LIKE wildcards in a user-supplied value.
     */
    public function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Determine whether the idea is readable without an account.
     */
    public function isPublished(): bool
    {
        return $this->visibility === ContentVisibility::Public && $this->published_at !== null;
    }

    /**
     * Determine whether the given account may read this idea.
     *
     * A published idea is readable by anyone. Otherwise only its author and an
     * account holding the admin panel may see it, because the admin console lists
     * across accounts while no workspace does. The rule lives here so the public
     * page, the workspace and the console cannot disagree about it.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($this->isPublished()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $this->user_id === $user->id
            || $user->hasPermission(AccountPermission::AccessAdminPanel);
    }

    /**
     * Determine whether the given account may write to this idea.
     *
     * Ownership only. An admin reads across accounts but does not rewrite them,
     * so moderation is a delete decision rather than an edit.
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    /**
     * Get the one-line teaser used by cards and meta tags.
     *
     * Falls back to the opening of the content so a card never renders an empty
     * teaser, which is the usual failure of a hand-written summary column.
     */
    public function excerpt(int $limit = 200): string
    {
        if (filled($this->summary)) {
            return $this->summary;
        }

        return str(strip_tags((string) $this->content))->limit($limit)->value();
    }

    /**
     * Get the topic names, in the order the author chose them.
     *
     * Both the meta keywords and the card badge read this, so the words a search
     * engine is handed are the words the page shows.
     *
     * @return array<int, string>
     */
    public function topicNames(): array
    {
        return $this->topics->pluck('name')->all();
    }

    /**
     * Get the topic a card would name first, when the idea carries any.
     */
    public function primaryTopic(): ?Topic
    {
        return $this->topics->first();
    }
}
