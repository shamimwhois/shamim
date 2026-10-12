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
new #[Layout('layouts::app.dashboard')] #[Title('Dashboard')] class extends Component
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
    <x-app.ui.header
        eyebrow="Overview"
        :title="'Welcome back, '.Str::of(auth()->user()->name)->explode(' ')->first()"
        description="A snapshot of your writing: what is published, what is waiting, and what is getting read."
        :breadcrumbs="[['label' => __('Overview')]]"
    >
        <x-slot:actions>
            <flux:button variant="primary" :href="route('dashboard.posts.create')" icon="plus" wire:navigate>
                {{ __('New post') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.body>
        {{-- ============================================================= --
         | Metrics
         --}}
        <section aria-labelledby="metrics-heading">
            <h2 id="metrics-heading" class="sr-only">{{ __('Content metrics') }}</h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <x-app.ui.stat
                    label="Published"
                    :value="$this->metrics->publishedPosts"
                    icon="globe-alt"
                    hint="Live on the public site"
                    :href="route('dashboard.posts.index', ['status' => 'published'])"
                />

                <x-app.ui.stat
                    label="Drafts"
                    :value="$this->metrics->draftPosts"
                    icon="pencil-square"
                    hint="Work in progress"
                    :href="route('dashboard.posts.index', ['status' => 'draft'])"
                />

                <x-app.ui.stat
                    label="Awaiting moderation"
                    :value="$this->metrics->pendingComments"
                    icon="shield-exclamation"
                    :tone="$this->metrics->pendingComments > 0 ? 'attention' : 'default'"
                    :hint="$this->metrics->pendingComments > 0 ? 'Needs your review' : 'Queue is clear'"
                    :href="route('dashboard.comments')"
                />

                <x-app.ui.stat
                    label="Total views"
                    :value="$this->metrics->totalViews"
                    icon="eye"
                    hint="Across all posts"
                />

                <x-app.ui.stat
                    label="Comments"
                    :value="$this->metrics->totalComments"
                    icon="chat-bubble-left-right"
                    hint="All statuses"
                    :href="route('dashboard.comments')"
                />

                <x-app.ui.stat
                    label="Archived"
                    :value="$this->metrics->archivedPosts"
                    icon="archive-box"
                    hint="Retired but retained"
                    :href="route('dashboard.posts.index', ['status' => 'archived'])"
                />
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            {{-- ========================================================= --
             | Moderation queue
             --}}
            <x-app.ui.section
                :title="__('Moderation queue')"
                icon="shield-exclamation"
                :tone="$this->metrics->pendingComments > 0 ? 'attention' : 'default'"
                :padded="false"
                aria-labelledby="queue-heading"
            >
                <x-slot:actions>
                    @if ($this->metrics->pendingComments > 0)
                        <span class="sui-chip-warning">
                            {{ $this->metrics->pendingComments }}
                        </span>
                    @endif
                </x-slot:actions>

                @if ($this->pendingComments->isEmpty())
                    <div class="sui-empty">
                        <div class="mx-auto grid size-10 place-items-center rounded-full border border-emerald-200 bg-sui-success-soft text-sui-success">
                            <flux:icon name="check" class="size-5" />
                        </div>
                        <p class="mt-3 text-sm font-medium text-ink-700">{{ __('The queue is clear.') }}</p>
                        <p class="mt-1 text-xs leading-normal text-ink-500">{{ __('Nothing is waiting for your review.') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-sui-hairline">
                        @foreach ($this->pendingComments as $comment)
                            <li class="sui-row-lg">
                                <div class="flex items-start gap-3">
                                    <div class="grid size-7 shrink-0 place-items-center rounded-md border border-sui-hairline bg-sui-well text-2xs font-semibold text-ink-500">
                                        {{ Str::of($comment->user->name)->substr(0, 1)->upper() }}
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium text-ink-900">
                                            {{ $comment->user->name }}
                                            <span class="font-normal text-ink-400">{{ __('on') }}</span>
                                            {{ Str::limit($comment->post->title, 42) }}
                                        </p>
                                        <p class="mt-1.5 line-clamp-2 text-sm leading-normal text-ink-500">
                                            {{ $comment->body }}
                                        </p>
                                        <p class="mt-2 text-2xs text-ink-500">
                                            {{ $comment->created_at->diffForHumans() }}
                                        </p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="border-t border-sui-hairline px-5 py-3">
                        <a
                            href="{{ route('dashboard.comments') }}"
                            wire:navigate
                            class="sui-link text-xs"
                        >
                            {{ __('Review all') }} &rarr;
                        </a>
                    </div>
                @endif
            </x-app.ui.section>

            {{-- ========================================================= --
             | Recently edited
             --}}
            <x-app.ui.section
                :title="__('Recently edited')"
                icon="pencil-square"
                :padded="false"
                aria-labelledby="recent-heading"
            >
                <x-slot:actions>
                    <a
                        href="{{ route('dashboard.posts.index') }}"
                        wire:navigate
                        class="sui-link text-xs"
                    >
                        {{ __('All posts') }} &rarr;
                    </a>
                </x-slot:actions>

                @if ($this->recentPosts->isEmpty())
                    <div class="sui-empty">
                        <div class="mx-auto grid size-10 place-items-center rounded-md border border-sui-hairline bg-sui-well text-ink-400">
                            <flux:icon name="document-text" class="size-5" />
                        </div>
                        <p class="mt-3 text-sm font-medium text-ink-700">{{ __('No posts yet.') }}</p>
                        <flux:button class="mt-4" variant="primary" size="sm" :href="route('dashboard.posts.create')" icon="plus" wire:navigate>
                            {{ __('Write the first one') }}
                        </flux:button>
                    </div>
                @else
                    <ul class="divide-y divide-sui-hairline">
                        @foreach ($this->recentPosts as $post)
                            <li>
                                <a
                                    href="{{ route('dashboard.posts.edit', $post) }}"
                                    wire:navigate
                                    class="sui-row-lg flex items-center gap-4 transition duration-150 ease-soft hover:bg-sui-hover"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-ink-900">
                                            {{ $post->title }}
                                        </p>
                                        <p class="mt-0.5 text-2xs text-ink-500">
                                            {{ $post->category?->name ?? 'Uncategorised' }}
                                            &middot; {{ __('updated') }} {{ $post->updated_at->diffForHumans() }}
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
            </x-app.ui.section>
        </div>

        {{-- ============================================================= --
         | Most read
         --}}
        @if ($this->topPosts->isNotEmpty())
            <x-app.ui.section :title="__('Most read')" icon="eye" :padded="false" aria-labelledby="top-heading">
                <ol class="divide-y divide-sui-hairline">
                    @foreach ($this->topPosts as $index => $post)
                        <li class="sui-row-lg flex items-center gap-4">
                            <span class="w-6 shrink-0 text-2xs tabular-nums text-ink-500">
                                {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                            </span>

                            <a
                                href="{{ route('blog.show', $post) }}"
                                target="_blank"
                                rel="noopener"
                                class="min-w-0 flex-1 truncate text-sm text-ink-700 transition duration-150 ease-soft hover:text-sui-accent-ink"
                            >
                                {{ $post->title }}
                            </a>

                            <span class="shrink-0 text-2xs tabular-nums text-ink-600">
                                {{ number_format($post->view_count) }}
                            </span>
                        </li>
                    @endforeach
                </ol>
            </x-app.ui.section>
        @endif

        {{-- ============================================================= --
         | Posts needing moderation, grouped by post
         --}}
        @if ($this->postsNeedingModeration->isNotEmpty())
            <x-app.ui.section
                :title="__('Posts with comments waiting')"
                icon="chat-bubble-left-right"
                tone="attention"
                aria-labelledby="by-post-heading"
            >
                <ul class="space-y-2">
                    @foreach ($this->postsNeedingModeration as $post)
                        <li class="sui-row !py-2 flex items-center justify-between gap-4">
                            <a
                                href="{{ route('dashboard.comments', ['post' => $post->id]) }}"
                                wire:navigate
                                class="min-w-0 truncate text-sm text-ink-700 transition duration-150 ease-soft hover:text-sui-accent-ink"
                            >
                                {{ $post->title }}
                            </a>
                            <span class="sui-chip-warning">
                                {{ $post->pending_count }} {{ __('waiting') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-app.ui.section>
        @endif
    </x-app.ui.body>
</div>
