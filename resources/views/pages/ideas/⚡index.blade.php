<?php

use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Models\Topic;
use App\Services\PublicTaxonomy;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public ideas index.
 *
 * Only {@see Idea::published()} rows are read here, and that rule lives in the
 * model's scope rather than in this component, so a draft, an inbox capture and
 * an idea its author has unpublisheded cannot reach a stranger through a second
 * listing that forgot the condition.
 *
 * Navigation is by subject and by author, and both come from
 * {@see PublicTaxonomy}, which counts through the same `published()` scope the list
 * uses. That is what keeps a chip from advertising an idea this page will not open.
 * The private `idea_categories` folders are deliberately absent: they are one
 * account's filing, and exposing them would publish how a stranger organises their
 * notes. A subject is platform-owned, an author chooses it, and it appears publicly
 * only once something published carries it.
 *
 * Search is `LIKE` over title, summary and content because the default connection is
 * SQLite and has no full-text index; it is honest substring matching, not a search
 * engine, and is described that way to the reader. It also waits for two characters,
 * because one keystroke matching half the corpus is noise presented as a result.
 */
new #[Title('Ideas')] class extends Component
{
    use WithPagination;

    /**
     * @var int Characters a query needs before the list filters at all.
     */
    public const MIN_QUERY_LENGTH = 2;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'tab', except: 'promoted')]
    public string $tab = 'promoted';

    #[Url(as: 'topic', except: '')]
    public string $topicSlug = '';

    #[Url(as: 'author', except: '')]
    public string $authorHandle = '';

    #[Url(as: 'sort', except: 'tab')]
    public string $sort = 'tab';

    public function mount(): void
    {
        $this->syncMetadata();
    }

    /**
     * Reset to page one whenever a filter moves, and refresh the metadata so a
     * shared link previews the view it actually points at.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'tab', 'topicSlug', 'authorHandle', 'sort'], true)) {
            $this->resetPage();
            $this->syncMetadata();
        }
    }

    /**
     * Clear every filter at once.
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'tab', 'topicSlug', 'authorHandle', 'sort');
        $this->resetPage();
        $this->syncMetadata();
    }

    /**
     * Apply a subject filter, toggling it off when it is already the filter.
     */
    public function toggleTopic(string $slug): void
    {
        $this->topicSlug = $this->topicSlug === $slug ? '' : $slug;
        $this->resetPage();
        $this->syncMetadata();
    }

    /**
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return [
            'promoted' => __('Promoted'),
            'latest' => __('Latest'),
            'approved' => __('Approved'),
        ];
    }

    /**
     * The orderings on offer. The default is the tab's own order, so choosing a tab
     * and touching nothing else behaves exactly as it did before this select existed.
     *
     * @return array<string, string>
     */
    public function sorts(): array
    {
        return [
            'tab' => __('The tab’s order'),
            'recent' => __('Recent'),
            'oldest' => __('Oldest'),
            'views' => __('Most viewed'),
            'title' => __('Title A–Z'),
        ];
    }

    /**
     * Whether the query is long enough to filter by.
     *
     * A method rather than a computed property: `updating()` runs before the new
     * search term lands, and a computed value read there would be held for the rest of
     * the request, so the list would filter one keystroke behind the box. The AI Hub
     * landing reads its raw properties for the same reason.
     */
    public function isSearching(): bool
    {
        return Str::length(trim($this->search)) >= self::MIN_QUERY_LENGTH;
    }

    /**
     * Whether anything currently narrows the list.
     */
    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->isSearching()
            || $this->topicSlug !== ''
            || $this->authorHandle !== ''
            || $this->sort !== 'tab'
            || $this->tab !== 'promoted';
    }

    /**
     * The subjects a stranger may browse by, each carrying its published count.
     *
     * @return SupportCollection<int, Topic>
     */
    #[Computed]
    public function topics(): SupportCollection
    {
        return $this->taxonomy()->topicChips(PublicTaxonomy::IDEAS);
    }

    /**
     * The selected subject, or null when the URL names one nobody can browse.
     */
    #[Computed]
    public function activeTopic(): ?Topic
    {
        return $this->topicSlug === ''
            ? null
            : $this->taxonomy()->topic(PublicTaxonomy::IDEAS, $this->topicSlug);
    }

    /**
     * The authors behind published ideas, biggest first.
     *
     * @return SupportCollection<int, array{handle: string, name: string, count: int}>
     */
    #[Computed]
    public function authors(): SupportCollection
    {
        return $this->taxonomy()->authorChips(PublicTaxonomy::IDEAS);
    }

    /**
     * The author the current view is narrowed to.
     *
     * @return array{handle: string, name: string, count: int}|null
     */
    #[Computed]
    public function activeAuthor(): ?array
    {
        return $this->authorHandle === ''
            ? null
            : $this->authors->firstWhere('handle', $this->authorHandle);
    }

    /**
     * The gate both facets come from, resolved per request rather than built here, so
     * this landing, the gallery and `/topics` cannot drift about what is browseable.
     */
    protected function taxonomy(): PublicTaxonomy
    {
        return app(PublicTaxonomy::class);
    }

    /**
     * @return LengthAwarePaginator<int, Idea>
     */
    #[Computed]
    public function ideas(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with('user')
            ->paginate((int) config('ideas.per_page.public'))
            ->withQueryString();
    }

    /**
     * The count for the current view, independent of pagination.
     */
    #[Computed]
    public function total(): int
    {
        return $this->filteredQuery()->count();
    }

    /**
     * @return Builder<Idea>
     */
    protected function filteredQuery(): Builder
    {
        $query = Idea::query()
            ->published()
            ->with('category')
            ->when($this->isSearching(), fn (Builder $query) => $query->search($this->search))
            ->when($this->topicSlug !== '', fn (Builder $query) => $query->inTopic($this->topicSlug))
            ->when($this->authorHandle !== '', fn (Builder $query) => $query->byAuthor($this->authorHandle))
            ->when($this->tab === 'approved', fn (Builder $query) => $query->inStatus(IdeaStatus::Approved));

        $this->applySort($query);

        return $query;
    }

    /**
     * Translate the `sort` parameter into an ORDER BY.
     */
    protected function applySort(Builder $query): void
    {
        match ($this->sort) {
            'recent' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'oldest' => $query->orderBy('published_at')->orderBy('id'),
            'views' => $query->orderByDesc('view_count')->orderByDesc('published_at'),
            'title' => $query->orderBy('title'),
            default => $query->when(
                $this->tab === 'latest',
                fn (Builder $query) => $query->orderByDesc('published_at')->orderByDesc('id'),
                fn (Builder $query) => $query->orderByDesc('is_pinned')->orderByDesc('published_at'),
            ),
        };
    }

    protected function syncMetadata(): void
    {
        $topic = $this->topicSlug === ''
            ? null
            : $this->taxonomy()->topic(PublicTaxonomy::IDEAS, $this->topicSlug);

        app(Seo::class)->set([
            'title' => $topic === null ? 'Ideas' : $topic->name.' — Ideas',
            'description' => $this->isSearching()
                ? __('Ideas matching :term.', ['term' => trim($this->search)])
                : ($topic === null
                    ? __('Ideas people have written down and decided were worth sharing, from the Ideas workspace.')
                    : __('Ideas filed under :topic.', ['topic' => $topic->name])),
            'tags' => $topic === null ? [] : [$topic->name],
        ]);
    }
}; ?>

@php
    $selectClasses = 'focus-ring min-h-10 appearance-none rounded-lg border border-ink-200 bg-white py-2 pl-3 pr-9 text-sm text-ink-700 transition-colors hover:border-ink-300 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-200 dark:hover:border-ink-700';
@endphp

<div data-test="ideas-index">
    {{-- ================================================================== --
     | Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-12 lg:py-16">
            <nav aria-label="{{ __('Breadcrumb') }}">
                <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                    <li>
                        <a href="{{ route('home') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Home') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li class="text-ink-600 dark:text-ink-300">{{ __('Ideas') }}</li>
                </ol>
            </nav>

            <h1 class="mt-6 max-w-3xl text-[clamp(2rem,4.5vw,3.25rem)] font-semibold leading-[1.05] tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('Thoughts people decided were worth sharing') }}
            </h1>

            <p class="mt-5 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                {{ __('An idea is not a product announcement. It is something somebody wrote down before it slipped away, then approved and published. Most of them are unfinished on purpose.') }}
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <label for="ideas-search" class="sr-only">{{ __('Search ideas') }}</label>
                <div class="relative max-w-md flex-1">
                    <span aria-hidden="true" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-400 dark:text-ink-500">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="ideas-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="{{ __('Search titles, summaries and notes') }}"
                        class="focus-ring min-h-12 w-full rounded-full border border-ink-200 bg-white py-3 pl-11 pr-4 text-sm text-ink-900 placeholder:text-ink-400 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-50 dark:placeholder:text-ink-500"
                    >
                </div>

                @auth
                    <a
                        href="{{ route('ideas.dashboard') }}"
                        wire:navigate
                        class="focus-ring inline-flex min-h-12 items-center gap-2 rounded-full border border-ink-200 bg-white px-5 text-sm font-medium text-ink-800 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-100"
                    >
                        {{ __('Your ideas') }}
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        class="focus-ring inline-flex min-h-12 items-center gap-2 rounded-full border border-ink-200 bg-white px-5 text-sm font-medium text-ink-800 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-100"
                    >
                        {{ __('Sign in to keep your own') }}
                    </a>
                @endauth
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | Subjects
     --}}
    @if ($this->topics->isNotEmpty())
        <section class="border-b border-ink-200 py-8 dark:border-ink-800" aria-labelledby="ideas-topics-heading">
            <div class="shell">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <h2 id="ideas-topics-heading" class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
                        {{ __('Browse by subject') }}
                    </h2>

                    <div class="flex items-center gap-4">
                        @if ($this->topicSlug !== '')
                            <button
                                type="button"
                                wire:click="toggleTopic('{{ $this->topicSlug }}')"
                                class="focus-ring inline-flex min-h-8 items-center gap-1.5 rounded-full px-3 font-mono text-2xs uppercase tracking-widest text-signal-700 transition-colors hover:bg-signal-50 dark:text-signal-400 dark:hover:bg-signal-950/40"
                            >
                                {{ __('Clear subject') }}
                                <span aria-hidden="true">&times;</span>
                            </button>
                        @endif

                        {{-- The chips here are this landing's own filter; the index they come from
                             is a page of its own, and a reader who wants the whole vocabulary —
                             including the shelf in the gallery — needs one way to ask for it. --}}
                        <a
                            href="{{ route('topics.index') }}"
                            wire:navigate
                            class="focus-ring inline-flex min-h-8 items-center gap-1.5 font-mono text-2xs uppercase tracking-widest text-ink-500 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                        >
                            {{ __('Every subject') }}
                            <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>

                <ul class="mt-4 flex snap-x gap-2 overflow-x-auto pb-2" role="list">
                    <li class="shrink-0">
                        <button
                            type="button"
                            wire:click="clearFilters"
                            @class([
                                'focus-ring inline-flex min-h-10 items-center gap-2 rounded-full border px-4 py-2 text-sm transition-colors',
                                'border-signal-600 bg-signal-600 text-white dark:border-signal-500 dark:bg-signal-500 dark:text-ink-950' => ! $this->hasActiveFilters,
                                'border-ink-200 bg-white text-ink-600 hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300 dark:hover:border-signal-600 dark:hover:text-signal-400' => $this->hasActiveFilters,
                            ])
                        >
                            {{ __('Everything') }}
                        </button>
                    </li>

                    @foreach ($this->topics as $topic)
                        <li class="shrink-0" wire:key="ideas-subject-{{ $topic->id }}">
                            <button
                                type="button"
                                wire:click="toggleTopic('{{ $topic->slug }}')"
                                @class([
                                    'focus-ring inline-flex min-h-10 items-center gap-2 rounded-full border px-4 py-2 text-sm transition-colors',
                                    'border-signal-600 bg-signal-600 text-white dark:border-signal-500 dark:bg-signal-500 dark:text-ink-950' => $this->topicSlug === $topic->slug,
                                    'border-ink-200 bg-white text-ink-600 hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300 dark:hover:border-signal-600 dark:hover:text-signal-400' => $this->topicSlug !== $topic->slug,
                                ])
                                @if ($this->topicSlug === $topic->slug) aria-pressed="true" @endif
                            >
                                <flux:icon :name="$topic->icon ?? 'tag'" class="size-4" />
                                <span>{{ $topic->name }}</span>
                                @if ($topic->ideas_count > 0)
                                    <span class="text-2xs tabular-nums opacity-70">{{ $topic->ideas_count }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | The list
     --}}
    @php
        $activeAuthorName = $this->activeAuthor === null ? null : $this->activeAuthor['name'];
    @endphp

    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="ideas-list-heading">
        <div class="flex flex-wrap items-end justify-between gap-6">
            <div class="min-w-0">
                <h2 id="ideas-list-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    {{ $this->activeTopic?->name ?? $activeAuthorName ?? __('Everything published') }}
                </h2>

                <p class="mt-3 max-w-xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ $this->activeTopic?->description ?? __('Drafts, inbox captures and unpublished ideas never appear here. An idea is listed once its author approves it and chooses to publish.') }}
                </p>
            </div>

            <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count idea|:count ideas', $this->total, ['count' => $this->total]) }}
            </p>
        </div>

        <div class="mt-8 flex flex-wrap gap-2" role="tablist" aria-label="{{ __('View ideas') }}">
            @foreach ($this->tabs() as $key => $label)
                <button
                    type="button"
                    role="tab"
                    wire:key="ideas-tab-{{ $key }}"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'focus-ring inline-flex min-h-10 items-center rounded-full border px-4 text-sm transition-colors',
                        'border-signal-600 bg-signal-600 text-white dark:border-signal-500 dark:bg-signal-500 dark:text-ink-950' => $tab === $key,
                        'border-ink-200 bg-white text-ink-600 hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300' => $tab !== $key,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Toolbar. Native selects, styled once: a custom listbox is more
             JavaScript than a filter needs, and native selects are already
             keyboard- and screen-reader-native. --}}
        <div class="mt-6 flex flex-wrap items-end gap-3 border-y border-ink-200 py-4 dark:border-ink-800">
            <div>
                <label for="ideas-sort" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Sort') }}
                </label>
                <div class="relative">
                    <select id="ideas-sort" wire:model.live="sort" class="{{ $selectClasses }}">
                        @foreach ($this->sorts() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                </div>
            </div>

            @if ($this->authors->isNotEmpty())
                <div>
                    <label for="ideas-author" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        {{ __('Author') }}
                    </label>
                    <div class="relative">
                        <select id="ideas-author" wire:model.live="authorHandle" class="{{ $selectClasses }}">
                            <option value="">{{ __('Anyone') }}</option>
                            @foreach ($this->authors as $author)
                                <option value="{{ $author['handle'] }}">
                                    {{ $author['name'] }} ({{ $author['count'] }})
                                </option>
                            @endforeach
                        </select>
                        <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                    </div>
                </div>
            @endif

            @if ($this->topicSlug !== '')
                <p class="flex min-h-10 items-center gap-2 rounded-full border border-ink-200 bg-ink-50 px-3 text-xs text-ink-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-300">
                    <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Subject') }}</span>
                    <span class="max-w-40 truncate">{{ $this->activeTopic?->name ?? $this->topicSlug }}</span>
                    <button type="button" wire:click="toggleTopic('{{ $this->topicSlug }}')" class="focus-ring rounded p-0.5 text-ink-400 hover:text-ink-700 dark:hover:text-ink-200" aria-label="{{ __('Clear the subject filter') }}">
                        <flux:icon name="x-mark" class="size-3.5" />
                    </button>
                </p>
            @endif

            @if ($this->authorHandle !== '')
                <p class="flex min-h-10 items-center gap-2 rounded-full border border-ink-200 bg-ink-50 px-3 text-xs text-ink-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-300">
                    <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Author') }}</span>
                    <span class="max-w-40 truncate">{{ $activeAuthorName ?? $this->authorHandle }}</span>
                    <button type="button" wire:click="$set('authorHandle', '')" class="focus-ring rounded p-0.5 text-ink-400 hover:text-ink-700 dark:hover:text-ink-200" aria-label="{{ __('Clear the author filter') }}">
                        <flux:icon name="x-mark" class="size-3.5" />
                    </button>
                </p>
            @endif

            @if ($this->search !== '')
                <p class="flex min-h-10 items-center gap-2 rounded-full border border-ink-200 bg-ink-50 px-3 text-xs text-ink-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-300">
                    <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Query') }}</span>
                    <span class="max-w-40 truncate">{{ $this->search }}</span>
                    <button type="button" wire:click="$set('search', '')" class="focus-ring rounded p-0.5 text-ink-400 hover:text-ink-700 dark:hover:text-ink-200" aria-label="{{ __('Clear the search term') }}">
                        <flux:icon name="x-mark" class="size-3.5" />
                    </button>
                </p>
            @endif

            @if ($this->hasActiveFilters)
                <button
                    type="button"
                    wire:click="clearFilters"
                    class="focus-ring ml-auto inline-flex min-h-10 items-center rounded-full px-3 text-xs font-medium text-ink-500 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                >
                    {{ __('Reset filters') }}
                </button>
            @endif
        </div>

        @if ($this->search !== '' && ! $this->isSearching())
            <p class="mt-6 flex flex-wrap items-center gap-2 text-sm leading-relaxed text-ink-500 dark:text-ink-400" role="status">
                <flux:icon name="exclamation-circle" class="size-4 shrink-0 text-ink-400 dark:text-ink-500" />
                {{ __('Type at least two characters to search — what is listed below is still everything published.') }}
            </p>
        @endif

        {{-- Loading skeleton, shown only while a filter request is in flight. The
             wrapper carries the directive and the inner element carries the grid,
             because `wire:loading` hides by writing `display: none` inline and a
             grid class on the same element would be fighting that. --}}
        <div wire:loading.delay.shorter wire:target="search, tab, topicSlug, authorHandle, sort" aria-hidden="true">
            <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach (range(1, 6) as $slot)
                    <div class="animate-pulse rounded-xl border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-950">
                        <div class="h-2.5 w-20 rounded-full bg-ink-200 dark:bg-ink-800"></div>
                        <div class="mt-5 h-5 w-3/4 rounded bg-ink-200 dark:bg-ink-800"></div>
                        <div class="mt-3 h-4 w-full rounded bg-ink-100 dark:bg-ink-900"></div>
                        <div class="mt-2 h-4 w-5/6 rounded bg-ink-100 dark:bg-ink-900"></div>
                        <div class="mt-6 h-3 w-1/2 rounded bg-ink-100 dark:bg-ink-900"></div>
                    </div>
                @endforeach
            </div>
        </div>

        <div wire:loading.remove wire:target="search, tab, topicSlug, authorHandle, sort">
            @if ($this->ideas->isNotEmpty())
                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->ideas as $idea)
                        <x-app.ideas.card
                            :idea="$idea"
                            :href="route('ideas.show', ['idea' => $idea->slug])"
                            wire:key="idea-{{ $idea->id }}"
                        />
                    @endforeach
                </div>

                <x-app.ui.pagination :paginator="$this->ideas" label="{{ __('Ideas pagination') }}" class="mt-10" />
            @else
                <x-app.ui.empty-state
                    class="mt-8"
                    icon="light-bulb"
                    :title="$this->hasActiveFilters ? __('No idea matches this view') : __('Nothing has been published yet')"
                    :description="$this->hasActiveFilters
                        ? __('Search matches titles, summaries and the body of an idea. Try a shorter phrase, drop the subject, or reset the filters to see everything published.')
                        : __('Ideas only appear here once their author approves them and chooses to publish. Try again later, or keep your own in the workspace.')"
                >
                    @if ($this->hasActiveFilters)
                        <flux:button wire:click="clearFilters" icon="arrow-path">
                            {{ __('Reset filters') }}
                        </flux:button>
                    @elseif (auth()->check())
                        <flux:button :href="route('ideas.dashboard')" wire:navigate icon="plus">
                            {{ __('Capture your own') }}
                        </flux:button>
                    @endif
                </x-app.ui.empty-state>
            @endif
        </div>
    </section>
</div>
