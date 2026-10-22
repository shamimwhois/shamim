<?php

use App\Enums\AccountPermission;
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
 * A filterable, searchable table of the signed-in account's own posts with
 * inline status actions. Deliberately a table rather than a card grid: at this
 * density the author is scanning for a specific row, not browsing.
 *
 * Two different things limit this page, and conflating them would be a mistake:
 * `admin:access` decides who may open it at all, while ownership decides which
 * rows they see. The route group applies the first; the `boot()` below re-applies
 * it because Livewire's update POSTs go to `/livewire/update` rather than to this
 * URI, so the route middleware never sees them. `authoredBy()` does the second
 * and is not a security check — an admin here sees their own writing, not
 * everybody's.
 *
 * All mutations route through the existing Action classes so the dashboard
 * cannot bypass the publish/unpublish invariants enforced for the editor.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Posts')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $category = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::AccessAdminPanel), 403);
    }

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

        \Flux\Flux::toast(variant: 'success', text: __('Post deleted.'));
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
    <x-app.ui.header
        eyebrow="Content"
        title="Posts"
        description="Every post you have written, with its current state. Drafts and archived posts are invisible to readers."
        :breadcrumbs="[['label' => __('Content')], ['label' => __('Posts')]]"
    >
        <x-slot:actions>
            <flux:button variant="primary" :href="route('admin.posts.create')" icon="plus" wire:navigate>
                {{ __('New post') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.body max="full">
        {{-- ============================================================= --
         | Filters
         --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="w-full lg:max-w-xs">
                <label for="post-search" class="sr-only">Search posts</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="post-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search your posts…"
                        autocomplete="off"
                        class="sui-control w-full pl-9"
                    />
                </div>
            </div>

            {{-- Status tabs --}}
            <div class="flex flex-wrap items-center gap-1">
                <button
                    type="button"
                    wire:click="$set('status', '')"
                    @class([
                        'sui-focus sui-tab',
                        'sui-tab-active' => $status === '',
                    ])
                >
                    All
                    <span class="opacity-60 tabular-nums">{{ array_sum(array_column($this->statusCounts, 'count')) }}</span>
                </button>

                @foreach ($this->statusCounts as $value => $meta)
                    <button
                        type="button"
                        wire:click="$set('status', '{{ $value }}')"
                        @class([
                            'sui-focus sui-tab',
                            'sui-tab-active' => $status === $value,
                        ])
                    >
                        {{ $meta['label'] }}
                        <span class="opacity-60 tabular-nums">{{ $meta['count'] }}</span>
                    </button>
                @endforeach

                @if ($this->categories->isNotEmpty())
                    <span aria-hidden="true" class="mx-1 h-4 w-px bg-sui-hairline"></span>

                    <label for="category-filter" class="sr-only">Filter by category</label>
                    <select
                        id="category-filter"
                        wire:model.live="category"
                        class="sui-control w-auto !py-1.5 text-xs"
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
                        class="sui-focus sui-chip-neutral"
                    >
                        Clear
                    </button>
                @endif
            </div>
        </div>

        {{-- ============================================================= --
         | Table
         --}}
        <div class="sui-card mt-6 overflow-hidden">
            @if ($this->posts->isEmpty())
                <div class="sui-empty">
                    <div class="mx-auto grid size-11 place-items-center rounded-md border border-sui-hairline bg-sui-well text-ink-400">
                        <flux:icon name="document-text" class="size-5" />
                    </div>

                    <p class="mt-4 text-base font-semibold tracking-tight text-sui-ink">
                        {{ $this->hasFilters ? 'No posts match those filters' : 'No posts yet' }}
                    </p>

                    <p class="mt-1.5 text-sm text-ink-500">
                        {{ $this->hasFilters ? 'Try clearing the filters.' : 'Your writing will show up here once you start.' }}
                    </p>

                    <div class="mt-6">
                        @if ($this->hasFilters)
                            <flux:button variant="primary" wire:click="clearFilters" icon="arrow-path">
                                Reset filters
                            </flux:button>
                        @else
                            <flux:button variant="primary" :href="route('admin.posts.create')" icon="plus" wire:navigate>
                                Write your first post
                            </flux:button>
                        @endif
                    </div>
                </div>
            @else
                {{-- Table header (desktop) --}}
                <div class="hidden border-b border-sui-hairline px-5 py-3 lg:grid lg:grid-cols-[1fr_120px_100px_100px_140px] lg:gap-4">
                    <span class="sui-table-head">Title</span>
                    <span class="sui-table-head">Status</span>
                    <span class="sui-table-head">Views</span>
                    <span class="sui-table-head">Comments</span>
                    <span class="sui-table-head">Updated</span>
                </div>

                <ul class="divide-y divide-sui-hairline">
                    @foreach ($this->posts as $post)
                        <li
                            wire:key="dashboard-post-{{ $post->id }}"
                            class="sui-row group transition duration-150 ease-soft hover:bg-sui-hover"
                            x-data="{ confirmingDelete: false }"
                        >
                            <div class="flex flex-col gap-4 lg:grid lg:grid-cols-[1fr_120px_100px_100px_140px] lg:items-center">
                                {{-- Title + meta --}}
                                <div class="min-w-0">
                                    <a
                                        href="{{ route('admin.posts.edit', $post) }}"
                                        wire:navigate
                                        class="block truncate text-sm font-medium text-sui-ink transition duration-150 ease-soft hover:text-sui-accent-ink"
                                    >
                                        {{ $post->title }}
                                    </a>

                                    <p class="mt-0.5 truncate text-2xs text-ink-500">
                                        {{ $post->category?->name ?? 'Uncategorised' }}
                                        @if ($post->is_featured)
                                            · <span class="text-sui-attention-ink">featured</span>
                                        @endif
                                        @if ($post->trashed())
                                            · <span class="text-sui-danger">deleted</span>
                                        @endif
                                    </p>
                                </div>

                                {{-- Status --}}
                                <div class="flex items-center gap-2 lg:block">
                                    <span class="sui-table-head lg:hidden">Status</span>
                                    <flux:badge :color="$post->status->badgeColor()" size="sm">
                                        {{ $post->status->label() }}
                                    </flux:badge>
                                </div>

                                {{-- Views --}}
                                <div class="flex items-center justify-between gap-2 lg:block">
                                    <span class="sui-table-head lg:hidden">Views</span>
                                    <span class="sui-table-cell">
                                        {{ number_format($post->view_count) }}
                                    </span>
                                </div>

                                {{-- Comments --}}
                                <div class="flex items-center justify-between gap-2 lg:block">
                                    <span class="sui-table-head lg:hidden">Comments</span>
                                    <span class="sui-table-cell">
                                        {{ $post->approved_comments_count }}
                                    </span>
                                </div>

                                {{-- Updated + actions --}}
                                <div class="flex items-center justify-between gap-2 lg:justify-end">
                                    <time class="text-2xs text-ink-500" datetime="{{ $post->updated_at->toIso8601String() }}">
                                        {{ $post->updated_at->diffForHumans(short: true) }}
                                    </time>
                                </div>
                            </div>

                            {{-- Row actions --}}
                            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-sui-hairline pt-3">
                                <flux:button variant="ghost" size="xs" :href="route('admin.posts.edit', $post)" icon="pencil-square" wire:navigate>
                                    Edit
                                </flux:button>

                                @if ($post->isPublished())
                                    <a
                                        href="{{ route('blog.show', $post) }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="sui-action !px-2.5 !py-1.5 !text-xs"
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
                                        <flux:button variant="ghost" size="xs" x-on:click="confirmingDelete = true" icon="trash" class="!text-sui-danger">
                                            Delete
                                        </flux:button>
                                    </template>

                                    <template x-if="confirmingDelete">
                                        <div class="flex items-center gap-2">
                                            <span class="text-2xs text-sui-danger">Delete permanently?</span>
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

        <x-app.ui.pagination :paginator="$this->posts" label="Posts pagination" />
    </x-app.ui.body>
</div>
