<?php

namespace App\Services;

use App\Models\Idea;
use App\Models\LabSnippet;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Decides which topics the public may browse by, and how many rows are behind each.
 *
 * One place owns the gate because three screens have to agree about it: the ideas
 * landing, the gallery, and `/topics`. The rule is the one the hub already states
 * about its own shelves, that a facet is counted through the `published()` scope on
 * the pivot relation so a chip can never advertise a row the page will not open.
 *
 * The same gate decides the other facet a stranger can navigate by: who wrote it.
 * Authorship is a property of the row like a subject is, and a name in a filter
 * list is offered only when that name has something published behind it, so
 * neither facet can be clicked into an empty page.
 *
 * Two consequences follow from writing it once instead of twice.
 *
 * First, an unpublished idea is invisible here in every sense. Its private
 * `idea_categories` folder is not read by this class at all, and a topic carried
 * only by drafts is not merely uncounted but absent, which is what makes an
 * author's work-in-progress unannouncable by accident.
 *
 * Second, `is_active` is necessary and not sufficient. A topic an operator switched
 * off is gone; a topic somebody switched on and never published under is also gone,
 * because a chip that opens an empty list is a dead end wearing navigation.
 *
 * Memoised per request, the way {@see AiHubContent} is: the landing asks for the
 * chips, the count line and the active topic's name within one render, and those
 * must be the same numbers in the same screen.
 */
class PublicTaxonomy
{
    /**
     * @var string The family whose published ideas gate and count a chip.
     */
    public const IDEAS = 'ideas';

    /**
     * @var string The family whose published snippets gate and count a chip.
     */
    public const SNIPPETS = 'snippets';

    /**
     * The chips for one family, biggest first, zero-count topics removed.
     *
     * @var array<string, SupportCollection<int, Topic>>
     */
    protected array $chips = [];

    /**
     * Every topic with a public row behind it, carrying both counts.
     *
     * @var SupportCollection<int, Topic>|null
     */
    protected ?SupportCollection $indexed = null;

    /**
     * The topics an author may pick from, held for the request.
     *
     * @var SupportCollection<int, Topic>|null
     */
    protected ?SupportCollection $picker = null;

    /**
     * The authorship facet for each family, biggest first, held for the request.
     *
     * @var array<string, SupportCollection<int, array{handle: string, name: string, count: int}>>
     */
    protected array $authors = [];

    /**
     * Get the browseable topics for one family.
     *
     * @return SupportCollection<int, Topic>
     */
    public function topicChips(string $family): SupportCollection
    {
        $column = $this->countColumn($family);

        // The zero-count topics are dropped in PHP, not with a HAVING clause:
        // `withCount()` writes a correlated subquery rather than a GROUP BY, and
        // SQLite rejects a HAVING against a query that never grouped. Ordering
        // stays in SQL, where the alias is legal in ORDER BY.
        return $this->chips[$family] ??= $this->query($family)
            ->withCount($this->countable($family))
            ->orderByDesc($column)
            ->ordered()
            ->get()
            ->filter(fn (Topic $topic): bool => (int) $topic->{$column} > 0)
            ->values();
    }

    /**
     * Get the selected topic for one family, or null when it cannot be opened.
     *
     * A hand-typed `?topic=` reaches this, so the check is the same one the chips
     * were built with rather than a lookup on the slug alone: an inactive topic, or
     * one whose rows are all unpublished, is not a filter a stranger may hold.
     */
    public function topic(string $family, string $slug): ?Topic
    {
        return $this->topicChips($family)->firstWhere('slug', $slug);
    }

    /**
     * Get the published rows a slug has behind it in one family.
     *
     * The count a landing page writes next to "N ideas" comes from here rather than
     * from counting a collection it is about to render, so the number and the list
     * are the same query and cannot disagree within one screen.
     */
    public function publishedCount(string $family, string $slug): int
    {
        $topic = $this->query($family)
            ->where('slug', $slug)
            ->withCount($this->countable($family))
            ->first();

        return $topic === null ? 0 : (int) $topic->{$this->countColumn($family)};
    }

    /**
     * Get the authors with published rows in one family, biggest first.
     *
     * A name is offered because it has something a stranger can open, never because
     * the account happens to exist. Accounts without a handle are skipped: this facet
     * travels in a URL as `?author=`, and an account with no handle has nothing
     * honest to put there.
     *
     * @return SupportCollection<int, array{handle: string, name: string, count: int}>
     */
    public function authorChips(string $family, int $limit = 12): SupportCollection
    {
        if (isset($this->authors[$family])) {
            return $this->authors[$family];
        }

        $counts = $this->authorCounts($family, $limit);

        return $this->authors[$family] = User::query()
            ->whereKey($counts->keys()->all())
            ->get()
            ->filter(fn (User $user): bool => filled($user->handle))
            ->map(fn (User $user): array => [
                'handle' => (string) $user->handle,
                'name' => $user->name,
                'count' => $counts->get($user->id, 0),
            ])
            ->sortByDesc('count')
            ->values();
    }

    /**
     * Count published rows per author, largest count first.
     *
     * Grouped in SQL and capped, because this list is a filter a reader picks from
     * and not a directory of accounts.
     *
     * @return SupportCollection<int, int>
     */
    protected function authorCounts(string $family, int $limit): SupportCollection
    {
        $column = 'author_rows';

        /** @var Builder<Idea|LabSnippet> $query */
        $query = $family === self::SNIPPETS
            ? LabSnippet::query()->published()
            : Idea::query()->published();

        // Read as `pluck($alias, 'user_id')` rather than as model attributes: the
        // aggregate lives in the select list, not on the table, and a model would be
        // asked for a property that no schema defines.
        return $query
            ->select('user_id')
            ->selectRaw('count(*) as '.$column)
            ->groupBy('user_id')
            ->orderByDesc($column)
            ->limit($limit)
            ->pluck($column, 'user_id')
            ->mapWithKeys(fn (mixed $rows, mixed $userId): array => [
                (int) $userId => (int) $rows,
            ]);
    }

    /**
     * Get the topics an author may file under, including a retired one they hold.
     *
     * The picker cannot show only what the public may browse, or an author carrying
     * a topic an operator has since switched off would see no box for it and could
     * never un-check it: the pivot would outlive the interface. What the picker
     * offers is therefore "switched on, or already attached to something".
     *
     * @return SupportCollection<int, Topic>
     */
    public function pickerTopics(): SupportCollection
    {
        // Expressed as a query rather than as counts read back off the models: the
        // exists subquery is the same rule, and it keeps the decision in the database
        // where the facet row's own count already lives.
        return $this->picker ??= Topic::query()
            ->where(function (Builder $query): void {
                $query->where('is_active', true)
                    ->orWhereHas('ideas')
                    ->orWhereHas('labSnippets');
            })
            ->ordered()
            ->get();
    }

    /**
     * Get one topic by slug for the editor, whether or not the public may reach it.
     */
    public function topicForEditor(string $slug): ?Topic
    {
        return $this->pickerTopics()->firstWhere('slug', $slug);
    }

    /**
     * Get every topic the public index may list, with both counts attached.
     *
     * @return SupportCollection<int, Topic>
     */
    public function indexed(): SupportCollection
    {
        if ($this->indexed !== null) {
            return $this->indexed;
        }

        $topics = Topic::query()
            ->active()
            ->withCount([
                'ideas' => $this->publishedIdeaQuery(),
                'labSnippets' => $this->publishedSnippetQuery(),
            ])
            ->get()
            ->filter(fn (Topic $topic): bool => $topic->ideas_count > 0 || $topic->lab_snippets_count > 0)
            ->sortByDesc(fn (Topic $topic): int => $topic->ideas_count + $topic->lab_snippets_count)
            ->values();

        return $this->indexed = $topics;
    }

    /**
     * Determine whether a topic page may be opened at all.
     *
     * Route binding hands over any row, including one an operator switched off and
     * one whose every idea is a draft. Both are a 404 rather than an empty page.
     */
    public function isBrowseable(Topic $topic): bool
    {
        return $this->indexed()->contains($topic);
    }

    /**
     * Start a query over the topics that are switched on.
     *
     * @return Builder<Topic>
     */
    protected function query(string $family): Builder
    {
        /** @var Builder<Topic> $query */
        $query = Topic::query()->active();

        return $query;
    }

    /**
     * The relation counted for one family.
     *
     * @return array<string, callable(Builder<*>): void>
     */
    protected function countable(string $family): array
    {
        return match ($family) {
            self::SNIPPETS => ['labSnippets' => $this->publishedSnippetQuery()],
            default => ['ideas' => $this->publishedIdeaQuery()],
        };
    }

    /**
     * The alias `withCount()` writes the number into for one family.
     */
    protected function countColumn(string $family): string
    {
        return match ($family) {
            self::SNIPPETS => 'lab_snippets_count',
            default => 'ideas_count',
        };
    }

    /**
     * Count only the ideas a stranger can open.
     *
     * @return callable(Builder<*>): void
     */
    protected function publishedIdeaQuery(): callable
    {
        // `withCount()` closures receive the Eloquent builder, not the relation
        // object Laravel uses for eager loads, so the type must be the query that
        // actually runs the count.
        return function (Builder $query): void {
            /** @var Builder<Idea> $query */
            $query->published();
        };
    }

    /**
     * Count only the snippets a stranger can open.
     *
     * @return callable(Builder<*>): void
     */
    protected function publishedSnippetQuery(): callable
    {
        return function (Builder $query): void {
            /** @var Builder<LabSnippet> $query */
            $query->published();
        };
    }
}
