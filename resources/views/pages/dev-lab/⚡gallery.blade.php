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
use Livewire\Attributes\Layout;
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
new #[Title('Snippet gallery')] #[Layout('layouts::feed')] class extends Component
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
    $activeAuthorName = $this->activeAuthor === null ? null : $this->activeAuthor['name'];
@endphp

<x-log.module
    active="dev-lab"
    data-test="dev-lab-gallery"
    :title="__('Gallery')"
    :meta="trans_choice(':count snippet|:count snippets', $this->snippets->total(), ['count' => $this->snippets->total()])"
    :note="__('A snippet is somebody’s working HTML, CSS, JavaScript or PHP, saved with a name and a note. Runnable ones offer a preview in a frame that cannot reach this site.')"
>
    <x-slot:filters>
        <div class="dn-module-tools">
            <div class="dn-search-field">
                <x-feed.icon name="search" :size="16" />
                <label for="gallery-search" class="sr-only">{{ __('Search snippets') }}</label>
                <input
                    id="gallery-search"
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="{{ __('Search titles, notes and the code itself') }}"
                    autocomplete="off"
                />
            </div>

            <div class="dn-market-sort">
                <label for="gallery-sort" class="dn-field-key">{{ __('sort') }}</label>
                <select id="gallery-sort" wire:model.live="sort" class="dn-input" style="width: auto;">
                    @foreach ($this->sorts() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if ($this->authors->isNotEmpty())
                <div class="dn-market-sort">
                    <label for="gallery-author" class="dn-field-key">{{ __('author') }}</label>
                    <select id="gallery-author" wire:model.live="authorHandle" class="dn-input" style="width: auto;">
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

        {{-- The language row. Only a language somebody has published in gets a
             chip, so a chip can never open an empty list. A hand-typed language
             is echoed below instead. --}}
        <div class="dn-module-tools" style="padding-top: 0;">
            <span class="dn-field-key">{{ __('language') }}</span>

            <button
                type="button"
                class="dn-filter {{ $language === '' ? 'is-active' : '' }}"
                wire:click="$set('language', '')"
                @if ($language === '') aria-current="true" @endif
            >{{ __('Everything') }}</button>

            @foreach (LabLanguage::cases() as $case)
                @continue(($this->languageCounts[$case->value] ?? 0) === 0)

                <button
                    type="button"
                    wire:key="gallery-lang-{{ $case->value }}"
                    aria-selected="{{ $language === $case->value ? 'true' : 'false' }}"
                    wire:click="$set('language', '{{ $case->value }}')"
                    data-test="language-chip-{{ $case->value }}"
                    class="dn-filter {{ $language === $case->value ? 'is-active' : '' }}"
                    style="padding: 2px 6px;"
                >
                    {{ $case->label() }}
                    <span class="dn-filter-count">{{ $this->languageCounts[$case->value] }}</span>
                    @unless ($case->isBrowserRunnable())
                        <span class="dn-filter-count">{{ __('stored') }}</span>
                    @endunless
                </button>
            @endforeach
        </div>

        {{-- The filters that are actually applied, echoed back so a reader can
             see what to undo and take it off. --}}
        @if ($language !== '' || $this->topicSlug !== '' || $this->authorHandle !== '' || $this->search !== '')
            <div class="dn-module-tools" style="padding-top: 0;" data-test="active-filters">
                <span class="dn-field-key">{{ __('applied') }}</span>

                @if ($language !== '')
                    <span class="dn-filter-chip">
                        <span class="dn-field-key">{{ __('language') }}</span>
                        <span>{{ LabLanguage::tryFrom($language)?->label() ?? $language }}</span>
                        <button type="button" wire:click="$set('language', '')" aria-label="{{ __('Clear the language filter') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </span>
                @endif

                @if ($this->topicSlug !== '')
                    <span class="dn-filter-chip">
                        <span class="dn-field-key">{{ __('subject') }}</span>
                        <span>{{ $this->activeTopic?->name ?? $this->topicSlug }}</span>
                        <button type="button" wire:click="toggleTopic('{{ $this->topicSlug }}')" aria-label="{{ __('Clear the subject filter') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </span>
                @endif

                @if ($this->authorHandle !== '')
                    <span class="dn-filter-chip">
                        <span class="dn-field-key">{{ __('author') }}</span>
                        <span>{{ $activeAuthorName ?? $this->authorHandle }}</span>
                        <button type="button" wire:click="$set('authorHandle', '')" aria-label="{{ __('Clear the author filter') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </span>
                @endif

                @if ($this->search !== '')
                    <span class="dn-filter-chip">
                        <span class="dn-field-key">{{ __('query') }}</span>
                        <span>{{ $this->search }}</span>
                        <button type="button" wire:click="$set('search', '')" aria-label="{{ __('Clear the search term') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </span>
                @endif
            </div>
        @endif

        @if ($this->topics->isNotEmpty())
            <div class="dn-module-tools" style="padding-top: 0;">
                <span class="dn-field-key">{{ __('subjects') }}</span>

                @foreach ($this->topics as $topic)
                    <button
                        type="button"
                        class="dn-filter {{ $this->topicSlug === $topic->slug ? 'is-active' : '' }}"
                        wire:click="toggleTopic('{{ $topic->slug }}')"
                        @if ($this->topicSlug === $topic->slug) aria-pressed="true" @endif
                        style="padding: 2px 6px;"
                    >{{ $topic->name }}</button>
                @endforeach
            </div>
        @endif
    </x-slot:filters>

    @if ($this->search !== '' && ! $this->isSearching())
        <p class="dn-module-note" role="status">
            {{ __('Type at least two characters to search — what is listed below is still everything published.') }}
        </p>
    @endif

    {{-- The skeleton is shown only while a filter request is in flight. --}}
    <div wire:loading.delay.shorter wire:target="search, language, topicSlug, authorHandle, sort" aria-hidden="true" data-test="gallery-skeleton">
        <div class="dn-entries">
            @foreach (range(1, 6) as $slot)
                <div class="dn-entry">
                    <span class="dn-entry-glyph" aria-hidden="true">&nbsp;</span>
                    <span class="dn-entry-main">
                        <span class="dn-entry-title">&nbsp;</span>
                        <span class="dn-entry-desc">&nbsp;</span>
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    <div wire:loading.remove wire:target="search, language, topicSlug, authorHandle, sort" data-test="gallery-results">
        @if ($this->snippets->isNotEmpty())
            <div class="dn-entries">
                @foreach ($this->snippets as $snippet)
                    <x-log.entry
                        :href="route('dev-lab.snippets.show', ['snippet' => $snippet->slug])"
                        :title="$snippet->title"
                        :eyebrow="$snippet->language->label()"
                        :description="$snippet->excerpt()"
                        :badge="$snippet->language->isBrowserRunnable() ? null : __('No run button')"
                        :meta="[
                            $snippet->user?->name ?? __('Someone'),
                            $snippet->published_at?->translatedFormat('j M Y'),
                            trans_choice(':count line|:count lines', $snippet->lineCount(), ['count' => $snippet->lineCount()]),
                        ]"
                        :wire:key="'gallery-snippet-'.$snippet->id"
                    />
                @endforeach
            </div>

            <x-app.ui.pagination :paginator="$this->snippets" label="{{ __('Snippets pagination') }}" class="dn-pagination" />
        @else
            <div class="dn-module-empty">
                <p class="dn-empty-command">
                    fatal: {{ $this->hasActiveFilters ? __('nothing matches this view') : __('nobody has published a snippet yet') }}
                </p>
                <p class="dn-empty-text">
                    @if ($this->hasActiveFilters)
                        {{ __('Search runs over the saved code as plain text. Try a shorter phrase, drop the language or subject, or reset the filters to see everything published.') }}
                    @else
                        {{ __('Snippets appear here when their author turns on visibility. Keep your own in the sandbox; it is the same editor with a Save button.') }}
                    @endif
                </p>

                @if ($this->hasActiveFilters)
                    <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="clearFilters" style="margin-top: 16px;">
                        {{ __('Reset filters') }}
                    </button>
                @elseif (auth()->check())
                    <a href="{{ route('dev-lab.snippets.create') }}" wire:navigate class="dn-btn dn-btn-outline dn-btn-sm" style="margin-top: 16px;">
                        {{ __('Save your own') }}
                    </a>
                @endif
            </div>
        @endif
    </div>
</x-log.module>
