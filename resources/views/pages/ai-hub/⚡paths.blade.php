<?php

use App\Enums\AiDifficulty;
use App\Models\AiLearningPath;
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
 * The hub's learning-path index.
 *
 * A path is a sequence rather than a pile, so this page's job is to make the
 * shape of each route legible before a reader commits to it: how many lessons,
 * how many minutes, which level. All three come from the model, which sums
 * reading time in the database instead of multiplying a guess by a count.
 *
 *   ?topic=       topic slug
 *   ?difficulty=  beginner | intermediate | advanced | expert
 *   ?sort=        featured | newest | title
 *   ?page=        pagination
 *
 * Difficulty is a string column, so ordering the ladder by it would sort it
 * alphabetically. The level is a filter here and never a sort.
 */
new #[Title('Learning Paths — AI Knowledge Hub')] class extends Component
{
    use WithPagination;

    #[Url(as: 'topic', except: '')]
    public string $topicSlug = '';

    #[Url(as: 'difficulty', except: '')]
    public string $difficulty = '';

    #[Url(except: 'featured')]
    public string $sort = 'featured';

    public function mount(): void
    {
        $this->syncMetadata();
    }

    /**
     * Reset to page 1 whenever a filter changes, and refresh the metadata so a
     * shared link describes the routes it actually lists.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['topicSlug', 'difficulty', 'sort'], true)) {
            $this->resetPage();
            $this->syncMetadata();
        }
    }

    /**
     * Clear every filter at once.
     */
    public function clearFilters(): void
    {
        $this->reset('topicSlug', 'difficulty', 'sort');
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
        return $this->topicSlug !== ''
            || $this->difficulty !== ''
            || $this->sort !== 'featured';
    }

    /**
     * Topics that have a published path, for the chip row.
     *
     * Unlike the article index, this row is derived from the data. A path can
     * only be published when every lesson in it is published, so a topic with no
     * paths is genuinely empty rather than waiting for content at a level nobody
     * has written yet — and an always-visible chip that lists nothing would be a
     * control that does nothing.
     *
     * @return Collection<int, AiTopic>
     */
    #[Computed]
    public function topics(): Collection
    {
        return AiTopic::query()
            ->active()
            ->whereHas('learningPaths', fn (Builder $query) => $query->published())
            ->ordered()
            ->withCount(['learningPaths' => fn (Builder $query) => $query->published()])
            ->get();
    }

    /**
     * The filtered page of published paths.
     *
     * @return LengthAwarePaginator<int, AiLearningPath>
     */
    #[Computed]
    public function paths(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with('topic')
            ->withCount('items')
            ->paginate((int) config('ai_hub.per_page.paths'))
            ->withQueryString();
    }

    /**
     * The path count for the current filter, independent of pagination.
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
     * @return Builder<AiLearningPath>
     */
    protected function filteredQuery(): Builder
    {
        $query = AiLearningPath::query()
            ->published()
            ->when($this->topicSlug !== '', fn (Builder $query) => $query->inTopic($this->topicSlug))
            ->when(AiDifficulty::tryFrom($this->difficulty) !== null, fn (Builder $query) => $query->where('difficulty', $this->difficulty));

        $this->applySort($query);

        return $query;
    }

    /**
     * Translate the `sort` query parameter into an ORDER BY.
     */
    protected function applySort(Builder $query): void
    {
        match ($this->sort) {
            'newest' => $query->orderByDesc('published_at')->orderBy('title'),
            'title' => $query->orderBy('title'),
            default => $query->inReadingOrder(),
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
                ? __(':topic — Learning Paths', ['topic' => $topic->name])
                : 'Learning Paths — AI Knowledge Hub',
            'description' => $topic instanceof AiTopic
                ? ($topic->description ?? __('Ordered routes through published articles in the AI Knowledge Hub.'))
                : __('Ordered routes through the AI Knowledge Hub. Each path sequences published articles and states how long the whole route takes.'),
        ]);
    }
}; ?>

@php
    $difficultyOptions = AiDifficulty::options();

    $sortOptions = [
        'featured' => __('Featured'),
        'newest' => __('Newest'),
        'title' => __('Title A–Z'),
    ];

    $selectClasses = 'focus-ring min-h-10 appearance-none rounded-lg border border-ink-200 bg-white py-2 pl-3 pr-9 text-sm text-ink-700 transition-colors hover:border-ink-300 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-200 dark:hover:border-ink-700';
@endphp

<div data-test="ai-hub-paths">
    {{-- ================================================================== --
     | 01 — Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-12 lg:py-16">
            <x-app.ui.section-marker :number="1" label="Learning paths" accent />

            <nav aria-label="{{ __('Breadcrumb') }}" class="mt-8">
                <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                    <li>
                        <a href="{{ route('ai-hub.index') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('AI Knowledge Hub') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li class="text-ink-600 dark:text-ink-300">{{ __('Learning paths') }}</li>
                </ol>
            </nav>

            <h1 class="mt-6 max-w-3xl text-[clamp(2rem,4.5vw,3.25rem)] font-semibold leading-[1.05] tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('Routes, not piles') }}
            </h1>

            <p class="mt-5 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                {{ __('A path holds published articles in the order an editor decided they should be read. The lesson count and the total minutes are stated up front, because a sequence is a commitment.') }}
            </p>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Topic chips
     --}}
    @if ($this->topics->isNotEmpty())
        <section class="border-b border-ink-200 py-8 dark:border-ink-800" aria-labelledby="hub-path-topics-heading">
            <div class="shell">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <h2 id="hub-path-topics-heading" class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
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
                                @if ($topic->learning_paths_count > 0)
                                    <span class="text-2xs tabular-nums opacity-70">{{ $topic->learning_paths_count }}</span>
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
    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="hub-paths-list-heading">
        <x-app.ui.section-marker :number="2" label="The routes" />

        <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
            <div class="min-w-0">
                <h2 id="hub-paths-list-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    @php $activeTopic = $this->topicSlug === '' ? null : $this->topics->firstWhere('slug', $this->topicSlug); @endphp
                    {{ $activeTopic?->name ?? __('Every path') }}
                </h2>

                <p class="mt-3 max-w-xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ $activeTopic?->description ?? __('A path publishes only once every lesson inside it is published, so nothing here can lead a reader into a draft.') }}
                </p>
            </div>

            <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count path|:count paths', $this->total) }}
            </p>
        </div>

        {{-- Toolbar. Native selects, styled once: a custom listbox is more
             JavaScript than a filter needs, and native selects are already
             keyboard- and screen-reader-native. --}}
        <div class="mt-8 flex flex-wrap items-end gap-3 border-y border-ink-200 py-4 dark:border-ink-800">
            <div>
                <label for="hub-paths-difficulty" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Difficulty') }}
                </label>
                <div class="relative">
                    <select id="hub-paths-difficulty" wire:model.live="difficulty" class="{{ $selectClasses }}">
                        <option value="">{{ __('Any level') }}</option>
                        @foreach ($difficultyOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                </div>
            </div>

            <div>
                <label for="hub-paths-sort" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Sort') }}
                </label>
                <div class="relative">
                    <select id="hub-paths-sort" wire:model.live="sort" class="{{ $selectClasses }}">
                        @foreach ($sortOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                </div>
            </div>

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

        @if ($this->paths->isNotEmpty())
            <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->paths as $path)
                    <x-app.ai-hub.path-card :path="$path" wire:key="path-{{ $path->id }}" />
                @endforeach
            </div>

            <x-app.ui.pagination :paginator="$this->paths" label="{{ __('Learning paths pagination') }}" class="mt-10" />
        @else
            <x-app.ui.empty-state
                class="mt-8"
                icon="map"
                :title="__('No paths match this view')"
                :description="__('A path only appears here once an editor has published it and every lesson inside it. Clear the filters to see the whole set.')"
            >
                <flux:button wire:click="clearFilters" icon="arrow-path">
                    {{ __('Clear filters') }}
                </flux:button>
            </x-app.ui.empty-state>
        @endif
    </section>
</div>
