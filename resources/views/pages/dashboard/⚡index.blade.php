<?php

use App\Data\PostMetrics;
use App\Enums\AccountRole;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dashboard overview — the author workspace.
 *
 * `/dashboard` is the user role's home and the shared author workspace. It is
 * also the safe landing spot for every authenticated account, so it forwards a
 * seller or an admin to the dashboard their role owns:
 *
 *   user   → renders the author workspace below
 *   seller → redirects to `/seller/dashboard`
 *   admin  → redirects to `/admin/dashboard`
 *
 * The redirect is a real HTTP redirect rather than a conditional render so the
 * URL always names what is on screen, and so the sidebar's active state and
 * browser history both stay honest.
 *
 * Every number on this page is computed from persisted data — no analytics
 * service, no sampling, no external dependency that can be wrong.
 */
new #[Layout('layouts::dashboard')] #[Title('Dashboard')] class extends Component
{
    public function mount(): void
    {
        $role = auth()->user()->accountRole();

        if ($role !== AccountRole::User) {
            /* Only redirect to a dashboard that is actually registered. */
            $routeName = $role->dashboardRoute();

            if (app('router')->has($routeName)) {
                $this->redirectRoute($routeName, navigate: true);

                return;
            }
        }

        app(Seo::class)->set([
            'title' => 'Dashboard',
            'description' => 'Author dashboard for '.config('portfolio.author.name'),
            'noindex' => true,
        ]);
    }

    /**
     * Aggregate content metrics for the signed-in author.
     */
    #[Computed]
    public function metrics(): PostMetrics
    {
        return PostMetrics::forAuthor(auth()->user());
    }

    /**
     * The most recent comments awaiting moderation.
     *
     * @return Collection<int, Comment>
     */
    #[Computed]
    public function pendingComments(): Collection
    {
        return Comment::query()
            ->pending()
            ->with(['post', 'user'])
            ->latest()
            ->limit(5)
            ->get();
    }

    /**
     * Recently edited posts, drafts first.
     *
     * `orderByRaw` puts unpublished work at the top: a draft you touched
     * yesterday needs attention more than a post published last month.
     *
     * @return Collection<int, Post>
     */
    #[Computed]
    public function recentPosts(): Collection
    {
        return Post::query()
            ->authoredBy(auth()->user())
            ->with('category')
            ->withCount(['comments' => fn ($query) => $query->approved()])
            ->orderByRaw("case when status = 'draft' then 0 else 1 end")
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get();
    }

    /**
     * Posts with a pending comment, so the queue can be scanned by post.
     *
     * @return Collection<int, Post>
     */
    #[Computed]
    public function postsNeedingModeration(): Collection
    {
        return Post::query()
            ->authoredBy(auth()->user())
            ->whereHas('comments', fn ($query) => $query->where('status', CommentStatus::Pending))
            ->withCount(['comments as pending_count' => fn ($query) => $query->where('status', CommentStatus::Pending)])
            ->orderByDesc('pending_count')
            ->limit(5)
            ->get();
    }

    /**
     * The most-read published posts.
     *
     * @return Collection<int, Post>
     */
    #[Computed]
    public function topPosts(): Collection
    {
        return Post::query()
            ->authoredBy(auth()->user())
            ->published()
            ->orderByDesc('view_count')
            ->limit(5)
            ->get();
    }
}; ?>

<div>
    <x-dashboard.header
        eyebrow="Overview"
        :title="'Welcome back, '.Str::of(auth()->user()->name)->explode(' ')->first()"
        description="A snapshot of your writing: what is published, what is waiting, and what is getting read."
    >
        <x-slot:actions>
            <flux:button variant="primary" :href="route('dashboard.posts.create')" icon="plus" wire:navigate>
                New post
            </flux:button>
        </x-slot:actions>
    </x-dashboard.header>

    <div class="space-y-8 px-6 py-8 lg:px-10">
        {{-- ============================================================= --
         | Metrics
         --}}
        <section aria-labelledby="metrics-heading">
            <h2 id="metrics-heading" class="sr-only">Content metrics</h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <x-dashboard.stat
                    label="Published"
                    :value="$this->metrics->publishedPosts"
                    icon="globe-alt"
                    hint="Live on the public site"
                    :href="route('dashboard.posts.index', ['status' => 'published'])"
                />

                <x-dashboard.stat
                    label="Drafts"
                    :value="$this->metrics->draftPosts"
                    icon="pencil-square"
                    hint="Work in progress"
                    :href="route('dashboard.posts.index', ['status' => 'draft'])"
                />

                <x-dashboard.stat
                    label="Awaiting moderation"
                    :value="$this->metrics->pendingComments"
                    icon="shield-exclamation"
                    :tone="$this->metrics->pendingComments > 0 ? 'attention' : 'default'"
                    :hint="$this->metrics->pendingComments > 0 ? 'Needs your review' : 'Queue is clear'"
                    :href="route('dashboard.comments')"
                />

                <x-dashboard.stat
                    label="Total views"
                    :value="$this->metrics->totalViews"
                    icon="eye"
                    hint="Across all posts"
                />

                <x-dashboard.stat
                    label="Comments"
                    :value="$this->metrics->totalComments"
                    icon="chat-bubble-left-right"
                    hint="All statuses"
                    :href="route('dashboard.comments')"
                />

                <x-dashboard.stat
                    label="Archived"
                    :value="$this->metrics->archivedPosts"
                    icon="archive-box"
                    hint="Retired but retained"
                    :href="route('dashboard.posts.index', ['status' => 'archived'])"
                />
            </div>
        </section>

        <div class="grid gap-8 xl:grid-cols-2">
            {{-- ========================================================= --
             | Moderation queue
             --}}
            <section aria-labelledby="queue-heading" class="rounded-lg border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
                <div class="flex items-center justify-between gap-4 border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                    <h2 id="queue-heading" class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        Moderation queue
                    </h2>

                    @if ($this->metrics->pendingComments > 0)
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 font-mono text-2xs font-semibold tabular-nums text-amber-700 dark:bg-amber-950 dark:text-amber-400">
                            {{ $this->metrics->pendingComments }}
                        </span>
                    @endif
                </div>

                @if ($this->pendingComments->isEmpty())
                    <div class="px-5 py-10 text-center">
                        <p class="text-sm text-ink-500 dark:text-ink-400">Nothing waiting. The queue is clear.</p>
                    </div>
                @else
                    <ul class="divide-y divide-ink-200 dark:divide-ink-800">
                        @foreach ($this->pendingComments as $comment)
                            <li class="px-5 py-4">
                                <div class="flex items-start gap-3">
                                    <div class="grid size-7 shrink-0 place-items-center rounded-md border border-ink-200 bg-ink-50 font-mono text-2xs font-semibold text-ink-500 dark:border-ink-700 dark:bg-ink-800 dark:text-ink-300">
                                        {{ Str::of($comment->user->name)->substr(0, 1)->upper() }}
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium text-ink-900 dark:text-ink-50">
                                            {{ $comment->user->name }}
                                            <span class="font-normal text-ink-400 dark:text-ink-500">on</span>
                                            {{ Str::limit($comment->post->title, 42) }}
                                        </p>
                                        <p class="mt-1.5 line-clamp-2 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                            {{ $comment->body }}
                                        </p>
                                        <p class="mt-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                                            {{ $comment->created_at->diffForHumans() }}
                                        </p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="border-t border-ink-200 px-5 py-3 dark:border-ink-800">
                        <a
                            href="{{ route('dashboard.comments') }}"
                            wire:navigate
                            class="font-mono text-2xs font-medium text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400 dark:hover:text-signal-300"
                        >
                            Review all →
                        </a>
                    </div>
                @endif
            </section>

            {{-- ========================================================= --
             | Recently edited
             --}}
            <section aria-labelledby="recent-heading" class="rounded-lg border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
                <div class="flex items-center justify-between gap-4 border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                    <h2 id="recent-heading" class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        Recently edited
                    </h2>

                    <a
                        href="{{ route('dashboard.posts.index') }}"
                        wire:navigate
                        class="font-mono text-2xs font-medium text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400 dark:hover:text-signal-300"
                    >
                        All posts →
                    </a>
                </div>

                @if ($this->recentPosts->isEmpty())
                    <div class="px-5 py-10 text-center">
                        <p class="text-sm text-ink-500 dark:text-ink-400">No posts yet.</p>
                        <flux:button class="mt-4" variant="primary" size="sm" :href="route('dashboard.posts.create')" icon="plus" wire:navigate>
                            Write the first one
                        </flux:button>
                    </div>
                @else
                    <ul class="divide-y divide-ink-200 dark:divide-ink-800">
                        @foreach ($this->recentPosts as $post)
                            <li>
                                <a
                                    href="{{ route('dashboard.posts.edit', $post) }}"
                                    wire:navigate
                                    class="flex items-center gap-4 px-5 py-3.5 transition-colors hover:bg-ink-50 dark:hover:bg-ink-950/60"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-ink-900 dark:text-ink-50">
                                            {{ $post->title }}
                                        </p>
                                        <p class="mt-0.5 font-mono text-2xs text-ink-400 dark:text-ink-500">
                                            {{ $post->category?->name ?? 'Uncategorised' }}
                                            · updated {{ $post->updated_at->diffForHumans() }}
                                        </p>
                                    </div>

                                    <flux:badge :color="$post->status->badgeColor()" size="sm" class="shrink-0">
                                        {{ $post->status->label() }}
                                    </flux:badge>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        {{-- ============================================================= --
         | Most read
         --}}
        @if ($this->topPosts->isNotEmpty())
            <section aria-labelledby="top-heading" class="rounded-lg border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
                <div class="border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                    <h2 id="top-heading" class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        Most read
                    </h2>
                </div>

                <ol class="divide-y divide-ink-200 dark:divide-ink-800">
                    @foreach ($this->topPosts as $index => $post)
                        <li class="flex items-center gap-4 px-5 py-3.5">
                            <span class="w-6 shrink-0 font-mono text-2xs tabular-nums text-ink-400 dark:text-ink-600">
                                {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                            </span>

                            <a
                                href="{{ route('blog.show', $post) }}"
                                target="_blank"
                                rel="noopener"
                                class="min-w-0 flex-1 truncate text-sm text-ink-700 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
                            >
                                {{ $post->title }}
                            </a>

                            <span class="shrink-0 font-mono text-2xs tabular-nums text-ink-500 dark:text-ink-400">
                                {{ number_format($post->view_count) }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        {{-- ============================================================= --
         | Posts needing moderation, grouped by post
         --}}
        @if ($this->postsNeedingModeration->isNotEmpty())
            <section aria-labelledby="by-post-heading" class="rounded-lg border border-amber-300/60 bg-amber-50/40 p-5 dark:border-amber-500/30 dark:bg-amber-950/20">
                <h2 id="by-post-heading" class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                    Posts with comments waiting
                </h2>

                <ul class="mt-4 space-y-2">
                    @foreach ($this->postsNeedingModeration as $post)
                        <li class="flex items-center justify-between gap-4">
                            <a
                                href="{{ route('dashboard.comments', ['post' => $post->id]) }}"
                                wire:navigate
                                class="min-w-0 truncate text-sm text-ink-700 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
                            >
                                {{ $post->title }}
                            </a>
                            <span class="shrink-0 font-mono text-2xs tabular-nums text-amber-700 dark:text-amber-400">
                                {{ $post->pending_count }} waiting
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
