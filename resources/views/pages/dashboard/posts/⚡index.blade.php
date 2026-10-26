<?php

use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Post;
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
 * Post management.
 *
 * A filterable, searchable table of the author's posts with inline status
 * actions. Deliberately a table rather than a card grid: at this density the
 * author is scanning for a specific row, not browsing.
 *
 * All mutations route through the existing Action classes so the dashboard
 * cannot bypass the publish/unpublish invariants enforced for the editor.
 */
new #[Layout('layouts::dashboard')] #[Title('Posts')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $category = '';

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Posts',
            'description' => 'Manage your writing.',
            'noindex' => true,
        ]);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status', 'category'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'category');
        $this->resetPage();
    }

    /**
     * Publish a draft, or republish an archived post.
     */
    public function publish(int $postId, \App\Actions\Posts\PublishPost $publishPost): void
    {
        $post = $this->findOwnedPost($postId);

        $this->authorize('publish', $post);

        if (! $publishPost->canPublish($post)) {
            \Flux\Flux::toast(variant: 'danger', text: __('This post cannot be published.'));

            return;
        }

        $publishPost->handle($post);

        unset($this->posts);

        \Flux\Flux::toast(variant: 'success', text: __('Published.'));
    }

    /**
     * Move a published post back to draft.
     */
    public function unpublish(int $postId, \App\Actions\Posts\UnpublishPost $unpublishPost): void
    {
        $post = $this->findOwnedPost($postId);

        $this->authorize('update', $post);

        $unpublishPost->handle($post);

        unset($this->posts);

        \Flux\Flux::toast(variant: 'success', text: __('Moved to drafts.'));
    }

    /**
     * Retire a post without deleting it.
     */
    public function archive(int $postId, \App\Actions\Posts\ArchivePost $archivePost): void
    {
        $post = $this->findOwnedPost($postId);

        $this->authorize('update', $post);

        $archivePost->handle($post);

        unset($this->posts);

        \Flux\Flux::toast(variant: 'success', text: __('Archived.'));
    }

    /**
     * Soft delete a post.
     */
    public function delete(int $postId, \App\Actions\Posts\DeletePost $deletePost): void
    {
        $post = $this->findOwnedPost($postId);

        $this->authorize('delete', $post);

        $deletePost->handle($post);

        unset($this->posts);

        \Flux\Flux::toast(variant: 'success', text: __('Post moved to trash.'));
    }

    /**
     * Resolve a post by id, scoped to the signed-in author.
     *
     * Scoping in the lookup rather than relying on the policy alone means an id
     * belonging to someone else is a 404, not a 403 — it does not confirm the
     * post exists.
     */
    protected function findOwnedPost(int $postId): Post
    {
        return Post::query()
            ->authoredBy(auth()->user())
            ->findOrFail($postId);
    }

    /**
     * @return LengthAwarePaginator<int, Post>
     */
    #[Computed]
    public function posts(): LengthAwarePaginator
    {
        return Post::query()
            ->authoredBy(auth()->user())
            ->with('category')
            ->withCount(['comments', 'approvedComments as approved_comments_count'])
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->category !== '', fn (Builder $query) => $query->inCategory($this->category))
            ->orderByDesc('updated_at')
            ->paginate((int) config('portfolio.blog.dashboard_per_page', 15))
            ->withQueryString();
    }

    /**
     * Status options for the filter, with live counts.
     *
     * @return array<string, array{label: string, count: int}>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $counts = [];

        foreach (PostStatus::cases() as $case) {
            $counts[$case->value] = [
                'label' => $case->label(),
                'count' => Post::query()
                    ->authoredBy(auth()->user())
                    ->where('status', $case)
                    ->count(),
            ];
        }

        return $counts;
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->ordered()->get();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->category !== '';
    }
}; ?>

<div>
    <x-dashboard.header
        eyebrow="Content"
        title="Posts"
        description="Every post you have written, with its current state. Drafts and archived posts are invisible to readers."
    >
        <x-slot:actions>
            <flux:button variant="primary" :href="route('dashboard.posts.create')" icon="plus" wire:navigate>
                New post
            </flux:button>
        </x-slot:actions>
    </x-dashboard.header>

    <div class="px-6 py-8 lg:px-10">
        {{-- ============================================================= --
         | Filters
         --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="w-full lg:max-w-xs">
                <label for="post-search" class="sr-only">Search posts</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400 dark:text-ink-500">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="post-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search your posts…"
                        autocomplete="off"
                        class="w-full rounded-md border border-ink-200 bg-white py-2 pl-9 pr-3 text-sm text-ink-900 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-100 dark:placeholder:text-ink-600"
                    />
                </div>
            </div>

            {{-- Status tabs --}}
            <div class="flex flex-wrap items-center gap-1">
                <button
                    type="button"
                    wire:click="$set('status', '')"
                    @class([
                        'rounded-xs px-3 py-1.5 font-mono text-2xs font-medium transition-colors',
                        'bg-ink-900 text-white dark:bg-ink-50 dark:text-ink-950' => $status === '',
                        'text-ink-500 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-100' => $status !== '',
                    ])
                >
                    All
                    <span class="ml-1 opacity-60 tabular-nums">{{ array_sum(array_column($this->statusCounts, 'count')) }}</span>
                </button>

                @foreach ($this->statusCounts as $value => $meta)
                    <button
                        type="button"
                        wire:click="$set('status', '{{ $value }}')"
                        @class([
                            'rounded-xs px-3 py-1.5 font-mono text-2xs font-medium transition-colors',
                            'bg-ink-900 text-white dark:bg-ink-50 dark:text-ink-950' => $status === $value,
                            'text-ink-500 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-100' => $status !== $value,
                        ])
                    >
                        {{ $meta['label'] }}
                        <span class="ml-1 opacity-60 tabular-nums">{{ $meta['count'] }}</span>
                    </button>
                @endforeach

                @if ($this->categories->isNotEmpty())
                    <span aria-hidden="true" class="mx-1 h-4 w-px bg-ink-200 dark:bg-ink-800"></span>

                    <label for="category-filter" class="sr-only">Filter by category</label>
                    <select
                        id="category-filter"
                        wire:model.live="category"
                        class="rounded-xs border border-ink-200 bg-white px-2 py-1.5 font-mono text-2xs text-ink-600 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-400"
                    >
                        <option value="">All categories</option>
                        @foreach ($this->categories as $cat)
                            <option value="{{ $cat->slug }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                @endif

                @if ($this->hasFilters)
                    <button
                        type="button"
                        wire:click="clearFilters"
                        class="rounded-xs px-2 py-1.5 font-mono text-2xs text-ink-400 transition-colors hover:text-ink-700 dark:text-ink-500 dark:hover:text-ink-200"
                    >
                        Clear
                    </button>
                @endif
            </div>
        </div>

        {{-- ============================================================= --
         | Table
         --}}
        <div class="mt-6 overflow-hidden rounded-lg border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
            @if ($this->posts->isEmpty())
                <div class="px-6 py-16 text-center">
                    <div class="mx-auto grid size-11 place-items-center rounded-md border border-ink-200 bg-ink-50 text-ink-400 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-500">
                        <flux:icon name="document-text" class="size-5" />
                    </div>

                    <p class="mt-4 text-base font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        {{ $this->hasFilters ? 'No posts match those filters' : 'No posts yet' }}
                    </p>

                    <p class="mt-1.5 text-sm text-ink-500 dark:text-ink-400">
                        {{ $this->hasFilters ? 'Try clearing the filters.' : 'Your writing will show up here once you start.' }}
                    </p>

                    <div class="mt-6">
                        @if ($this->hasFilters)
                            <flux:button variant="primary" wire:click="clearFilters" icon="arrow-path">
                                Reset filters
                            </flux:button>
                        @else
                            <flux:button variant="primary" :href="route('dashboard.posts.create')" icon="plus" wire:navigate>
                                Write your first post
                            </flux:button>
                        @endif
                    </div>
                </div>
            @else
                {{-- Table header (desktop) --}}
                <div class="hidden border-b border-ink-200 px-5 py-3 lg:grid lg:grid-cols-[1fr_120px_100px_100px_140px] lg:gap-4 dark:border-ink-800">
                    <span class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">Title</span>
                    <span class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">Status</span>
                    <span class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">Views</span>
                    <span class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">Comments</span>
                    <span class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">Updated</span>
                </div>

                <ul class="divide-y divide-ink-200 dark:divide-ink-800">
                    @foreach ($this->posts as $post)
                        <li
                            wire:key="dashboard-post-{{ $post->id }}"
                            class="group px-5 py-4 transition-colors hover:bg-ink-50 dark:hover:bg-ink-950/60"
                            x-data="{ confirmingDelete: false }"
                        >
                            <div class="flex flex-col gap-4 lg:grid lg:grid-cols-[1fr_120px_100px_100px_140px] lg:items-center">
                                {{-- Title + meta --}}
                                <div class="min-w-0">
                                    <a
                                        href="{{ route('dashboard.posts.edit', $post) }}"
                                        wire:navigate
                                        class="block truncate text-sm font-medium text-ink-900 transition-colors hover:text-signal-700 dark:text-ink-50 dark:hover:text-signal-400"
                                    >
                                        {{ $post->title }}
                                    </a>

                                    <p class="mt-0.5 truncate font-mono text-2xs text-ink-400 dark:text-ink-500">
                                        {{ $post->category?->name ?? 'Uncategorised' }}
                                        @if ($post->is_featured)
                                            · <span class="text-amber-600 dark:text-amber-400">featured</span>
                                        @endif
                                        @if ($post->trashed())
                                            · <span class="text-red-500">deleted</span>
                                        @endif
                                    </p>
                                </div>

                                {{-- Status --}}
                                <div class="flex items-center gap-2 lg:block">
                                    <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 lg:hidden dark:text-ink-500">Status</span>
                                    <flux:badge :color="$post->status->badgeColor()" size="sm">
                                        {{ $post->status->label() }}
                                    </flux:badge>
                                </div>

                                {{-- Views --}}
                                <div class="flex items-center justify-between gap-2 lg:block">
                                    <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 lg:hidden dark:text-ink-500">Views</span>
                                    <span class="font-mono text-xs tabular-nums text-ink-600 dark:text-ink-400">
                                        {{ number_format($post->view_count) }}
                                    </span>
                                </div>

                                {{-- Comments --}}
                                <div class="flex items-center justify-between gap-2 lg:block">
                                    <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 lg:hidden dark:text-ink-500">Comments</span>
                                    <span class="font-mono text-xs tabular-nums text-ink-600 dark:text-ink-400">
                                        {{ $post->approved_comments_count }}
                                    </span>
                                </div>

                                {{-- Updated + actions --}}
                                <div class="flex items-center justify-between gap-2 lg:justify-end">
                                    <time class="font-mono text-2xs text-ink-400 dark:text-ink-500" datetime="{{ $post->updated_at->toIso8601String() }}">
                                        {{ $post->updated_at->diffForHumans(short: true) }}
                                    </time>
                                </div>
                            </div>

                            {{-- Row actions --}}
                            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-ink-100 pt-3 lg:mt-3 dark:border-ink-800">
                                <flux:button variant="ghost" size="xs" :href="route('dashboard.posts.edit', $post)" icon="pencil-square" wire:navigate>
                                    Edit
                                </flux:button>

                                @if ($post->isPublished())
                                    <a
                                        href="{{ route('blog.show', $post) }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-100"
                                    >
                                        <flux:icon name="arrow-top-right-on-square" class="size-3.5" />
                                        View
                                    </a>

                                    <flux:button variant="ghost" size="xs" wire:click="unpublish({{ $post->id }})" icon="arrow-uturn-left" wire:loading.attr="disabled">
                                        Unpublish
                                    </flux:button>
                                @else
                                    <flux:button variant="ghost" size="xs" wire:click="publish({{ $post->id }})" icon="globe-alt" wire:loading.attr="disabled">
                                        Publish
                                    </flux:button>
                                @endif

                                @unless ($post->status === PostStatus::Archived)
                                    <flux:button variant="ghost" size="xs" wire:click="archive({{ $post->id }})" icon="archive-box" wire:loading.attr="disabled">
                                        Archive
                                    </flux:button>
                                @endunless

                                <div class="ml-auto flex items-center gap-2">
                                    <template x-if="! confirmingDelete">
                                        <flux:button variant="ghost" size="xs" x-on:click="confirmingDelete = true" icon="trash" class="!text-red-600 dark:!text-red-400">
                                            Delete
                                        </flux:button>
                                    </template>

                                    <template x-if="confirmingDelete">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-2xs text-red-600 dark:text-red-400">Move to trash?</span>
                                            <flux:button variant="danger" size="xs" wire:click="delete({{ $post->id }})" wire:loading.attr="disabled">
                                                Confirm
                                            </flux:button>
                                            <flux:button variant="ghost" size="xs" x-on:click="confirmingDelete = false">
                                                Cancel
                                            </flux:button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <x-pagination :paginator="$this->posts" label="Posts pagination" class="mt-6" />
    </div>
</div>
