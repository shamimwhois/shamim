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
use Livewire\Attributes\Layout;
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
new #[Title('Resources — AI Knowledge Hub')] #[Layout('layouts::feed')] class extends Component
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
@endphp

<x-log.module
    active="ai-hub"
    data-test="ai-hub-resources"
    :title="__('Resources')"
    :meta="trans_choice(':count resource|:count resources', $this->total, ['count' => $this->total])"
    :note="__('Tools, libraries, datasets, papers and courses the articles here depend on. These links leave the site.')"
>
    <x-slot:filters>
        <div class="dn-module-tools">
            <div class="dn-search-field">
                <x-feed.icon name="search" :size="16" />
                <label for="ai-hub-resource-search" class="sr-only">{{ __('Search resources') }}</label>
                <input
                    id="ai-hub-resource-search"
                    type="search"
                    wire:model.live.debounce.350ms="search"
                    placeholder="{{ __('Search resources…') }}"
                    autocomplete="off"
                />
            </div>

            <div class="dn-market-sort">
                <label for="ai-hub-resource-sort" class="dn-field-key">{{ __('sort') }}</label>
                <select id="ai-hub-resource-sort" wire:model.live="sort" class="dn-input" style="width: auto;">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if ($this->hasActiveFilters)
                <button type="button" class="dn-btn dn-btn-ghost dn-btn-sm" wire:click="clearFilters">
                    {{ __('Clear filters') }}
                </button>
            @endif
        </div>

        @if ($this->kinds->isNotEmpty())
            <div class="dn-module-tools" style="padding-top: 0;">
                <span class="dn-field-key">{{ __('kind') }}</span>

                @foreach ($this->kinds as $kindOption)
                    <button
                        type="button"
                        class="dn-filter {{ $kind === $kindOption['value'] ? 'is-active' : '' }}"
                        wire:click="toggleKind('{{ $kindOption['value'] }}')"
                        style="padding: 2px 6px;"
                    >{{ $kindOption['label'] }} <span class="dn-filter-count">{{ $kindOption['count'] }}</span></button>
                @endforeach
            </div>
        @endif

        @if ($this->topics->isNotEmpty())
            <div class="dn-module-tools" style="padding-top: 0;">
                <span class="dn-field-key">{{ __('topics') }}</span>

                @foreach ($this->topics as $topic)
                    <button
                        type="button"
                        class="dn-filter {{ $topicSlug === $topic->slug ? 'is-active' : '' }}"
                        wire:click="toggleTopic('{{ $topic->slug }}')"
                        style="padding: 2px 6px;"
                    >{{ $topic->name }} <span class="dn-filter-count">{{ $topic->articles_count }}</span></button>
                @endforeach
            </div>
        @endif
    </x-slot:filters>

    @if ($this->resources->isEmpty())
        <div class="dn-module-empty" data-test="ai-hub-resources-empty">
            <p class="dn-empty-command">fatal: no resources match those filters</p>
            <p class="dn-empty-text">
                {{ __('Nothing in the hub matches this view. Loosen a filter or clear them all.') }}
            </p>
            <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="clearFilters" style="margin-top: 16px;">
                {{ __('Reset filters') }}
            </button>
        </div>
    @else
        <div class="dn-entries" wire:loading.class="is-loading" wire:target="search, sort, kind, topicSlug">
            @foreach ($this->resources as $resource)
                <x-log.entry
                    :href="$resource->url"
                    :title="$resource->title"
                    :eyebrow="$resource->kind->label()"
                    :description="$resource->description"
                    :meta="[$resource->provider, $resource->host()]"
                    :wire:key="'hub-resource-'.$resource->id"
                />
            @endforeach
        </div>

        <x-app.ui.pagination :paginator="$this->resources" label="{{ __('AI hub resource pagination') }}" class="dn-pagination" />
    @endif
</x-log.module>
