<?php

use App\Enums\AiResourceKind;
use App\Models\AiResource;
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
 * The hub's resource index.
 *
 * These are the only rows in the hub that point outside the application, which
 * is why they are rows at all rather than links buried in article bodies: a
 * third-party page can move without the hub being told, and a shelf of discrete
 * entries can be reviewed, re-labelled, hidden, or archived in one place.
 *
 *   ?q=           free-text search over title, description and provider
 *   ?topic=       topic slug
 *   ?kind=        tool | library | dataset | course | paper | video | documentation
 *   ?sort=        featured | newest | title
 *   ?page=        pagination
 *
 * There is no detail page behind a resource card. The card *is* the content — an
 * external link with a label, a kind, and the host it actually leads to — so a
 * hub page that wrapped it would only add a click between the reader and the
 * thing they came for.
 */
new #[Title('Resources — AI Knowledge Hub')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'topic', except: '')]
    public string $topicSlug = '';

    #[Url(as: 'kind', except: '')]
    public string $kind = '';

    #[Url(except: 'featured')]
    public string $sort = 'featured';

    public function mount(): void
    {
        $this->syncMetadata();
    }

    /**
     * Reset to page 1 whenever a filter changes, and refresh the metadata so a
     * shared link describes the shelf it actually points at.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'topicSlug', 'kind', 'sort'], true)) {
            $this->resetPage();
            $this->syncMetadata();
        }
    }

    /**
     * Clear every filter at once.
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'topicSlug', 'kind', 'sort');
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
     * Apply a kind filter, toggling it off when it is already active.
     */
    public function toggleKind(string $value): void
    {
        $this->kind = $this->kind === $value ? '' : $value;
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
            || $this->kind !== ''
            || $this->sort !== 'featured';
    }

    /**
     * Topics that actually have a published resource, for the chip row.
     *
     * A chip that filters to nothing would be a control that does nothing, so
     * this row is derived from the data — unlike the article index, where every
     * difficulty tier is offered whether or not anything is published at it.
     *
     * @return Collection<int, AiTopic>
     */
    #[Computed]
    public function topics(): Collection
    {
        return AiTopic::query()
            ->active()
            ->whereHas('resources', fn (Builder $query) => $query->published())
            ->ordered()
            ->withCount(['resources' => fn (Builder $query) => $query->published()])
            ->get();
    }

    /**
     * The kinds that actually have a published resource, with their counts.
     *
     * @return Collection<int, array{value: string, label: string, count: int}>
     */
    #[Computed]
    public function kinds(): Collection
    {
        $counts = $this->baseQuery()
            ->select('kind')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('kind')
            ->pluck('aggregate', 'kind');

        return collect(AiResourceKind::cases())
            ->map(fn (AiResourceKind $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'count' => (int) ($counts[$case->value] ?? 0),
            ])
            ->filter(fn (array $kind): bool => $kind['count'] > 0)
            ->values();
    }

    /**
     * The filtered page of published resources.
     *
     * @return LengthAwarePaginator<int, AiResource>
     */
    #[Computed]
    public function resources(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with('topic')
            ->paginate((int) config('ai_hub.per_page.resources'))
            ->withQueryString();
    }

    /**
     * The resource count for the current filter, independent of pagination.
     */
    #[Computed]
    public function total(): int
    {
        return $this->filteredQuery()->count();
    }

    /**
     * The public rows, before the index's own filters are layered on.
     *
     * @return Builder<AiResource>
     */
    protected function baseQuery(): Builder
    {
        return AiResource::query()->published();
    }

    /**
     * The list query with every active filter applied.
     *
     * One definition, used by the grid, the count, and the kind tallies, so the
     * three cannot disagree about what the current view contains.
     *
     * @return Builder<AiResource>
     */
    protected function filteredQuery(): Builder
    {
        $query = $this->baseQuery()
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when($this->topicSlug !== '', fn (Builder $query) => $query->inTopic($this->topicSlug))
            ->when(AiResourceKind::tryFrom($this->kind) !== null, fn (Builder $query) => $query->ofKind(AiResourceKind::from($this->kind)));

        $this->applySort($query);

        return $query;
    }

    /**
     * Translate the `sort` query parameter into an ORDER BY.
     *
     * The default is the operator's own ordering, which is what the console's
     * reorder control writes to `sort_order` — ignoring it would make that
     * control look like it does nothing.
     */
    protected function applySort(Builder $query): void
    {
        match ($this->sort) {
            'newest' => $query->orderByDesc('published_at')->orderBy('id'),
            'title' => $query->orderBy('title'),
            default => $query->ordered(),
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
                ? __(':topic — Resources', ['topic' => $topic->name])
                : 'Resources — AI Knowledge Hub',
            'description' => $topic instanceof AiTopic
                ? ($topic->description ?? __('Tools, libraries, datasets, courses and papers an editor kept for :topic.', ['topic' => $topic->name]))
                : __('External tools, libraries, datasets, courses, papers and documentation curated by the AI Knowledge Hub, filed by topic and kind.'),
        ]);
    }
}; ?>

@php
    $sortOptions = [
        'featured' => __('Curator order'),
        'newest' => __('Newest'),
        'title' => __('Title A–Z'),
    ];

    $selectClasses = 'focus-ring min-h-10 appearance-none rounded-lg border border-ink-200 bg-white py-2 pl-3 pr-9 text-sm text-ink-700 transition-colors hover:border-ink-300 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-200 dark:hover:border-ink-700';
@endphp

<div data-test="ai-hub-resources">
    {{-- ================================================================== --
     | 01 — Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-12 lg:py-16">
            <x-app.ui.section-marker :number="1" label="Resources" accent />

            <nav aria-label="{{ __('Breadcrumb') }}" class="mt-8">
                <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                    <li>
                        <a href="{{ route('ai-hub.index') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('AI Knowledge Hub') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li class="text-ink-600 dark:text-ink-300">{{ __('Resources') }}</li>
                </ol>
            </nav>

            <h1 class="mt-6 max-w-3xl text-[clamp(2rem,4.5vw,3.25rem)] font-semibold leading-[1.05] tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('What the hub keeps open') }}
            </h1>

            <p class="mt-5 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                {{ __('Tools, libraries, datasets, courses, papers and documentation an editor found worth keeping. Every card leaves the hub, and every card shows where it lands.') }}
            </p>

            <div class="mt-8 max-w-xl">
                <label for="ai-hub-resources-search" class="sr-only">{{ __('Search resources') }}</label>
                <div class="relative">
                    <span aria-hidden="true" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-400 dark:text-ink-500">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="ai-hub-resources-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="{{ __('Search names, descriptions and providers') }}"
                        class="focus-ring min-h-12 w-full rounded-full border border-ink-200 bg-white py-3 pl-11 pr-4 text-sm text-ink-900 placeholder:text-ink-400 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-50 dark:placeholder:text-ink-500"
                    >
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Filters
     --}}
    @if ($this->topics->isNotEmpty() || $this->kinds->isNotEmpty())
        <section class="border-b border-ink-200 py-8 dark:border-ink-800" aria-labelledby="hub-resource-filters-heading">
            <div class="shell">
                <h2 id="hub-resource-filters-heading" class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
                    {{ __('Narrow the shelf') }}
                </h2>

                @if ($this->kinds->isNotEmpty())
                    <ul class="mt-4 flex snap-x gap-2 overflow-x-auto pb-2" role="list">
                        @foreach ($this->kinds as $kindOption)
                            <li class="shrink-0">
                                <button
                                    type="button"
                                    wire:click="toggleKind('{{ $kindOption['value'] }}')"
                                    @class([
                                        'focus-ring inline-flex min-h-10 items-center gap-2 rounded-full border px-4 py-2 text-sm transition-colors',
                                        'border-signal-600 bg-signal-600 text-white dark:border-signal-500 dark:bg-signal-500 dark:text-ink-950' => $this->kind === $kindOption['value'],
                                        'border-ink-200 bg-white text-ink-600 hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300 dark:hover:border-signal-600 dark:hover:text-signal-400' => $this->kind !== $kindOption['value'],
                                    ])
                                    @if ($this->kind === $kindOption['value']) aria-pressed="true" @endif
                                >
                                    {{ $kindOption['label'] }}
                                    <span class="text-2xs tabular-nums opacity-70">{{ $kindOption['count'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($this->topics->isNotEmpty())
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
                                    @if ($topic->resources_count > 0)
                                        <span class="text-2xs tabular-nums opacity-70">{{ $topic->resources_count }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 03 — The list
     --}}
    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="hub-resources-list-heading">
        <x-app.ui.section-marker :number="2" label="The shelf" />

        <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
            <div class="min-w-0">
                <h2 id="hub-resources-list-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    @php $activeTopic = $this->topicSlug === '' ? null : $this->topics->firstWhere('slug', $this->topicSlug); @endphp
                    {{ $activeTopic?->name ?? __('Every resource') }}
                </h2>

                <p class="mt-3 max-w-xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ $activeTopic?->description ?? __('Resources are links the hub does not own. An editor can hide or archive one the moment its destination stops being worth the visit.') }}
                </p>
            </div>

            <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count resource|:count resources', $this->total) }}
            </p>
        </div>

        {{-- Toolbar. Native select, styled once: a custom listbox is more
             JavaScript than a sort needs, and native selects are already
             keyboard- and screen-reader-native. --}}
        <div class="mt-8 flex flex-wrap items-end gap-3 border-y border-ink-200 py-4 dark:border-ink-800">
            <div>
                <label for="hub-resources-sort" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Order') }}
                </label>
                <div class="relative">
                    <select id="hub-resources-sort" wire:model.live="sort" class="{{ $selectClasses }}">
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

        @if ($this->resources->isNotEmpty())
            <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                @foreach ($this->resources as $resource)
                    <x-app.ai-hub.resource-card :resource="$resource" wire:key="resource-{{ $resource->id }}" />
                @endforeach
            </div>

            <x-app.ui.pagination :paginator="$this->resources" label="{{ __('Resources pagination') }}" class="mt-10" />
        @else
            <x-app.ui.empty-state
                class="mt-8"
                icon="globe-alt"
                :title="__('No resources match this view')"
                :description="__('Try a broader search, a different kind, or clear the filters to see the whole shelf.')"
            >
                <flux:button wire:click="clearFilters" icon="arrow-path">
                    {{ __('Clear filters') }}
                </flux:button>
            </x-app.ui.empty-state>
        @endif
    </section>
</div>
