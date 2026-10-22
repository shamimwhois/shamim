<?php

use App\Enums\AiArticleFormat;
use App\Enums\AiDifficulty;
use App\Models\AiArticle;
use App\Models\AiTopic;
use App\Services\AiHubContent;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The hub's article index.
 *
 * Five filters, all reflected in the URL so any view of the list is shareable
 * (the same state model as the marketplace and blog indexes):
 *
 *   ?q=           free-text search over the plain-text body projection
 *   ?topic=       topic slug
 *   ?difficulty=  beginner | intermediate | advanced | expert
 *   ?format=      article | tutorial | technical-note | …
 *   ?sort=        featured | newest | oldest | shortest | longest | title
 *   ?page=        pagination
 *
 * Only `published()` rows are read here, and that rule lives in the model's
 * scope rather than in this component, so the landing shelves, the topic page
 * and this index cannot disagree about what is public.
 *
 * Difficulty and format are enumerated in PHP and rendered from the enums'
 * `options()`, not read from the data. A filter list built from rows would hide
 * a whole tier of difficulty the first time an editor published nothing at that
 * level, which reads to a visitor as a missing feature rather than an empty one.
 */
new #[Title('Articles — AI Knowledge Hub')] class extends Component
{
    use WithPagination;

    /**
     * Free-text query, debounced so typing does not fire a request per keystroke.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'topic', except: '')]
    public string $topicSlug = '';

    #[Url(as: 'difficulty', except: '')]
    public string $difficulty = '';

    #[Url(as: 'format', except: '')]
    public string $format = '';

    #[Url(except: 'featured')]
    public string $sort = 'featured';

    public function mount(): void
    {
        $this->syncMetadata();
    }

    /**
     * Reset to page 1 whenever a filter changes, and refresh the metadata so a
     * shared link previews the filter it actually points at.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'topicSlug', 'difficulty', 'format', 'sort'], true)) {
            $this->resetPage();
            $this->syncMetadata();
        }
    }

    /**
     * Clear every filter at once.
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'topicSlug', 'difficulty', 'format', 'sort');
        $this->resetPage();
        $this->syncMetadata();
    }

    /**
     * Apply a topic filter, toggling it off when it is already active.
     */
    public function toggleTopic(string $slug): void
    {
        $this->topicSlug = $this->topicSlug === $slug ? '' : $slug;
        $this->resetPage();
        $this->syncMetadata();
    }

    /**
     * Whether any filter is currently narrowing the list.
     */
    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->topicSlug !== ''
            || $this->difficulty !== ''
            || $this->format !== ''
            || $this->sort !== 'featured';
    }

    /**
     * Every topic that can appear in the chip row, flattened roots-then-children.
     *
     * @return Collection<int, AiTopic>
     */
    #[Computed]
    public function topics(): Collection
    {
        return app(AiHubContent::class)->topicChips();
    }

    /**
     * The filtered page of published articles.
     *
     * @return LengthAwarePaginator<int, AiArticle>
     */
    #[Computed]
    public function articles(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with(['topics', 'user'])
            ->paginate((int) config('ai_hub.per_page.articles'))
            ->withQueryString();
    }

    /**
     * The article count for the current filter, independent of pagination.
     */
    #[Computed]
    public function total(): int
    {
        return $this->filteredQuery()->count();
    }

    /**
     * The list query with every active filter applied.
     *
     * One definition, used by both the grid and the count, so the two cannot
     * disagree about what the current view contains.
     *
     * @return Builder<AiArticle>
     */
    protected function filteredQuery(): Builder
    {
        $query = AiArticle::query()
            ->published()
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when($this->topicSlug !== '', fn (Builder $query) => $query->inTopic($this->topicSlug))
            ->when(AiDifficulty::tryFrom($this->difficulty) !== null, fn (Builder $query) => $query->atDifficulty(AiDifficulty::from($this->difficulty)))
            ->when(AiArticleFormat::tryFrom($this->format) !== null, fn (Builder $query) => $query->inFormat(AiArticleFormat::from($this->format)));

        $this->applySort($query);

        return $query;
    }

    /**
     * Translate the `sort` query parameter into an ORDER BY.
     */
    protected function applySort(Builder $query): void
    {
        match ($this->sort) {
            'newest' => $query->recent(),
            'oldest' => $query->orderBy('published_at')->orderBy('id'),
            'shortest' => $query->readableOrder(),
            'longest' => $query->orderByDesc('reading_time')->orderByDesc('published_at'),
            'title' => $query->orderBy('title'),
            default => $query->orderByDesc('is_featured')->orderByDesc('published_at'),
        };
    }

    /**
     * Write the page's head metadata for the current filter state.
     */
    protected function syncMetadata(): void
    {
        $topic = $this->topicSlug === ''
            ? null
            : app(AiHubContent::class)->topic($this->topicSlug);

        app(Seo::class)->set([
            'title' => $topic instanceof AiTopic
                ? __(':topic — Articles', ['topic' => $topic->name])
                : 'Articles — AI Knowledge Hub',
            'description' => $topic instanceof AiTopic
                ? ($topic->description ?? __('Published articles, tutorials and technical notes in the AI Knowledge Hub.'))
                : __('Published articles, tutorials, technical notes and code examples from the AI Knowledge Hub. Filter by topic, difficulty or format.'),
        ]);
    }
}; ?>

@php
    $difficultyOptions = AiDifficulty::options();
    $formatOptions = AiArticleFormat::options();

    $sortOptions = [
        'featured' => __('Featured'),
        'newest' => __('Newest'),
        'oldest' => __('Oldest'),
        'shortest' => __('Shortest read'),
        'longest' => __('Longest read'),
        'title' => __('Title A–Z'),
    ];

    $selectClasses = 'focus-ring min-h-10 appearance-none rounded-lg border border-ink-200 bg-white py-2 pl-3 pr-9 text-sm text-ink-700 transition-colors hover:border-ink-300 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-200 dark:hover:border-ink-700';
@endphp

<div data-test="ai-hub-articles">
    {{-- ================================================================== --
     | 01 — Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-12 lg:py-16">
            <x-app.ui.section-marker :number="1" label="Articles" accent />

            <nav aria-label="{{ __('Breadcrumb') }}" class="mt-8">
                <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                    <li>
                        <a href="{{ route('ai-hub.index') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('AI Knowledge Hub') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li class="text-ink-600 dark:text-ink-300">{{ __('Articles') }}</li>
                </ol>
            </nav>

            <h1 class="mt-6 max-w-3xl text-[clamp(2rem,4.5vw,3.25rem)] font-semibold leading-[1.05] tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('Everything worth reading once') }}
            </h1>

            <p class="mt-5 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                {{ __('Every published article in the hub. Filter by topic, difficulty or format; the URL keeps whatever view you land on shareable.') }}
            </p>

            <div class="mt-8 max-w-xl">
                <label for="ai-hub-articles-search" class="sr-only">{{ __('Search articles') }}</label>
                <div class="relative">
                    <span aria-hidden="true" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-400 dark:text-ink-500">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="ai-hub-articles-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="{{ __('Search titles, summaries and body text') }}"
                        class="focus-ring min-h-12 w-full rounded-full border border-ink-200 bg-white py-3 pl-11 pr-4 text-sm text-ink-900 placeholder:text-ink-400 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-50 dark:placeholder:text-ink-500"
                    >
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Topic chips
     --}}
    @if ($this->topics->isNotEmpty())
        <section class="border-b border-ink-200 py-8 dark:border-ink-800" aria-labelledby="hub-article-topics-heading">
            <div class="shell">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <h2 id="hub-article-topics-heading" class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
                        {{ __('Browse by topic') }}
                    </h2>

                    @if ($this->topicSlug !== '')
                        <button
                            type="button"
                            wire:click="toggleTopic('{{ $this->topicSlug }}')"
                            class="focus-ring inline-flex min-h-8 items-center gap-1.5 rounded-full px-3 font-mono text-2xs uppercase tracking-widest text-signal-700 transition-colors hover:bg-signal-50 dark:text-signal-400 dark:hover:bg-signal-950/40"
                        >
                            {{ __('Clear topic') }}
                            <span aria-hidden="true">&times;</span>
                        </button>
                    @endif
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
                        <li class="shrink-0">
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
                                @if ($topic->articles_count > 0)
                                    <span class="text-2xs tabular-nums opacity-70">{{ $topic->articles_count }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 03 — The list
     --}}
    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="hub-articles-list-heading">
        <x-app.ui.section-marker :number="2" label="The list" />

        <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
            <div class="min-w-0">
                <h2 id="hub-articles-list-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    @php $activeTopic = $this->topicSlug === '' ? null : $this->topics->firstWhere('slug', $this->topicSlug); @endphp
                    {{ $activeTopic?->name ?? __('Every article') }}
                </h2>

                <p class="mt-3 max-w-xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ $activeTopic?->description ?? __('Drafts and archived pieces never appear here; an article is listed only once an editor has published it.') }}
                </p>
            </div>

            <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count article|:count articles', $this->total) }}
            </p>
        </div>

        {{-- Toolbar. Native selects, styled once: a custom listbox is more
             JavaScript than a filter needs, and native selects are already
             keyboard- and screen-reader-native. --}}
        <div class="mt-8 flex flex-wrap items-end gap-3 border-y border-ink-200 py-4 dark:border-ink-800">
            <div>
                <label for="hub-articles-difficulty" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Difficulty') }}
                </label>
                <div class="relative">
                    <select id="hub-articles-difficulty" wire:model.live="difficulty" class="{{ $selectClasses }}">
                        <option value="">{{ __('Any level') }}</option>
                        @foreach ($difficultyOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                </div>
            </div>

            <div>
                <label for="hub-articles-format" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Format') }}
                </label>
                <div class="relative">
                    <select id="hub-articles-format" wire:model.live="format" class="{{ $selectClasses }}">
                        <option value="">{{ __('Any format') }}</option>
                        @foreach ($formatOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                </div>
            </div>

            <div>
                <label for="hub-articles-sort" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Sort') }}
                </label>
                <div class="relative">
                    <select id="hub-articles-sort" wire:model.live="sort" class="{{ $selectClasses }}">
                        @foreach ($sortOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                </div>
            </div>

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

        @if ($this->articles->isNotEmpty())
            <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->articles as $article)
                    <x-app.ai-hub.article-card :article="$article" wire:key="article-{{ $article->id }}" />
                @endforeach
            </div>

            <x-app.ui.pagination :paginator="$this->articles" label="{{ __('Articles pagination') }}" class="mt-10" />
        @else
            <x-app.ui.empty-state
                class="mt-8"
                icon="magnifying-glass"
                :title="__('No articles match this view')"
                :description="__('Try a broader search, a different topic, or clear the filters to see everything published.')"
            >
                <flux:button wire:click="clearFilters" icon="arrow-path">
                    {{ __('Clear filters') }}
                </flux:button>
            </x-app.ui.empty-state>
        @endif
    </section>
</div>
