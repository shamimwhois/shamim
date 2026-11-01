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
use Livewire\Attributes\Layout;
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
new #[Title('Articles — AI Knowledge Hub')] #[Layout('layouts::feed')] class extends Component
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
@endphp

<x-log.module
    active="ai-hub"
    data-test="ai-hub-articles"
    :title="__('Articles')"
    :meta="trans_choice(':count article|:count articles', $this->total, ['count' => $this->total])"
    :note="__('Published articles, tutorials, technical notes and code examples. Filter by topic, difficulty or format.')"
>
    <x-slot:filters>
        <div class="dn-module-tools">
            <div class="dn-search-field">
                <x-feed.icon name="search" :size="16" />
                <label for="ai-hub-article-search" class="sr-only">{{ __('Search articles') }}</label>
                <input
                    id="ai-hub-article-search"
                    type="search"
                    wire:model.live.debounce.350ms="search"
                    placeholder="{{ __('Search articles…') }}"
                    autocomplete="off"
                />
            </div>

            <div class="dn-market-sort">
                <label for="ai-hub-article-sort" class="dn-field-key">{{ __('sort') }}</label>
                <select id="ai-hub-article-sort" wire:model.live="sort" class="dn-input" style="width: auto;">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="dn-market-sort">
                <label for="ai-hub-article-difficulty" class="dn-field-key">{{ __('difficulty') }}</label>
                <select id="ai-hub-article-difficulty" wire:model.live="difficulty" class="dn-input" style="width: auto;">
                    <option value="">{{ __('Any difficulty') }}</option>
                    @foreach ($difficultyOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="dn-market-sort">
                <label for="ai-hub-article-format" class="dn-field-key">{{ __('format') }}</label>
                <select id="ai-hub-article-format" wire:model.live="format" class="dn-input" style="width: auto;">
                    <option value="">{{ __('Any format') }}</option>
                    @foreach ($formatOptions as $value => $label)
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

    @if ($this->articles->isEmpty())
        <div class="dn-module-empty" data-test="ai-hub-articles-empty">
            <p class="dn-empty-command">fatal: no articles match those filters</p>
            <p class="dn-empty-text">
                {{ __('Nothing in the hub matches this view. Loosen a filter or clear them all.') }}
            </p>
            <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="clearFilters" style="margin-top: 16px;">
                {{ __('Reset filters') }}
            </button>
        </div>
    @else
        <div class="dn-entries" wire:loading.class="is-loading" wire:target="search, sort, difficulty, format, topicSlug">
            @foreach ($this->articles as $article)
                <x-log.entry
                    :href="route('ai-hub.article', ['article' => $article->slug])"
                    :title="$article->title"
                    :eyebrow="$article->format->label()"
                    :description="$article->excerpt((int) config('ai_hub.article.excerpt_length'))"
                    :badge="$article->difficulty->label()"
                    :meta="[
                        $article->readingTimeLabel(),
                        $article->published_at?->translatedFormat('M j, Y'),
                        $article->user?->name,
                    ]"
                    :wire:key="'hub-article-'.$article->id"
                />
            @endforeach
        </div>

        <x-app.ui.pagination :paginator="$this->articles" label="{{ __('AI hub article pagination') }}" class="dn-pagination" />
    @endif
</x-log.module>
