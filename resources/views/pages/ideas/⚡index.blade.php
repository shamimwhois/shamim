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
use Livewire\Attributes\Layout;
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
new #[Title('Ideas')] #[Layout('layouts::feed')] class extends Component
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
    $activeAuthorName = $this->activeAuthor === null ? null : $this->activeAuthor['name'];
@endphp

<x-log.module
    active="ideas"
    data-test="ideas-index"
    :title="$this->activeTopic?->name ?? $activeAuthorName ?? __('Ideas')"
    :meta="trans_choice(':count idea|:count ideas', $this->total, ['count' => $this->total])"
    :note="__('An idea is not a product announcement. It is something somebody wrote down before it slipped away, then approved and published. Most of them are unfinished on purpose.')"
>
    <x-slot:filters>
        <div class="dn-module-tools">
            <div class="dn-search-field">
                <x-feed.icon name="search" :size="16" />
                <label for="ideas-search" class="sr-only">{{ __('Search ideas') }}</label>
                <input
                    id="ideas-search"
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="{{ __('Search titles, summaries and notes') }}"
                    autocomplete="off"
                />
            </div>

            <div class="dn-market-sort">
                <label for="ideas-sort" class="dn-field-key">{{ __('sort') }}</label>
                <select id="ideas-sort" wire:model.live="sort" class="dn-input" style="width: auto;">
                    @foreach ($this->sorts() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if ($this->authors->isNotEmpty())
                <div class="dn-market-sort">
                    <label for="ideas-author" class="dn-field-key">{{ __('author') }}</label>
                    <select id="ideas-author" wire:model.live="authorHandle" class="dn-input" style="width: auto;">
                        <option value="">{{ __('Anyone') }}</option>
                        @foreach ($this->authors as $author)
                            <option value="{{ $author['handle'] }}">
                                {{ $author['name'] }} ({{ $author['count'] }})
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($this->hasActiveFilters)
                <button type="button" class="dn-btn dn-btn-ghost dn-btn-sm" wire:click="clearFilters">
                    {{ __('Reset filters') }}
                </button>
            @endif
        </div>

        {{-- The filters that are actually applied, echoed back so a reader can
             see what to undo. A hand-typed subject that resolves to nothing is
             printed raw, because hiding it would leave the list empty with no
             explanation. --}}
        @if ($topicSlug !== '' || $authorHandle !== '' || $search !== '')
            <div class="dn-module-tools" style="padding-top: 0;" data-test="active-filters">
                <span class="dn-field-key">{{ __('applied') }}</span>

                @if ($topicSlug !== '')
                    <span class="dn-filter-chip">
                        <span class="dn-field-key">{{ __('subject') }}</span>
                        <span>{{ $this->activeTopic?->name ?? $topicSlug }}</span>
                        <button type="button" wire:click="toggleTopic('{{ $topicSlug }}')" aria-label="{{ __('Clear the subject filter') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </span>
                @endif

                @if ($authorHandle !== '')
                    <span class="dn-filter-chip">
                        <span class="dn-field-key">{{ __('author') }}</span>
                        <span>{{ $activeAuthorName ?? $authorHandle }}</span>
                        <button type="button" wire:click="$set('authorHandle', '')" aria-label="{{ __('Clear the author filter') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </span>
                @endif

                @if ($search !== '')
                    <span class="dn-filter-chip">
                        <span class="dn-field-key">{{ __('query') }}</span>
                        <span>{{ $search }}</span>
                        <button type="button" wire:click="$set('search', '')" aria-label="{{ __('Clear the search term') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </span>
                @endif
            </div>
        @endif

        <div class="dn-module-tools" style="padding-top: 0;">
            <div class="dn-filters" style="border-bottom: 0; padding: 0;">
                @foreach ($this->tabs() as $key => $label)
                    <button
                        type="button"
                        class="dn-filter {{ $tab === $key ? 'is-active' : '' }}"
                        wire:click="$set('tab', '{{ $key }}')"
                        @if ($tab === $key) aria-current="true" @endif
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>

        @if ($this->topics->isNotEmpty())
            <div class="dn-module-tools" style="padding-top: 0;">
                <span class="dn-field-key">{{ __('subjects') }}</span>

                @foreach ($this->topics as $topic)
                    <button
                        type="button"
                        class="dn-filter {{ $topicSlug === $topic->slug ? 'is-active' : '' }}"
                        wire:click="toggleTopic('{{ $topic->slug }}')"
                        @if ($topicSlug === $topic->slug) aria-pressed="true" @endif
                        style="padding: 2px 6px;"
                    >
                        {{ $topic->name }}
                        @if ($topic->ideas_count > 0)
                            <span class="dn-filter-count">{{ $topic->ideas_count }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif
    </x-slot:filters>

    @if ($this->search !== '' && ! $this->isSearching())
        <p class="dn-module-note" role="status">
            {{ __('Type at least two characters to search — what is listed below is still everything published.') }}
        </p>
    @endif

    @if ($this->ideas->isEmpty())
        <div class="dn-module-empty" data-test="ideas-empty">
            <p class="dn-empty-command">
                fatal: {{ $this->hasActiveFilters ? __('no idea matches this view') : __('nothing has been published yet') }}
            </p>
            <p class="dn-empty-text">
                @if ($this->hasActiveFilters)
                    {{ __('Search matches titles, summaries and the body of an idea. Try a shorter phrase, drop the subject, or reset the filters to see everything published.') }}
                @else
                    {{ __('Ideas only appear here once their author approves them and chooses to publish. Try again later, or keep your own in the workspace.') }}
                @endif
            </p>

            @if ($this->hasActiveFilters)
                <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="clearFilters" style="margin-top: 16px;">
                    {{ __('Reset filters') }}
                </button>
            @elseif (auth()->check())
                <a href="{{ route('ideas.dashboard') }}" wire:navigate class="dn-btn dn-btn-outline dn-btn-sm" style="margin-top: 16px;">
                    {{ __('Capture your own') }}
                </a>
            @endif
        </div>
    @else
        <div class="dn-entries" wire:loading.class="is-loading" wire:target="search, tab, topicSlug, authorHandle, sort">
            @foreach ($this->ideas as $idea)
                <x-log.entry
                    :href="route('ideas.show', ['idea' => $idea->slug])"
                    :title="$idea->title"
                    {{-- The folder is one account's private filing system, not a
                         fact about the published idea: it renders for its owner
                         and nobody else, which is the card's own rule. --}}
                    :eyebrow="auth()->id() !== null && auth()->id() === (int) $idea->user_id ? $idea->category?->name : null"
                    :description="$idea->excerpt()"
                    :badge="$idea->is_pinned ? __('pinned') : null"
                    :meta="[
                        $idea->published_at?->translatedFormat('M j, Y'),
                        trans_choice(':count view|:count views', (int) $idea->view_count, ['count' => (int) $idea->view_count]),
                        $idea->user?->name,
                    ]"
                    :wire:key="'idea-'.$idea->id"
                />
            @endforeach
        </div>

        <x-app.ui.pagination :paginator="$this->ideas" label="{{ __('Ideas pagination') }}" class="dn-pagination" />
    @endif
</x-log.module>
