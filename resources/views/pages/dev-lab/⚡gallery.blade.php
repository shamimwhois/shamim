<?php

use App\Enums\LabLanguage;
use App\Models\LabSnippet;
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
 * The public snippet gallery.
 *
 * Only {@see LabSnippet::published()} rows are read here, and that rule lives in the
 * model's scope rather than in this component, so a snippet whose author never pressed
 * publish cannot appear in a listing that forgot to check. Publication is the
 * conjunction of the visibility flag and `published_at`, defined once on the model.
 *
 * Three facets, each counted through that same scope by {@see PublicTaxonomy} so no
 * chip can advertise a snippet this page will not open. Language first, because a
 * gallery of code is navigated by what it is written in. Subject second, and shared
 * with the ideas landing: the same platform-owned taxonomy files both kinds of row,
 * so "forms" means the same thing on both pages. Author last, offered only for
 * accounts with something published, which is what keeps this a view of code rather
 * than a directory of accounts.
 *
 * Search runs over title, note and the saved code with `LIKE`, because the default
 * connection is SQLite and has no full-text index. It is honest substring matching, and
 * the placeholder describes it as such rather than implying an index exists. It waits
 * for two characters, the same floor the ideas landing uses, because one keystroke
 * matching half the corpus is noise presented as a result.
 */
new #[Title('Snippet gallery')] class extends Component
{
    use WithPagination;

    /**
     * @var int Characters a query needs before the list filters at all.
     */
    public const MIN_QUERY_LENGTH = 2;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'lang', except: '')]
    public string $language = '';

    #[Url(as: 'topic', except: '')]
    public string $topicSlug = '';

    #[Url(as: 'author', except: '')]
    public string $authorHandle = '';

    #[Url(as: 'sort', except: 'recent')]
    public string $sort = 'recent';

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
        if (in_array($property, ['search', 'language', 'topicSlug', 'authorHandle', 'sort'], true)) {
            $this->resetPage();
            $this->syncMetadata();
        }
    }

    /**
     * Clear every filter at once.
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'language', 'topicSlug', 'authorHandle', 'sort');
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
     * The orderings on offer. The default is the order the gallery has always used,
     * so a reader who never touches the select sees what they saw before it existed.
     *
     * @return array<string, string>
     */
    public function sorts(): array
    {
        return [
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
     * the request, so the gallery would filter one keystroke behind the box.
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
            || $this->language !== ''
            || $this->topicSlug !== ''
            || $this->authorHandle !== ''
            || $this->sort !== 'recent';
    }

    /**
     * Published snippets per language, keyed by enum value.
     *
     * Counted over everything published rather than over the current view, the way the
     * subject chips are, so a chip never changes meaning when another filter moves.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function languageCounts(): array
    {
        $column = 'language_rows';

        /** @var Builder<LabSnippet> $query */
        $query = LabSnippet::query()->published();

        // Grouped in SQL and read back dynamically: `selectRaw` leaves the analyser no
        // way to know the alias exists, and the name is a constant rather than
        // anything a caller controls.
        return $query
            ->select('language')
            ->selectRaw('count(*) as '.$column)
            ->groupBy('language')
            ->get()
            ->mapWithKeys(fn (LabSnippet $row): array => [
                $row->language->value => (int) $row->{$column},
            ])
            ->all();
    }

    /**
     * The subjects a stranger may browse by, each carrying its published count.
     *
     * @return SupportCollection<int, Topic>
     */
    #[Computed]
    public function topics(): SupportCollection
    {
        return $this->taxonomy()->topicChips(PublicTaxonomy::SNIPPETS);
    }

    /**
     * The selected subject, or null when the URL names one nobody can browse.
     */
    #[Computed]
    public function activeTopic(): ?Topic
    {
        return $this->topicSlug === ''
            ? null
            : $this->taxonomy()->topic(PublicTaxonomy::SNIPPETS, $this->topicSlug);
    }

    /**
     * The authors behind published snippets, biggest first.
     *
     * @return SupportCollection<int, array{handle: string, name: string, count: int}>
     */
    #[Computed]
    public function authors(): SupportCollection
    {
        return $this->taxonomy()->authorChips(PublicTaxonomy::SNIPPETS);
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
     * @return LengthAwarePaginator<int, LabSnippet>
     */
    #[Computed]
    public function snippets(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with('user:id,name')
            ->paginate((int) config('dev_lab.per_page.public'))
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
     * The gate both facets come from, resolved per request rather than built here, so
     * this gallery, the ideas landing and `/topics` cannot drift.
     */
    protected function taxonomy(): PublicTaxonomy
    {
        return app(PublicTaxonomy::class);
    }

    /**
     * @return Builder<LabSnippet>
     */
    protected function filteredQuery(): Builder
    {
        $language = LabLanguage::tryFrom($this->language);

        $query = LabSnippet::query()
            ->published()
            ->when($this->isSearching(), fn (Builder $query) => $query->search($this->search))
            ->when($this->language !== '', fn (Builder $query) => $this->applyLanguage($query, $language))
            ->when($this->topicSlug !== '', fn (Builder $query) => $query->inTopic($this->topicSlug))
            ->when($this->authorHandle !== '', fn (Builder $query) => $query->byAuthor($this->authorHandle));

        $this->applySort($query);

        return $query;
    }

    /**
     * Narrow the gallery to the language the address bar asked for.
     *
     * A value the enum does not recognise still narrows, down to nothing. The chip
     * over the grid prints whatever `?lang=` said, so a list of everything published
     * under a heading naming one language would be a label describing a different
     * view — which is the same rule the subject facet follows when a URL names a
     * subject nobody can browse.
     */
    protected function applyLanguage(Builder $query, ?LabLanguage $language): Builder
    {
        return $language === null
            ? $query->where('language', $this->language)
            : $query->inLanguage($language);
    }

    /**
     * Translate the `sort` parameter into an ORDER BY.
     *
     * There is no ordering by line count, because lines are counted in PHP from the
     * stored code and a byte-length proxy in SQL would be a different number than the
     * one each card prints.
     */
    protected function applySort(Builder $query): void
    {
        match ($this->sort) {
            'oldest' => $query->orderBy('published_at')->orderBy('id'),
            'views' => $query->orderByDesc('view_count')->orderByDesc('published_at'),
            'title' => $query->orderBy('title'),
            default => $query->recentlyPublished(),
        };
    }

    protected function syncMetadata(): void
    {
        $topic = $this->topicSlug === ''
            ? null
            : $this->taxonomy()->topic(PublicTaxonomy::SNIPPETS, $this->topicSlug);

        app(Seo::class)->set([
            'title' => $topic === null ? 'Snippet gallery' : $topic->name.' — Snippet gallery',
            'description' => $this->isSearching()
                ? __('Snippets matching :term.', ['term' => trim($this->search)])
                : ($topic === null
                    ? __('Code people saved out of the Developer Lab sandbox and decided was worth sharing.')
                    : __('Snippets filed under :topic.', ['topic' => $topic->name])),
            'tags' => $topic === null ? [] : [$topic->name],
        ]);
    }
}; ?>

@php
    $selectClasses = 'focus-ring min-h-10 appearance-none rounded-lg border border-ink-200 bg-white py-2 pl-3 pr-9 text-sm text-ink-700 transition-colors hover:border-ink-300 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-200 dark:hover:border-ink-700';
    $activeAuthorName = $this->activeAuthor === null ? null : $this->activeAuthor['name'];
@endphp

<div data-test="dev-lab-gallery">
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
                    <li>
                        <a href="{{ route('dev-lab.index') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Developer Lab') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li class="text-ink-600 dark:text-ink-300">{{ __('Gallery') }}</li>
                </ol>
            </nav>

            <h1 class="mt-6 max-w-3xl text-[clamp(2rem,4.5vw,3.25rem)] font-semibold leading-[1.05] tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('Code people pulled out of the sandbox') }}
            </h1>

            <p class="mt-5 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                {{ __('A snippet is somebody’s working HTML, CSS, JavaScript or PHP, saved with a name and a note. Runnable ones offer a preview in a frame that cannot reach this site. PHP is stored and copyable, and there is no Run button for it.') }}
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <label for="gallery-search" class="sr-only">{{ __('Search snippets') }}</label>
                <div class="relative max-w-md flex-1">
                    <span aria-hidden="true" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-400 dark:text-ink-500">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="gallery-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="{{ __('Search titles, notes and the code itself') }}"
                        class="focus-ring min-h-12 w-full rounded-full border border-ink-200 bg-white py-3 pl-11 pr-4 text-sm text-ink-900 placeholder:text-ink-400 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-50 dark:placeholder:text-ink-500"
                    >
                </div>

                @auth
                    <a
                        href="{{ route('dev-lab.snippets') }}"
                        wire:navigate
                        class="focus-ring inline-flex min-h-12 items-center gap-2 rounded-full border border-ink-200 bg-white px-5 text-sm font-medium text-ink-800 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-100"
                    >
                        {{ __('Your snippets') }}
                    </a>
                @else
                    <a
                        href="{{ route('dev-lab.index') }}"
                        wire:navigate
                        class="focus-ring inline-flex min-h-12 items-center gap-2 rounded-full border border-ink-200 bg-white px-5 text-sm font-medium text-ink-800 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-100"
                    >
                        {{ __('What the lab runs') }}
                    </a>
                @endauth
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | Subjects
     --}}
    @if ($this->topics->isNotEmpty())
        <section class="border-b border-ink-200 py-8 dark:border-ink-800" aria-labelledby="gallery-topics-heading">
            <div class="shell">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <h2 id="gallery-topics-heading" class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
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

                        {{-- The same shelf the ideas landing filters by, and the same index it
                             comes from. See `pages/⚡topics.blade.php`. --}}
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
                    @foreach ($this->topics as $topic)
                        <li class="shrink-0" wire:key="gallery-subject-{{ $topic->id }}">
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
                                <span class="text-2xs tabular-nums opacity-70">{{ $topic->lab_snippets_count }}</span>
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
    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="gallery-list-heading">
        <div class="flex flex-wrap items-end justify-between gap-6">
            <div class="min-w-0">
                <h2 id="gallery-list-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    {{ $this->activeTopic?->name ?? $activeAuthorName ?? __('Everything published') }}
                </h2>

                <p class="mt-3 max-w-xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ $this->activeTopic?->description ?? __('A snippet is listed once its author turns on visibility. PHP is stored and copyable; nothing here runs on this site’s server.') }}
                </p>
            </div>

            <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count snippet|:count snippets', $this->total, ['count' => $this->total]) }}
            </p>
        </div>

        <div class="mt-8 flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Filter by language') }}">
            <button
                type="button"
                role="tab"
                wire:key="gallery-lang-"
                aria-selected="{{ $language === '' ? 'true' : 'false' }}"
                wire:click="$set('language', '')"
                @class([
                    'focus-ring inline-flex min-h-10 items-center rounded-full border px-4 text-sm transition-colors',
                    'border-signal-600 bg-signal-600 text-white dark:border-signal-500 dark:bg-signal-500 dark:text-ink-950' => $language === '',
                    'border-ink-200 bg-white text-ink-600 hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300' => $language !== '',
                ])
            >
                {{ __('Everything') }}
            </button>

            @foreach (LabLanguage::cases() as $case)
                @continue(($this->languageCounts[$case->value] ?? 0) === 0)

                <button
                    type="button"
                    role="tab"
                    wire:key="gallery-lang-{{ $case->value }}"
                    aria-selected="{{ $language === $case->value ? 'true' : 'false' }}"
                    wire:click="$set('language', '{{ $case->value }}')"
                    data-test="language-chip-{{ $case->value }}"
                    @class([
                        'focus-ring inline-flex min-h-10 items-center gap-2 rounded-full border px-4 text-sm transition-colors',
                        'border-signal-600 bg-signal-600 text-white dark:border-signal-500 dark:bg-signal-500 dark:text-ink-950' => $language === $case->value,
                        'border-ink-200 bg-white text-ink-600 hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300' => $language !== $case->value,
                    ])
                >
                    {{ $case->label() }}
                    <span class="text-2xs tabular-nums opacity-70">{{ $this->languageCounts[$case->value] }}</span>
                    @unless ($case->isBrowserRunnable())
                        <span class="font-mono text-2xs uppercase tracking-widest opacity-70">{{ __('stored') }}</span>
                    @endunless
                </button>
            @endforeach
        </div>

        {{-- Toolbar. Native selects, styled once: a custom listbox is more
             JavaScript than a filter needs, and native selects are already
             keyboard- and screen-reader-native. --}}
        <div class="mt-6 flex flex-wrap items-end gap-3 border-y border-ink-200 py-4 dark:border-ink-800">
            <div>
                <label for="gallery-sort" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Sort') }}
                </label>
                <div class="relative">
                    <select id="gallery-sort" wire:model.live="sort" class="{{ $selectClasses }}">
                        @foreach ($this->sorts() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                </div>
            </div>

            @if ($this->authors->isNotEmpty())
                <div>
                    <label for="gallery-author" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        {{ __('Author') }}
                    </label>
                    <div class="relative">
                        <select id="gallery-author" wire:model.live="authorHandle" class="{{ $selectClasses }}">
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

            @if ($language !== '')
                <p class="flex min-h-10 items-center gap-2 rounded-full border border-ink-200 bg-ink-50 px-3 text-xs text-ink-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-300">
                    <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Language') }}</span>
                    <span class="max-w-40 truncate">{{ LabLanguage::tryFrom($language)?->label() ?? $language }}</span>
                    <button type="button" wire:click="$set('language', '')" class="focus-ring rounded p-0.5 text-ink-400 hover:text-ink-700 dark:hover:text-ink-200" aria-label="{{ __('Clear the language filter') }}">
                        <flux:icon name="x-mark" class="size-3.5" />
                    </button>
                </p>
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
        <div wire:loading.delay.shorter wire:target="search, language, topicSlug, authorHandle, sort" aria-hidden="true" data-test="gallery-skeleton">
            <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach (range(1, 6) as $slot)
                    <div class="animate-pulse rounded-xl border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-950">
                        <div class="h-2.5 w-24 rounded-full bg-ink-200 dark:bg-ink-800"></div>
                        <div class="mt-4 h-3 w-full rounded bg-ink-100 dark:bg-ink-900"></div>
                        <div class="mt-2 h-3 w-5/6 rounded bg-ink-100 dark:bg-ink-900"></div>
                        <div class="mt-2 h-3 w-2/3 rounded bg-ink-100 dark:bg-ink-900"></div>
                        <div class="mt-6 flex gap-2">
                            <div class="h-4 w-14 rounded-full bg-ink-100 dark:bg-ink-900"></div>
                            <div class="h-4 w-10 rounded-full bg-ink-100 dark:bg-ink-900"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div wire:loading.remove wire:target="search, language, topicSlug, authorHandle, sort" data-test="gallery-results">
            @if ($this->snippets->isNotEmpty())
            <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->snippets as $snippet)
                    <x-app.dev-lab.snippet-card
                        :snippet="$snippet"
                        wire:key="gallery-snippet-{{ $snippet->id }}"
                    />
                @endforeach
            </div>

            <x-app.ui.pagination :paginator="$this->snippets" label="{{ __('Snippets pagination') }}" class="mt-10" />
        @else
                <x-app.ui.empty-state
                    class="mt-8"
                    icon="code-bracket"
                    :title="$this->hasActiveFilters
                        ? __('Nothing matches this view')
                        : __('Nobody has published a snippet yet')"
                    :description="$this->hasActiveFilters
                        ? __('Search runs over the saved code as plain text. Try a shorter phrase, drop the language or subject, or reset the filters to see everything published.')
                        : __('Snippets appear here when their author turns on visibility. Keep your own in the sandbox; it is the same editor with a Save button.')"
                >
                    @if ($this->hasActiveFilters)
                        <flux:button wire:click="clearFilters" icon="arrow-path">
                            {{ __('Reset filters') }}
                        </flux:button>
                    @elseif (auth()->check())
                        <flux:button :href="route('dev-lab.snippets.create')" wire:navigate icon="plus">
                            {{ __('Save your own') }}
                        </flux:button>
                    @endif
                </x-app.ui.empty-state>
            @endif
        </div>
    </section>
</div>
