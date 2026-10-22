<?php

use App\Actions\Comments\ApproveComment;
use App\Actions\Comments\RejectComment;
use App\Enums\AccountPermission;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Support\Seo;
use Flux\Flux;
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
 * Comment moderation.
 *
 * The queue is deliberately optimised for the common case: a batch of obviously
 * fine comments. Approve is one click, no confirmation, no page reload. Rejecting
 * is equally quick. The page defaults to the pending filter, because a moderation
 * queue that opens on "everything" makes you find the work.
 *
 * It holds the comments on the signed-in account's own posts — `/admin/feed` is
 * the platform-wide surface — and `admin:access` is what opens the door. The
 * `boot()` recheck matters here more than on most pages: approve and reject are
 * the kind of thing a stale tab keeps posting to after the permission is gone.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Comments')] class extends Component
{
    use WithPagination;

    /**
     * Defaults to pending: opening on "all" would bury the work.
     */
    #[Url(except: '')]
    public string $status = 'pending';

    #[Url(except: '')]
    public string $post = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::AccessAdminPanel), 403);
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Comments',
            'description' => 'Moderate reader comments.',
            'noindex' => true,
        ]);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'post', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'post', 'search');
        $this->status = 'pending';
        $this->resetPage();
    }

    /**
     * Approve a comment, publishing it to the public thread.
     */
    public function approve(int $commentId, ApproveComment $approveComment): void
    {
        $comment = $this->findModeratableComment($commentId);

        $approveComment->handle($comment);

        unset($this->comments, $this->statusCounts);

        Flux::toast(variant: 'success', text: __('Comment approved.'));
    }

    /**
     * Reject a comment. Kept in the database rather than deleted, so a
     * re-submission can be recognised.
     */
    public function reject(int $commentId, RejectComment $rejectComment): void
    {
        $comment = $this->findModeratableComment($commentId);

        $rejectComment->handle($comment);

        unset($this->comments, $this->statusCounts);

        Flux::toast(variant: 'success', text: __('Comment rejected.'));
    }

    /**
     * Approve every pending comment on a post.
     */
    public function approveAllOnPost(int $postId, ApproveComment $approveComment): void
    {
        $post = Post::query()->authoredBy(auth()->user())->findOrFail($postId);

        $comments = $post->comments()->pending()->get();

        foreach ($comments as $comment) {
            $approveComment->handle($comment);
        }

        unset($this->comments, $this->statusCounts);

        Flux::toast(variant: 'success', text: trans_choice(':count comment approved.|:count comments approved.', $comments->count(), ['count' => $comments->count()]));
    }

    /**
     * Delete a comment outright.
     */
    public function delete(int $commentId): void
    {
        $comment = $this->findModeratableComment($commentId);

        $comment->delete();

        unset($this->comments, $this->statusCounts);

        Flux::toast(variant: 'success', text: __('Comment deleted.'));
    }

    /**
     * Resolve a comment, scoped to the author's own posts.
     *
     * Scope in the lookup, not just the policy: a comment id belonging to
     * another author's post is a 404, not a 403.
     */
    protected function findModeratableComment(int $commentId): Comment
    {
        return Comment::query()
            ->whereHas('post', fn (Builder $query) => $query->authoredBy(auth()->user()))
            ->with('post')
            ->findOrFail($commentId);
    }

    /**
     * @return LengthAwarePaginator<int, Comment>
     */
    #[Computed]
    public function comments(): LengthAwarePaginator
    {
        return Comment::query()
            ->whereHas('post', fn (Builder $query) => $query->authoredBy(auth()->user()))
            ->with(['post', 'user', 'parent.user'])
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->post !== '', fn (Builder $query) => $query->where('post_id', (int) $this->post))
            ->when($this->search !== '', fn (Builder $query) => $query->where(function (Builder $query): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';

                $query->where('body', 'like', $term)
                    ->orWhereHas('user', fn (Builder $query) => $query->where('name', 'like', $term));
            }))
            ->latest()
            ->paginate((int) config('portfolio.blog.comments_per_page', 20))
            ->withQueryString();
    }

    /**
     * Status tabs, with counts.
     *
     * @return array<string, array{label: string, count: int}>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $base = fn () => Comment::query()
            ->whereHas('post', fn (Builder $query) => $query->authoredBy(auth()->user()));

        $counts = [
            'all' => [
                'label' => 'All',
                'count' => $base()->count(),
            ],
        ];

        foreach (CommentStatus::cases() as $case) {
            $counts[$case->value] = [
                'label' => $case->label(),
                'count' => $base()->where('status', $case)->count(),
            ];
        }

        return $counts;
    }

    /**
     * The author's posts that have comments, for the post filter.
     *
     * @return Collection<int, Post>
     */
    #[Computed]
    public function posts(): Collection
    {
        return Post::query()
            ->authoredBy(auth()->user())
            ->whereHas('comments')
            ->withCount('comments')
            ->orderByDesc('comments_count')
            ->get();
    }

    #[Computed]
    public function pendingCount(): int
    {
        return $this->statusCounts['pending']['count'] ?? 0;
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Engagement"
        title="Comments"
        description="Approve what adds to the conversation, reject what does not. Approved comments appear immediately on the post."
    >
        <x-slot:actions>
            @if ($this->pendingCount > 0 && $status === 'pending')
                <span class="sui-chip-warning !px-3 !py-2">
                    <span class="size-1.5 rounded-full bg-sui-warning"></span>
                    {{ trans_choice(':count waiting|:count waiting', $this->pendingCount, ['count' => $this->pendingCount]) }}
                </span>
            @endif
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.body>
        {{-- ============================================================= --
         | Filters
         --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="w-full lg:max-w-xs">
                <label for="comment-search" class="sr-only">Search comments</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="comment-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search by comment or author…"
                        autocomplete="off"
                        class="sui-control w-full pl-9"
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1">
                @foreach ($this->statusCounts as $value => $meta)
                    @php $active = ($value === 'all' && $status === '') || $status === $value; @endphp

                    <button
                        type="button"
                        wire:click="$set('status', '{{ $value === 'all' ? '' : $value }}')"
                        @class(['sui-focus sui-tab', 'sui-tab-active' => $active])
                    >
                        {{ $meta['label'] }}
                        <span class="opacity-60 tabular-nums">{{ $meta['count'] }}</span>
                    </button>
                @endforeach

                @if ($this->posts->isNotEmpty())
                    <span aria-hidden="true" class="mx-1 h-4 w-px bg-sui-hairline"></span>

                    <label for="post-filter" class="sr-only">Filter by post</label>
                    <select
                        id="post-filter"
                        wire:model.live="post"
                        class="sui-control w-auto max-w-[200px] !py-1.5 text-xs"
                    >
                        <option value="">All posts</option>
                        @foreach ($this->posts as $p)
                            <option value="{{ $p->id }}">{{ Str::limit($p->title, 32) }} ({{ $p->comments_count }})</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </div>

        {{-- Bulk action for the selected post --}}
        @if ($post !== '' && $this->pendingCount > 0 && $status === 'pending')
            <div class="sui-card mt-4 flex flex-wrap items-center gap-3 px-4 py-3">
                <p class="text-xs leading-normal text-ink-500">
                    {{ trans_choice(':count pending comment|:count pending comments', $this->pendingCount, ['count' => $this->pendingCount]) }} on this post.
                </p>
                <flux:button
                    class="ml-auto"
                    size="xs"
                    variant="primary"
                    wire:click="approveAllOnPost({{ (int) $post }})"
                    wire:loading.attr="disabled"
                    icon="check-badge"
                >
                    Approve all
                </flux:button>
            </div>
        @endif

        {{-- ============================================================= --
         | Comment list
         --
         | Cards rather than a table: a comment is a paragraph of prose with
         | a header, and splitting prose across a fixed column grid is how
         you end up with a layout only short comments fit.
         --}}
        <div class="mt-6 space-y-3">
            @if ($this->comments->isEmpty())
                <div class="sui-card sui-empty">
                    <div class="mx-auto grid size-11 place-items-center rounded-md border border-sui-hairline bg-sui-well text-ink-400">
                        <flux:icon name="check-badge" class="size-5" />
                    </div>

                    <p class="mt-4 text-base font-semibold tracking-tight text-ink-900">
                        @if ($status === 'pending')
                            Nothing waiting for review
                        @else
                            No comments match those filters
                        @endif
                    </p>

                    <p class="mt-1.5 text-sm text-ink-500">
                        @if ($status === 'pending')
                            New comments will appear here for review.
                        @else
                            Try a different status or clear the search.
                        @endif
                    </p>
                </div>
            @else
                @foreach ($this->comments as $comment)
                    <article
                        wire:key="comment-{{ $comment->id }}"
                    @class([
                        'sui-card p-5 transition duration-200 ease-soft',
                        /* `!` — `sui-card` also sets `border-sui-hairline`, and the
                           amber border has to win for the pending state to show. */
                        '!border-sui-attention-border dark:!border-sui-attention-border/30' => $comment->status === CommentStatus::Pending,
                    ])
                    >
                        <div class="flex gap-4">
                            {{-- Avatar --}}
                            <div class="grid size-9 shrink-0 place-items-center rounded-md border border-sui-hairline bg-sui-well text-xs font-semibold text-ink-500">
                                {{ Str::of($comment->user->name)->substr(0, 1)->upper() }}
                            </div>

                            <div class="min-w-0 flex-1">
                                {{-- Header --}}
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <p class="text-sm font-semibold tracking-tight text-ink-900">
                                        {{ $comment->user->name }}
                                    </p>

                                    <span class="text-2xs text-ink-500">
                                        {{ $comment->user->email }}
                                    </span>

                                    <time class="text-2xs text-ink-500" datetime="{{ $comment->created_at->toIso8601String() }}">
                                        {{ $comment->created_at->diffForHumans() }}
                                    </time>

                                    <flux:badge :color="$comment->status->badgeColor()" size="sm">
                                        {{ $comment->status->label() }}
                                    </flux:badge>

                                    @if ($comment->parent_id !== null)
                                        <span class="text-2xs text-ink-500">
                                            reply to {{ $comment->parent?->user?->name ?? 'a deleted comment' }}
                                        </span>
                                    @endif
                                </div>

                                {{-- On which post --}}
                                <p class="mt-1.5 text-2xs text-ink-500">
                                    on
                                    <a
                                        href="{{ route('blog.show', $comment->post) }}#comments-heading"
                                        target="_blank"
                                        rel="noopener"
                                        class="sui-link"
                                    >
                                        {{ $comment->post->title }}
                                    </a>
                                </p>

                                {{-- Quoted parent --}}
                                @if ($comment->parent)
                                    <blockquote class="mt-3 border-l-2 border-sui-hairline pl-3 text-xs leading-normal text-ink-500">
                                        {{ Str::limit($comment->parent->body, 140) }}
                                    </blockquote>
                                @endif

                                {{-- Body --}}
                                <div class="mt-3 text-sm leading-normal text-ink-700">
                                    {!! nl2br(e($comment->body)) !!}
                                </div>

                                {{-- Actions --}}
                                <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-sui-hairline pt-3">
                                    @if ($comment->status !== CommentStatus::Approved)
                                        <flux:button
                                            size="xs"
                                            variant="primary"
                                            wire:click="approve({{ $comment->id }})"
                                            wire:loading.attr="disabled"
                                            icon="check"
                                        >
                                            Approve
                                        </flux:button>
                                    @endif

                                    @if ($comment->status !== CommentStatus::Rejected)
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            wire:click="reject({{ $comment->id }})"
                                            wire:loading.attr="disabled"
                                            icon="x-mark"
                                        >
                                            Reject
                                        </flux:button>
                                    @endif

                                    <a
                                        href="{{ route('blog.show', $comment->post) }}#comments-heading"
                                        target="_blank"
                                        rel="noopener"
                                        class="sui-action !px-2.5 !py-1.5 !text-xs"
                                    >
                                        <flux:icon name="arrow-top-right-on-square" class="size-3.5" />
                                        View in context
                                    </a>

                                    <div class="ml-auto" x-data="{ confirming: false }">
                                        <template x-if="! confirming">
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                x-on:click="confirming = true"
                                                icon="trash"
                                                class="!text-sui-danger"
                                            >
                                                Delete
                                            </flux:button>
                                        </template>

                                        <template x-if="confirming">
                                            <div class="flex items-center gap-2">
                                                <span class="text-2xs text-sui-danger">Sure?</span>
                                                <flux:button size="xs" variant="danger" wire:click="delete({{ $comment->id }})" wire:loading.attr="disabled">
                                                    Delete
                                                </flux:button>
                                                <flux:button size="xs" variant="ghost" x-on:click="confirming = false">
                                                    No
                                                </flux:button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            @endif
        </div>

        <x-app.ui.pagination :paginator="$this->comments" label="Comments pagination" class="mt-6" />
    </x-app.ui.body>
</div>
