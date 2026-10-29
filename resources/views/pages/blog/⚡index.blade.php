<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The blog index.
 *
 * Four independent filters, all reflected in the URL so any view of the list is
 * shareable and bookmarkable (docs/APP-FLOW.md §3, state model):
 *
 *   ?q=          free-text search across title, excerpt, and body
 *   ?category=   single category slug
 *   ?tag=        single tag slug
 *   ?page=       pagination
 *
 * Search is debounced; category and tag changes apply immediately. Every filter
 * change resets pagination, because page 4 of a new result set is meaningless.
 */
new #[Title('Writing')] #[Layout('layouts::feed')] class extends Component
{
    use WithPagination;

    /**
     * The shortest search term that narrows the list.
     *
     * One character matches a third of the corpus and tells the reader nothing,
     * so the query is not run until there is a word to look for. The same rule
     * the snippet gallery and the AI hub already follow.
     */
    public const MIN_QUERY_LENGTH = 2;

    /**
     * Free-text query. Debounced so typing does not fire a request per keystroke.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $tag = '';

    public function mount(): void
    {
        $this->syncMetadata();
    }

    /**
     * Reset to page 1 whenever a filter changes, and refresh the head metadata
     * so a shared link previews the filter it actually points at.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'category', 'tag'], true)) {
            $this->resetPage();
            $this->syncMetadata();
        }
    }

    /**
     * Clear every filter at once.
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'tag');
        $this->resetPage();
        $this->syncMetadata();
    }

    /**
     * Apply a category filter, toggling it off if it is already active.
     */
    public function toggleCategory(string $slug): void
    {
        $this->category = $this->category === $slug ? '' : $slug;
        $this->resetPage();
        $this->syncMetadata();
    }

    /**
     * Apply a tag filter, toggling it off if it is already active.
     */
    public function toggleTag(string $slug): void
    {
        $this->tag = $this->tag === $slug ? '' : $slug;
        $this->resetPage();
        $this->syncMetadata();
    }

    /**
     * Declare head metadata for the current filter state.
     *
     * Filtered views are treated as canonical only when unfiltered: a search
     * result page has no business competing with the index in a SERP, so those
     * variants are marked noindex while remaining fully linkable.
     */
    protected function syncMetadata(): void
    {
        $title = match (true) {
            $this->search !== '' => 'Search: '.$this->search,
            $this->category !== '' => $this->activeCategory?->name ?? 'Writing',
            $this->tag !== '' => 'Tagged: '.$this->activeTag?->name ?? 'Writing',
            default => 'Writing',
        };

        app(Seo::class)->set([
            'title' => $title,
            'description' => match (true) {
                $this->category !== '' => $this->activeCategory?->description
                    ?? 'Posts filed under '.$this->activeCategory?->name.'.',
                $this->tag !== '' => 'Every post tagged '.$this->activeTag?->name.'.',
                default => 'Long-form notes on backend architecture, database performance, and the parts of shipping software nobody puts on a slide.',
            },
            'canonical' => route('blog.index'),
            // Filtered and paged views are reachable but should not be indexed.
            'noindex' => $this->hasFilters || $this->getPage() > 1,
        ]);
    }

    /**
     * Whether any filter is currently applied.
     */
    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->category !== '' || $this->tag !== '';
    }

    /**
     * Whether the search term is long enough to actually query the index.
     *
     * A term that carries a LIKE wildcard is exempt: it is escaped before
     * interpolation, so it matches literally and cannot match every row.
     * A bare `%` is one character but still a real question — "posts with a
     * percent sign" — and the escaping contract only holds if the query
     * actually runs.
     */
    #[Computed]
    public function isSearching(): bool
    {
        $term = trim($this->search);

        if ($term !== '' && strpbrk($term, '%_') !== false) {
            return true;
        }

        return Str::length($term) >= self::MIN_QUERY_LENGTH;
    }

    /**
     * The paginated result set.
     *
     * Eager loads are declared here because the card component reads `category`,
     * `tags`, and a comment count. `withCount` on a constrained relation keeps
     * the count query honest — only approved comments are counted.
     *
     * @return LengthAwarePaginator<int, Post>
     */
    #[Computed]
    public function posts(): LengthAwarePaginator
    {
        return $this->query()
            ->paginate((int) config('portfolio.blog.per_page', 9))
            ->withQueryString();
    }

    /**
     * Build the filtered query.
     *
     * @return Builder<Post>
     */
    protected function query(): Builder
    {
        return Post::query()
            ->published()
            ->recent()
            ->with(['category', 'tags'])
            ->withCount(['comments' => fn (Builder $query) => $query->approved()])
            ->when($this->isSearching, fn (Builder $query) => $query->search($this->search))
            ->when($this->category !== '', fn (Builder $query) => $query->inCategory($this->category))
            ->when($this->tag !== '', fn (Builder $query) => $query->withTag($this->tag));
    }

    /**
     * Categories with a published post, for the filter rail.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->ordered()
            ->whereHas('publishedPosts')
            ->get();
    }

    /**
     * The most-used tags, for the filter rail.
     *
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()
            ->popular()
            ->whereHas('posts', fn (Builder $query) => $query->published())
            ->limit(20)
            ->get();
    }

    /**
     * The currently applied category model, when one is selected.
     */
    #[Computed]
    public function activeCategory(): ?Category
    {
        return $this->category === ''
            ? null
            : $this->categories->firstWhere('slug', $this->category);
    }

    /**
     * The currently applied tag model, when one is selected.
     */
    #[Computed]
    public function activeTag(): ?Tag
    {
        return $this->tag === ''
            ? null
            : $this->tags->firstWhere('slug', $this->tag);
    }

    /**
     * The heading shown above the results, describing the current filter state.
     */
    #[Computed]
    public function heading(): string
    {
        if ($this->search !== '') {
            return $this->search;
        }

        return $this->activeCategory?->name
            ?? $this->activeTag?->name
            ?? 'All writing';
    }
}; ?>
<x-log.module
    active="blog"
    data-test="blog-index"
    :title="__('Writing')"
    :meta="trans_choice(':count post|:count posts', $this->posts->total(), ['count' => $this->posts->total()])"
    :note="__('Long-form notes on backend architecture, database performance, and the parts of shipping software nobody puts on a slide.')"
>
    <x-slot:filters>
        <div class="dn-module-tools">
            <div class="dn-search-field">
                <x-feed.icon name="search" :size="16" />
                <label for="post-search" class="sr-only">{{ __('Search posts') }}</label>
                <input
                    id="post-search"
                    type="search"
                    wire:model.live.debounce.350ms="search"
                    placeholder="{{ __('Search posts…') }}"
                    autocomplete="off"
                />
            </div>

            @if ($this->hasFilters)
                <button type="button" class="dn-btn dn-btn-ghost dn-btn-sm" wire:click="clearFilters">
                    {{ __('Clear filters') }}
                </button>
            @endif
        </div>

        {{-- Categories get their own row. Sharing the search row squeezed the
             field down to two characters: the chip strip is `flex: none` wide
             and won the space, because a flex child with no basis shrinks
             before the search field's `flex: 1` can grow. --}}
        @if ($this->categories->isNotEmpty())
            <div class="dn-module-tools" style="padding-top: 0;">
                <div class="dn-filters" style="border-bottom: 0; padding: 0;">
                    @foreach ($this->categories as $category)
                        <button
                            type="button"
                            class="dn-filter {{ $category->slug === $this->category ? 'is-active' : '' }}"
                            wire:click="toggleCategory('{{ $category->slug }}')"
                        >{{ $category->name }} <span class="dn-filter-count">{{ $category->posts_count }}</span></button>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($this->tags->isNotEmpty())
            <div class="dn-module-tools" style="padding-top: 0;">
                <span class="dn-field-key">{{ __('tags') }}</span>

                @foreach ($this->tags as $tag)
                    <button
                        type="button"
                        class="dn-filter {{ $tag->slug === $this->tag ? 'is-active' : '' }}"
                        wire:click="toggleTag('{{ $tag->slug }}')"
                        style="padding: 2px 6px;"
                    >#{{ $tag->name }} <span class="dn-filter-count">{{ $tag->posts_count }}</span></button>
                @endforeach
            </div>
        @endif
    </x-slot:filters>

    @if ($this->posts->isEmpty())
        <div class="dn-module-empty" data-test="blog-empty">
            <p class="dn-empty-command">fatal: nothing published</p>
            <p class="dn-empty-text">
                {{ __('No posts match those filters.') }}
                {{ $this->search !== '' ? __('Try a broader search term, or browse everything instead.') : __('Nothing has been published under this filter yet.') }}
            </p>
            <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="clearFilters" style="margin-top: 16px;">
                {{ __('Reset filters') }}
            </button>
        </div>
    @else
        <div class="dn-entries" wire:loading.class="is-loading" wire:target="search, category, tag">
            @foreach ($this->posts as $post)
                <x-log.entry
                    :href="route('blog.show', ['post' => $post->slug])"
                    :title="$post->title"
                    :eyebrow="$post->category?->name"
                    :description="$post->excerpt"
                    :badge="$post->is_featured ? __('featured') : null"
                    :meta="[
                        $post->published_at?->format('M j, Y'),
                        $post->readingTimeLabel(),
                        trans_choice(':count comment|:count comments', (int) $post->comments_count, ['count' => (int) $post->comments_count]),
                    ]"
                    :wire:key="'post-'.$post->id"
                />
            @endforeach
        </div>

        <x-app.ui.pagination :paginator="$this->posts" label="{{ __('Blog pagination') }}" class="dn-pagination" />
    @endif
</x-log.module>
