<?php

namespace App\Models;

use App\Concerns\HasSlug;
use App\Enums\AccountPermission;
use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use Database\Factories\LabSnippetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One saved piece of code in the Developer Lab, with a URL it may earn.
 *
 * The snippet is the module's publishable object, and the reason it exists
 * separately from {@see LabWorkspace}: a workspace is what is in the panes right
 * now, whereas a snippet is a thing somebody decided was worth naming, describing
 * and showing to other people. It carries the language, the visibility pair, and
 * the code itself as plain text.
 *
 * Publication is the conjunction of `visibility` being public and `published_at`
 * being set, defined once by {@see self::isPublished()} the way the Ideas, blog,
 * hub and marketplace suites already define it. Nothing in this module is allowed
 * to read the two columns independently, because a snippet readable by a guest
 * before the moment it was meant to go live is the failure that pair exists to
 * prevent.
 *
 * The `code` column is data. Every surface that shows it escapes it, and the only
 * place it becomes executable is the sandboxed frame the visitor explicitly asks
 * for, which runs it under an opaque origin rather than this site's.
 *
 * @property int $id
 * @property int $user_id
 * @property LabLanguage $language
 * @property string $title
 * @property string $slug
 * @property string $code
 * @property string|null $description
 * @property ContentVisibility $visibility
 * @property int $view_count
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Topic> $topics
 */
#[Fillable([
    'user_id',
    'language',
    'title',
    'slug',
    'code',
    'description',
    'visibility',
    'published_at',
])]
class LabSnippet extends Model
{
    /**
     * @var int The longest title the form will accept.
     */
    public const TITLE_MAX = 160;

    /**
     * @var int The length of the teaser shown on gallery cards and in meta tags.
     */
    public const DESCRIPTION_MAX = 500;

    /** @use HasFactory<LabSnippetFactory> */
    use HasFactory, HasSlug;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'language' => LabLanguage::class,
            'visibility' => ContentVisibility::class,
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
     * Get the account that saved the snippet.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the public topics the author chose, in the order they chose them.
     *
     * A snippet already has one subject nobody had to decide: its
     * {@see LabLanguage}. Topics are the other half, what the code is for rather
     * than what it is written in, and they are the second axis the gallery filters by.
     *
     * @return BelongsToMany<Topic, $this>
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'lab_snippet_topic')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * Scope to snippets readable without an account.
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
     * Scope to the snippets one account saved.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function authoredBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Scope to snippets written in the given language.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inLanguage(Builder $query, LabLanguage $language): void
    {
        $query->where('language', $language);
    }

    /**
     * Scope to snippets carrying the given public topic.
     *
     * The caller composes this with `published()`; the facet count and the facet's
     * own list must reach the same rows through the same words.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inTopic(Builder $query, string $topicSlug): void
    {
        $query->whereHas('topics', fn (Builder $query) => $query->where('slug', $topicSlug));
    }

    /**
     * Scope to snippets saved under the given author handle.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function byAuthor(Builder $query, string $handle): void
    {
        $query->whereHas('user', fn (Builder $query) => $query->where('handle', $handle));
    }

    /**
     * Scope to snippets in the given visibility.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function withVisibility(Builder $query, ContentVisibility $visibility): void
    {
        $query->where('visibility', $visibility);
    }

    /**
     * Scope to snippets matching a free-text term.
     *
     * The title, the description and the code are searched with `LIKE`. Searching
     * the code is the point: somebody looking for the debounce helper they saved
     * remembers the function name, not the sentence they wrote under it. There is
     * no full-text index behind this because the default connection is SQLite.
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
                ->orWhere('description', 'like', $pattern)
                ->orWhere('code', 'like', $pattern);
        });
    }

    /**
     * Scope to the most recently published snippets.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function recentlyPublished(Builder $query): void
    {
        $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * Scope to the most recently touched snippets.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function recentlyTouched(Builder $query): void
    {
        $query->orderByDesc('updated_at')->orderByDesc('id');
    }

    /**
     * Escape LIKE wildcards in a user-supplied value.
     */
    public function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Determine whether the snippet is readable without an account.
     */
    public function isPublished(): bool
    {
        return $this->visibility === ContentVisibility::Public && $this->published_at !== null;
    }

    /**
     * Determine whether the given account may read this snippet.
     *
     * A published snippet is readable by anyone. Otherwise only its author and an
     * account holding the admin panel may see it: the console lists across accounts
     * so an operator can take down work that should not be on the public site.
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
     * Determine whether the given account may write to this snippet.
     *
     * Ownership only. An operator moderates by removing, never by rewriting
     * somebody else's code.
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    /**
     * Get the teaser used by gallery cards and meta tags.
     *
     * A code teaser is built from the description when there is one and from the
     * opening of the code when there is not. Whitespace is collapsed first: a card
     * that renders somebody's indentation is a card with a wall of blank space in
     * it, and the reader is not going to learn anything from the indentation.
     */
    public function excerpt(int $limit = 200): string
    {
        if (filled($this->description)) {
            return $this->description;
        }

        $code = preg_replace('/\s+/u', ' ', trim($this->code)) ?? '';

        return str($code)->limit($limit)->value();
    }

    /**
     * Get the number of lines in the saved code.
     *
     * The gallery shows it because a two-line helper and a forty-line component are
     * different reads, and the count costs nothing to derive from a column already
     * loaded.
     */
    public function lineCount(): int
    {
        return substr_count($this->code, "\n") + 1;
    }

    /**
     * Get the topic names, in the order the author chose them.
     *
     * Both the meta keywords and the structured data read this, so the words a
     * search engine is handed are the words the page shows.
     *
     * @return array<int, string>
     */
    public function topicNames(): array
    {
        return $this->topics->pluck('name')->all();
    }

    /**
     * Get the topic a card would name first, when the snippet carries any.
     */
    public function primaryTopic(): ?Topic
    {
        return $this->topics->first();
    }
}
