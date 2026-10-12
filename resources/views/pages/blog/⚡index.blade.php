<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
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
new #[Title('Writing')] class extends Component
{
    use WithPagination;

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
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
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

<div>
    {{-- ================================================================== --
     | Page header
     --}}
    <section class="border-b border-ink-200 dark:border-ink-800">
        <div class="shell py-14 lg:py-16">
            <x-section-marker :number="1" label="Writing" accent />

            <div class="mt-6 flex flex-wrap items-end justify-between gap-6">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tightest text-ink-900 sm:text-4xl dark:text-ink-50">
                        {{ $this->heading }}
                    </h1>

                    <p class="mt-3 max-w-xl text-base leading-relaxed text-ink-500 dark:text-ink-400">
                        @if ($this->search !== '')
                            {{ $this->posts->total() }} {{ Str::plural('post', $this->posts->total()) }} matching your search.
                        @elseif ($this->activeCategory)
                            {{ $this->activeCategory->description ?? 'Posts in '.$this->activeCategory->name.'.' }}
                        @elseif ($this->activeTag)
                            Everything filed under <span class="font-mono text-signal-700 dark:text-signal-400">{{ $this->activeTag->name }}</span>.
                        @else
                            Long-form notes on backend architecture, database performance, and the parts of shipping software nobody puts on a slide.
                        @endif
                    </p>
                </div>

                @if ($this->hasFilters)
                    <flux:button
                        variant="ghost"
                        size="sm"
                        wire:click="clearFilters"
                        icon="x-mark"
                    >
                        Clear filters
                    </flux:button>
                @endif
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | Filter bar
     --}}
    <section class="border-b border-ink-200 bg-ink-50/50 dark:border-ink-800 dark:bg-ink-900/30" aria-label="Filter posts">
        <div class="shell py-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                {{-- Search --}}
                <div class="w-full lg:max-w-sm">
                    <label for="post-search" class="sr-only">Search posts</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400 dark:text-ink-500">
                            <flux:icon name="magnifying-glass" class="size-4" />
                        </span>

                        <input
                            id="post-search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search posts…"
                            autocomplete="off"
                            class="w-full rounded-md border border-ink-200 bg-white py-2 pl-9 pr-9 text-sm text-ink-900 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-100 dark:placeholder:text-ink-600"
                        />

                        <div wire:loading wire:target="search" class="absolute inset-y-0 right-3 flex items-center">
                            <svg class="size-4 animate-spin text-ink-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4Z"></path>
                            </svg>
                            <span class="sr-only">Searching</span>
                        </div>

                        @if ($search !== '')
                            <button
                                type="button"
                                wire:click="$set('search', '')"
                                class="absolute inset-y-0 right-3 flex items-center text-ink-400 transition-colors hover:text-ink-700 dark:hover:text-ink-200"
                                aria-label="Clear search"
                            >
                                <flux:icon name="x-mark" class="size-4" />
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Active filter chips --}}
                @if ($this->hasFilters)
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($this->activeCategory)
                            <button
                                type="button"
                                wire:click="toggleCategory('{{ $this->activeCategory->slug }}')"
                                class="tag-pill border-signal-400 bg-signal-50 text-signal-700 dark:border-signal-600 dark:bg-signal-950 dark:text-signal-300"
                            >
                                {{ $this->activeCategory->name }}
                                <flux:icon name="x-mark" class="size-3" />
                                <span class="sr-only">Remove category filter</span>
                            </button>
                        @endif

                        @if ($this->activeTag)
                            <button
                                type="button"
                                wire:click="toggleTag('{{ $this->activeTag->slug }}')"
                                class="tag-pill border-signal-400 bg-signal-50 text-signal-700 dark:border-signal-600 dark:bg-signal-950 dark:text-signal-300"
                            >
                                {{ $this->activeTag->name }}
                                <flux:icon name="x-mark" class="size-3" />
                                <span class="sr-only">Remove tag filter</span>
                            </button>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Category rail --}}
            @if ($this->categories->isNotEmpty())
                <div class="mt-4 flex flex-wrap items-center gap-x-1 gap-y-2 border-t border-ink-200 pt-4 dark:border-ink-800">
                    <span class="mr-2 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">Topics</span>

                    @foreach ($this->categories as $category)
                        <button
                            type="button"
                            wire:click="toggleCategory('{{ $category->slug }}')"
                            @class([
                                'rounded-xs px-2.5 py-1 font-mono text-2xs font-medium transition-colors',
                                'bg-ink-900 text-white dark:bg-ink-50 dark:text-ink-950' => $category->slug === $this->category,
                                'text-ink-500 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-100' => $category->slug !== $this->category,
                            ])
                        >
                            {{ $category->name }}
                            <span class="ml-1 opacity-50">{{ $category->posts_count }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Tag rail --}}
            @if ($this->tags->isNotEmpty())
                <div class="mt-3 flex flex-wrap items-center gap-x-1 gap-y-2">
                    <span class="mr-2 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">Tags</span>

                    @foreach ($this->tags as $tag)
                        <button
                            type="button"
                            wire:click="toggleTag('{{ $tag->slug }}')"
                            @class([
                                'rounded-xs px-2 py-0.5 font-mono text-2xs transition-colors',
                                'bg-signal-600 text-white dark:bg-signal-500 dark:text-ink-950' => $tag->slug === $this->tag,
                                'text-ink-500 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-100' => $tag->slug !== $this->tag,
                            ])
                        >
                            {{ $tag->name }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- ================================================================== --
     | Results
     --}}
    <section class="shell py-14" aria-live="polite" aria-busy="{{ $this->posts->isEmpty() ? 'false' : 'false' }}">
        {{-- Loading skeleton, shown only while a filter request is in flight --}}
        <div wire:loading.delay.shorter.flex wire:target="search, category, tag" class="hidden grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (range(1, 6) as $i)
                <div class="card-surface animate-pulse p-6">
                    <div class="h-2.5 w-20 rounded-full bg-ink-200 dark:bg-ink-800"></div>
                    <div class="mt-5 h-5 w-3/4 rounded bg-ink-200 dark:bg-ink-800"></div>
                    <div class="mt-3 h-4 w-full rounded bg-ink-100 dark:bg-ink-900"></div>
                    <div class="mt-2 h-4 w-5/6 rounded bg-ink-100 dark:bg-ink-900"></div>
                    <div class="mt-6 h-3 w-1/2 rounded bg-ink-100 dark:bg-ink-900"></div>
                </div>
            @endforeach
        </div>

        <div wire:loading.remove wire:target="search, category, tag">
            @if ($this->posts->isEmpty())
                <x-empty-state
                    icon="magnifying-glass"
                    title="No posts match those filters"
                    description="{{ $this->search !== '' ? 'Try a broader search term, or browse everything instead.' : 'Nothing has been published under this filter yet.' }}"
                >
                    <flux:button variant="primary" wire:click="clearFilters" icon="arrow-path">
                        Reset filters
                    </flux:button>
                </x-empty-state>
            @else
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->posts as $post)
                        <x-post.card :post="$post" wire:key="post-{{ $post->id }}" />
                    @endforeach
                </div>

                <x-pagination :paginator="$this->posts" label="Blog pagination" class="mt-12" />
            @endif
        </div>
    </section>
</div>
