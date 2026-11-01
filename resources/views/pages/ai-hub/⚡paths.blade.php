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
use Livewire\Attributes\Layout;
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
new #[Title('Learning Paths — AI Knowledge Hub')] #[Layout('layouts::feed')] class extends Component
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
@endphp

<x-log.module
    active="ai-hub"
    data-test="ai-hub-paths"
    :title="__('Learning paths')"
    :meta="trans_choice(':count path|:count paths', $this->total, ['count' => $this->total])"
    :note="__('A path is a sequence of published articles with an estimated cost in minutes. The order is the point — lessons stay where the operator put them.')"
>
    <x-slot:filters>
        <div class="dn-module-tools">
            <div class="dn-market-sort">
                <label for="ai-hub-path-sort" class="dn-field-key">{{ __('sort') }}</label>
                <select id="ai-hub-path-sort" wire:model.live="sort" class="dn-input" style="width: auto;">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="dn-market-sort">
                <label for="ai-hub-path-difficulty" class="dn-field-key">{{ __('difficulty') }}</label>
                <select id="ai-hub-path-difficulty" wire:model.live="difficulty" class="dn-input" style="width: auto;">
                    <option value="">{{ __('Any difficulty') }}</option>
                    @foreach ($difficultyOptions as $value => $label)
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

    @if ($this->paths->isEmpty())
        <div class="dn-module-empty" data-test="ai-hub-paths-empty">
            <p class="dn-empty-command">fatal: no paths match those filters</p>
            <p class="dn-empty-text">
                {{ __('Nothing in the hub matches this view. Loosen a filter or clear them all.') }}
            </p>
            <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="clearFilters" style="margin-top: 16px;">
                {{ __('Reset filters') }}
            </button>
        </div>
    @else
        <div class="dn-entries" wire:loading.class="is-loading" wire:target="sort, difficulty, topicSlug">
            @foreach ($this->paths as $path)
                <x-log.entry
                    :href="route('ai-hub.path', ['path' => $path->slug])"
                    :title="$path->title"
                    :eyebrow="$path->difficulty->label()"
                    :description="$path->description"
                    :meta="[
                        trans_choice(':count lesson|:count lessons', $path->lessonCount(), ['count' => $path->lessonCount()]),
                        $path->estimatedMinutesLabel(),
                        $path->topic?->name,
                    ]"
                    :wire:key="'hub-path-'.$path->id"
                />
            @endforeach
        </div>

        <x-app.ui.pagination :paginator="$this->paths" label="{{ __('AI hub path pagination') }}" class="dn-pagination" />
    @endif
</x-log.module>
