<?php

use App\Data\PostMetrics;
use App\Enums\AccountRole;
use App\Models\Post;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dashboard overview — a read-only summary of your own content.
 *
 * `/dashboard` is the user role's home and the safe landing spot for every
 * authenticated account, so it forwards a seller or an admin to the dashboard
 * their role owns:
 *
 *   user   → renders the overview below
 *   seller → redirects to `/seller/dashboard`
 *   admin  → redirects to `/admin/dashboard`
 *
 * The redirect is a real HTTP redirect rather than a conditional render so the
 * URL always names what is on screen, and so the sidebar's active state and
 * browser history both stay honest.
 *
 * Nothing here is actionable. Writing, the moderation queue, and taxonomy all
 * moved to `/admin` and are therefore unreachable from this page — an account
 * that lands here cannot open them, so the panels were removed rather than
 * wrapped in a condition that can never be true. What stays answers "how is my
 * content doing?" and not "what do I do next?".
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

    /**
     * Publishing activity: posts published per week for the last twelve
     * weeks, oldest first.
     *
     * Twelve buckets, because a quarter of weekly history is long enough to
     * show a trend and short enough that every bucket is meaningful for an
     * author who publishes a handful of times a month. A count per week
     * rather than per day for the same reason: daily buckets would be
     * almost entirely zero, and a chart of zeroes is noise.
     *
     * @return array{series: array<int, int>, labels: array<int, string>}
     */
    #[Computed]
    public function publishActivity(): array
    {
        $series = [];
        $labels = [];

        $start = now()->startOfWeek()->subWeeks(11);

        for ($week = 0; $week < 12; $week++) {
            $weekStart = $start->copy()->addWeeks($week);

            $series[] = Post::query()
                ->authoredBy(auth()->user())
                ->published()
                ->whereBetween('published_at', [$weekStart, $weekStart->copy()->endOfWeek()])
                ->count();

            $labels[] = $weekStart->format('M j');
        }

        return ['series' => $series, 'labels' => $labels];
    }
}; ?>

<div>
    <x-app.ui.body>
        {{-- ============================================================= --
         | Welcome
         |
         | The Creative Tim hero. It is the page's one gradient surface and
         | its one `h1`: the navbar crumb already names the workspace, so a
         | second heading band above this would be a duplicate. The old
         | `x-app.ui.header` is therefore gone from this page — the crumb
         | does its job higher up, in the navbar, where CT puts it.
         --}}
        <x-app.ui.welcome-card
            :level="1"
            eyebrow="Overview"
            :title="'Welcome back, '.Str::of(auth()->user()->name)->explode(' ')->first()"
            description="A snapshot of your writing: what is published, what is waiting, and what is getting read."
            icon="sparkles"
        />

        {{-- ============================================================= --
         | Metrics
         |
         | Tone is assigned per metric rather than per position, so a
         | metric's colour survives a reordering of the grid. The
         | moderation tile is the only one that reacts to its own value:
         | a clear queue is a quiet tile, not an amber one.
         |
         | The tiles report; they no longer link. Every destination they
         | used to point at is an `/admin` screen.
         --}}
        <section aria-labelledby="metrics-heading">
            <h2 id="metrics-heading" class="sr-only">{{ __('Content metrics') }}</h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <x-app.ui.stat
                    label="Published"
                    :value="$this->metrics->publishedPosts"
                    icon="globe-alt"
                    tone="success"
                    hint="Live on the public site"
                />

                <x-app.ui.stat
                    label="Drafts"
                    :value="$this->metrics->draftPosts"
                    icon="pencil-square"
                    tone="secondary"
                    hint="Work in progress"
                />

                <x-app.ui.stat
                    label="Awaiting moderation"
                    :value="$this->metrics->pendingComments"
                    icon="shield-exclamation"
                    :tone="$this->metrics->pendingComments > 0 ? 'attention' : 'primary'"
                    :hint="$this->metrics->pendingComments > 0 ? 'Needs your review' : 'Queue is clear'"
                />

                <x-app.ui.stat
                    label="Total views"
                    :value="$this->metrics->totalViews"
                    icon="eye"
                    tone="info"
                    hint="Across all posts"
                />

                <x-app.ui.stat
                    label="Comments"
                    :value="$this->metrics->totalComments"
                    icon="chat-bubble-left-right"
                    tone="primary"
                    hint="All statuses"
                />

                <x-app.ui.stat
                    label="Archived"
                    :value="$this->metrics->archivedPosts"
                    icon="archive-box"
                    tone="danger"
                    hint="Retired but retained"
                />
            </div>
        </section>

        {{-- ============================================================= --
         | Activity
         |
         | The one question this page still answers: "am I still shipping?".
         | It reads `published_at`, so it cannot drift from the database.
         --}}
        <x-app.ui.chart-card
            :title="__('Publishing activity')"
            :description="__('Posts published per week over the last twelve weeks.')"
            :series="$this->publishActivity()['series']"
            :labels="$this->publishActivity()['labels']"
            :empty="__('Nothing published in the last twelve weeks yet.')"
        />

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
    </x-app.ui.body>
</div>
