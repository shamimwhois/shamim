<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The community feed — social + forum hybrid.
 *
 * A developer feed with the rhythm of a social timeline and the structure of
 * a forum: topics, question/discussion/showcase types, solved state, a topics
 * rail with live counts, trending tags, a contributor leaderboard and a
 * guidelines card. Topic, ordering and search are URL-bound Livewire state
 * (`?category=`, `?sort=`, `?q=`), so every view is shareable, and Load more
 * pages through the filtered set.
 */
new #[Title('Feed')] class extends Component
{
    /** Cards shown per page; Load more raises it. */
    private const PAGE_SIZE = 3;

    /** Active topic slug; null = all topics. Bound to `?category=`. */
    #[Url]
    public ?string $category = null;

    /** Feed ordering: latest | top | unanswered. Bound to `?sort=`. */
    #[Url]
    public string $sort = 'latest';

    /** Full-text filter across author, body and tags. Bound to `?q=`. */
    #[Url]
    public string $q = '';

    /** How many filtered cards the feed currently renders. */
    public int $visible = self::PAGE_SIZE;

    /** A crafted `?category=hack` or `?sort=x` degrades to the default view. */
    public function mount(): void
    {
        if ($this->category !== null && ! in_array($this->category, array_column($this->topics(), 'slug'), true)) {
            $this->category = null;
        }

        if (! in_array($this->sort, ['latest', 'top', 'unanswered'], true)) {
            $this->sort = 'latest';
        }
    }

    /** Any filter change restarts paging from the top of the new set. */
    public function updatedCategory(): void
    {
        $this->visible = self::PAGE_SIZE;
    }

    public function updatedSort(): void
    {
        $this->visible = self::PAGE_SIZE;
    }

    public function updatedQ(): void
    {
        $this->visible = self::PAGE_SIZE;
    }

    public function loadMore(): void
    {
        $this->visible += self::PAGE_SIZE;
    }

    /** Reset every filter at once; used by the empty state. */
    public function clearFilters(): void
    {
        $this->category = null;
        $this->sort = 'latest';
        $this->q = '';
        $this->visible = self::PAGE_SIZE;
    }

    /**
     * Feed topics with live counts, derived from the demo corpus.
     *
     * @return array<int, array{slug: string, label: string, count: int}>
     */
    public function topics(): array
    {
        $counts = [];
        foreach ($this->posts() as $post) {
            $counts[$post['topic']] = ($counts[$post['topic']] ?? 0) + 1;
        }

        $topics = [
            ['slug' => 'backend', 'label' => 'Backend'],
            ['slug' => 'frontend', 'label' => 'Frontend'],
            ['slug' => 'tooling', 'label' => 'Tooling'],
            ['slug' => 'community', 'label' => 'Community'],
        ];

        return array_map(function (array $topic) use ($counts): array {
            $topic['count'] = $counts[$topic['slug']] ?? 0;

            return $topic;
        }, $topics);
    }

    /**
     * Demo corpus: six posts across topics, types and solved states.
     *
     * @return array<int, array<string, mixed>>
     */
    public function posts(): array
    {
        return [
            [
                'id' => 1, 'author' => 'Shamim Hossain', 'handle' => '@shamim', 'avatar' => 'SH',
                'time' => '2h ago', 'topic' => 'backend', 'type' => 'showcase', 'answered' => true, 'score' => 210,
                'body' => 'Shipped a small but satisfying refactor today: pulled the N+1 out of the dashboard post list by eager-loading category + tags in a single query. Page went from 140ms to 18ms. The boring wins are the best wins.',
                'tags' => ['Laravel', 'Performance', 'Eloquent'],
                'reactions' => ['like' => 24, 'love' => 6, 'insight' => 3],
                'comments' => 14,
            ],
            [
                'id' => 2, 'author' => 'Priya Menon', 'handle' => '@priya', 'avatar' => 'PM',
                'time' => '5h ago', 'topic' => 'frontend', 'type' => 'discussion', 'answered' => false, 'score' => 96,
                'body' => 'Hot take: most "design systems" are just component libraries with a marketing page. The actual system is the decisions — spacing scale, type ramp, motion language — not the button variants. (Yes, I will die on this hill.)',
                'tags' => ['Design', 'Systems', 'Opinion'],
                'reactions' => ['like' => 41, 'love' => 12, 'insight' => 9],
                'comments' => 7,
            ],
            [
                'id' => 3, 'author' => 'Kenji Sato', 'handle' => '@kenji', 'avatar' => 'KS',
                'time' => '1d ago', 'topic' => 'tooling', 'type' => 'question', 'answered' => true, 'score' => 88,
                'body' => 'SOLVED: if you are on Tailwind v4 and your dark mode classes stopped working, check that you moved from `darkMode: \'class\'` to the new `@custom-variant dark` syntax. Spent an hour on this so you do not have to.',
                'tags' => ['Tailwind', 'CSS', 'Tips'],
                'reactions' => ['like' => 89, 'love' => 4, 'insight' => 31],
                'comments' => 5,
            ],
            [
                'id' => 4, 'author' => 'Daniel Okafor', 'handle' => '@danielo', 'avatar' => 'DO',
                'time' => '2d ago', 'topic' => 'backend', 'type' => 'question', 'answered' => false, 'score' => 12,
                'body' => 'Best practice for chunking a 500k-row export? `lazy()` seems to hold the connection hostage in worker queues — anyone benchmarked `chunkById` vs `lazyById` on a busy primary?',
                'tags' => ['Queues', 'Eloquent', 'Help'],
                'reactions' => ['like' => 8, 'love' => 1, 'insight' => 3],
                'comments' => 2,
            ],
            [
                'id' => 5, 'author' => 'Aisha Rahman', 'handle' => '@aisha', 'avatar' => 'AR',
                'time' => '3d ago', 'topic' => 'community', 'type' => 'showcase', 'answered' => true, 'score' => 158,
                'body' => 'Just cut v1.0 of our starter kit: auth scaffolding, role gates and a tested CI pipeline. Ten months of weekend nights in a git tag. Docs are rough; feedback wildly welcome.',
                'tags' => ['OpenSource', 'Release'],
                'reactions' => ['like' => 96, 'love' => 40, 'insight' => 22],
                'comments' => 23,
            ],
            [
                'id' => 6, 'author' => 'Tomás Herrera', 'handle' => '@tomas', 'avatar' => 'TH',
                'time' => '4d ago', 'topic' => 'tooling', 'type' => 'discussion', 'answered' => false, 'score' => 74,
                'body' => 'The tools I trust are the boring ones: ripgrep, fd, make, jq. Every shiny dashboard eventually becomes a shell alias. What "boring" tool quietly carries your whole workflow?',
                'tags' => ['Cli', 'Workflow', 'Opinion'],
                'reactions' => ['like' => 30, 'love' => 8, 'insight' => 14],
                'comments' => 11,
            ],
        ];
    }

    /**
     * Corpus filtered by the URL-bound topic, search and ordering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function filteredPosts(): array
    {
        $posts = $this->posts();

        if ($this->category !== null) {
            $posts = array_values(array_filter(
                $posts,
                fn (array $post): bool => $post['topic'] === $this->category
            ));
        }

        $needle = mb_strtolower(trim($this->q));
        if ($needle !== '') {
            $posts = array_values(array_filter(
                $posts,
                fn (array $post): bool => str_contains(
                    mb_strtolower($post['author'].' '.$post['body'].' '.implode(' ', $post['tags'])),
                    $needle
                )
            ));
        }

        if ($this->sort === 'top') {
            usort($posts, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        } elseif ($this->sort === 'unanswered') {
            $posts = array_values(array_filter(
                $posts,
                fn (array $post): bool => $post['type'] === 'question' && ! $post['answered']
            ));
        }

        return $posts;
    }

    /**
     * The slice of filtered posts the feed currently renders.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visiblePosts(): array
    {
        return array_slice($this->filteredPosts(), 0, $this->visible);
    }

    /**
     * Tag frequencies across the whole corpus, hottest first.
     *
     * @return array<int, array{tag: string, count: int}>
     */
    public function trendingTags(): array
    {
        $counts = [];
        foreach ($this->posts() as $post) {
            foreach ($post['tags'] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        arsort($counts);

        return array_map(
            fn (string $tag, int $count): array => ['tag' => $tag, 'count' => $count],
            array_keys($counts),
            $counts
        );
    }

    /**
     * Contributor leaderboard: cards published and score earned, top four.
     *
     * @return array<int, array{author: string, handle: string, avatar: string, posts: int, score: int}>
     */
    public function contributors(): array
    {
        $totals = [];
        foreach ($this->posts() as $post) {
            if (! isset($totals[$post['handle']])) {
                $totals[$post['handle']] = [
                    'author' => $post['author'], 'handle' => $post['handle'],
                    'avatar' => $post['avatar'], 'posts' => 0, 'score' => 0,
                ];
            }
            $totals[$post['handle']]['posts']++;
            $totals[$post['handle']]['score'] += $post['score'];
        }

        usort($totals, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($totals, 0, 4);
    }

    /** How many cards are rendered versus how many match, for the count line. */
    public function hasMore(): bool
    {
        return count($this->filteredPosts()) > $this->visible;
    }
}; ?>

<div>
    {{-- ================================================================== --}}
    {{-- 01 — Hero                                                          --}}
    {{-- ================================================================== --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-16 lg:py-20">
            <x-app.ui.section-marker :number="1" label="Feed" accent />

            <div class="mt-8 grid items-end gap-10 lg:grid-cols-[minmax(0,7fr)_minmax(0,4fr)] lg:gap-16">
                <div>
                    <h1 class="max-w-3xl text-[clamp(2.25rem,5vw,3.75rem)] font-semibold leading-[1.02] tracking-tightest text-ink-900 dark:text-ink-50">
                        Where the people who build things compare&nbsp;notes.
                    </h1>

                    <p class="mt-6 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                        {{ __('A community feed tuned for engineers, designers and tech people: wins worth shipping, questions worth solving and threads worth following. The rhythm of a social timeline, the structure of a forum.') }}
                    </p>
                </div>

                <dl class="grid grid-cols-3 gap-px overflow-hidden rounded-lg border border-ink-200 bg-ink-200 dark:border-ink-800 dark:bg-ink-800">
                    <div class="bg-white p-4 dark:bg-ink-950">
                        <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Posts') }}</dt>
                        <dd class="mt-2 font-mono text-2xl font-semibold tabular-nums text-ink-900 dark:text-ink-50">{{ count($this->posts()) }}</dd>
                    </div>
                    <div class="bg-white p-4 dark:bg-ink-950">
                        <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Solved') }}</dt>
                        <dd class="mt-2 font-mono text-2xl font-semibold tabular-nums text-signal-600 dark:text-signal-400">
                            {{ collect($this->posts())->where('answered', true)->count() }}
                        </dd>
                    </div>
                    <div class="bg-white p-4 dark:bg-ink-950">
                        <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('People') }}</dt>
                        <dd class="mt-2 font-mono text-2xl font-semibold tabular-nums text-ink-900 dark:text-ink-50">
                            {{ collect($this->posts())->pluck('handle')->unique()->count() }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    {{-- ================================================================== --}}
    {{-- 02 — The feed                                                      --}}
    {{-- ================================================================== --}}
    <section data-reveal class="shell py-16 lg:py-20" aria-labelledby="feed-heading">
        <x-app.ui.section-marker :number="2" label="Community" />

        <h2 id="feed-heading" class="mt-6 max-w-2xl text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
            {{ __('Today on the feed') }}
        </h2>

        <div class="mt-10 grid items-start gap-10 lg:grid-cols-[minmax(0,1fr)_18rem] lg:gap-12">
            {{-- Main column ------------------------------------------------ --}}
            <div class="min-w-0">
                {{-- Filter toolbar: search + segmented sort. Both are
                     server state, both are shareable URLs. --}}
                <div class="flex flex-col gap-3 border-b border-ink-200 pb-5 dark:border-ink-800 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative sm:w-72">
                        <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-400 dark:text-ink-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <label class="sr-only" for="feed-search">{{ __('Search the feed') }}</label>
                        <input
                            id="feed-search"
                            type="search"
                            wire:model.live.debounce.350ms="q"
                            placeholder="{{ __('Search posts, people, tags…') }}"
                            value="{{ $q }}"
                            class="focus-ring h-10 w-full rounded-full border border-ink-200 bg-white pl-9 pr-4 text-sm text-ink-900 placeholder:text-ink-400 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-50 dark:placeholder:text-ink-500"
                        />
                    </div>

                    <div class="flex items-center gap-1 rounded-full border border-ink-200 p-1 dark:border-ink-800" role="group" aria-label="{{ __('Sort posts') }}">
                        @foreach (['latest' => __('Latest'), 'top' => __('Top'), 'unanswered' => __('Unanswered')] as $key => $label)
                            <button
                                type="button"
                                wire:click="$set('sort', '{{ $key }}')"
                                @class([
                                    'focus-ring min-h-8 rounded-full px-3.5 font-mono text-2xs uppercase tracking-widest transition-colors',
                                    'bg-ink-900 text-white dark:bg-ink-100 dark:text-ink-900' => $sort === $key,
                                    'text-ink-500 hover:text-ink-900 dark:text-ink-400 dark:hover:text-ink-100' => $sort !== $key,
                                ])
                                aria-pressed="{{ $sort === $key ? 'true' : 'false' }}"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <p class="mt-4 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500" aria-live="polite">
                    {{ count($this->filteredPosts()) }} {{ __('matching posts') }} · {{ __('showing') }} {{ count($this->visiblePosts()) }}
                </p>

                {{-- Composer --}}
                <div class="card-surface mt-5 p-4 sm:p-5" aria-labelledby="composer-heading">
                    <h3 id="composer-heading" class="sr-only">{{ __('Create a post') }}</h3>
                    <div class="flex items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-signal-100 font-mono text-sm font-semibold text-signal-700 dark:bg-signal-900/40 dark:text-signal-300">SH</span>
                        <div class="flex-1">
                            <textarea
                                rows="2"
                                placeholder="{{ __('Ask a question, share a win, or start a thread…') }}"
                                class="w-full resize-none rounded-md border border-ink-200 bg-ink-50/60 px-3 py-2 text-sm text-ink-900 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-50 dark:placeholder:text-ink-500"
                            ></textarea>
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                <div class="flex items-center gap-1" role="group" aria-label="{{ __('Post type') }}">
                                    @foreach (['question', 'discussion', 'showcase'] as $type)
                                        <button type="button" class="focus-ring rounded-full px-3 py-1.5 font-mono text-2xs uppercase tracking-widest text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-900 dark:hover:text-ink-100">
                                            {{ $type }}
                                        </button>
                                    @endforeach
                                </div>
                                <button type="button" class="focus-ring inline-flex min-h-10 items-center rounded-full bg-signal-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-signal-700 dark:bg-signal-500 dark:hover:bg-signal-400">
                                    {{ __('Post to feed') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Cards --}}
                <div class="mt-6 space-y-5" aria-live="polite">
                    @forelse ($this->visiblePosts() as $post)
                        <article class="card-surface shadow-soft-1" wire:key="post-{{ $post['id'] }}" x-data="{ open: false, liked: false, saved: false }">
                            <header class="flex flex-wrap items-start justify-between gap-3 p-4 pb-0 sm:p-5 sm:pb-0">
                                <div class="flex items-start gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-ink-100 font-mono text-sm font-semibold text-ink-700 dark:bg-ink-900 dark:text-ink-200">
                                        {{ $post['avatar'] }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ $post['author'] }}</p>
                                        <p class="font-mono text-2xs text-ink-400 dark:text-ink-500">{{ $post['handle'] }} · {{ $post['time'] }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="tag-pill">{{ $post['topic'] }}</span>
                                    <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ $post['type'] }}</span>
                                </div>
                            </header>

                            <div class="px-4 py-4 sm:px-5">
                                <p class="text-sm leading-relaxed text-ink-800 dark:text-ink-200">{{ $post['body'] }}</p>

                                @if (! empty($post['tags']))
                                    <ul class="mt-3 flex flex-wrap gap-1.5">
                                        @foreach ($post['tags'] as $tag)
                                            <li><span class="tag-pill">#{{ $tag }}</span></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            {{-- Meta bar: score, reactions, thread, solved state --}}
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-ink-100 px-4 py-2.5 text-2xs text-ink-500 dark:border-ink-800 dark:text-ink-400 sm:px-5">
                                <span class="font-mono tabular-nums" title="{{ __('Community score') }}">↑ {{ number_format($post['score']) }}</span>
                                <span class="inline-flex items-center gap-1" aria-label="{{ __('Reactions') }}">
                                    <span aria-hidden="true">👍</span><span aria-hidden="true">❤️</span><span aria-hidden="true">💡</span>
                                    <span class="font-mono tabular-nums">{{ $post['reactions']['like'] + $post['reactions']['love'] + $post['reactions']['insight'] }}</span>
                                </span>
                                <button type="button" class="focus-ring font-mono transition-colors hover:text-signal-700 dark:hover:text-signal-400" @click="open = ! open">
                                    {{ __('Thread') }} · {{ $post['comments'] }}
                                </button>
                                <span class="ml-auto">
                                    @if ($post['type'] === 'question')
                                        @if ($post['answered'])
                                            <span class="inline-flex items-center gap-1 rounded-full border border-signal-300 bg-signal-50 px-2 py-0.5 font-mono text-2xs font-medium uppercase tracking-widest text-signal-700 dark:border-signal-800 dark:bg-signal-950 dark:text-signal-300">
                                                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4.5 12.75l6 6 9-13.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                {{ __('Solved') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full border border-ink-200 px-2 py-0.5 font-mono text-2xs uppercase tracking-widest text-ink-500 dark:border-ink-700 dark:text-ink-400">
                                                {{ __('Unanswered') }}
                                            </span>
                                        @endif
                                    @endif
                                </span>
                            </div>

                            {{-- Thread --}}
                            <div x-show="open" x-collapse class="border-t border-ink-100 px-4 py-4 dark:border-ink-800 sm:px-5">
                                <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Recent replies') }}</p>
                                <ul class="mt-3 space-y-3">
                                    <li class="flex items-start gap-2">
                                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ink-100 font-mono text-2xs font-semibold text-ink-700 dark:bg-ink-900 dark:text-ink-200">
                                            {{ strtoupper(substr($post['author'], 0, 2)) }}
                                        </span>
                                        <div class="min-w-0 flex-1 rounded-lg bg-ink-50 px-3 py-2 dark:bg-ink-900">
                                            <p class="text-2xs leading-relaxed text-ink-600 dark:text-ink-300">
                                                {{ __('The full conversation lives in the thread — this preview shows the opening post only.') }}
                                            </p>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </article>
                    @empty
                        {{-- Empty state --}}
                        <div class="card-surface grid place-items-center px-6 py-16 text-center">
                            <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('No matching posts') }}</p>
                            <p class="mt-3 max-w-sm text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                                {{ __('No posts match this topic, sort and search together. Loosen one filter and the feed comes back.') }}
                            </p>
                            <button
                                type="button"
                                wire:click="clearFilters"
                                class="focus-ring mt-6 inline-flex min-h-10 items-center rounded-full border border-ink-200 px-5 py-2 text-sm font-medium text-ink-700 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:text-ink-200 dark:hover:border-signal-600 dark:hover:text-signal-300"
                            >
                                {{ __('Reset filters') }}
                            </button>
                        </div>
                    @endforelse
                </div>

                {{-- Load more --}}
                @if ($this->hasMore())
                    <div class="flex flex-col items-center gap-3 pt-2">
                        <button
                            type="button"
                            wire:click="loadMore"
                            class="focus-ring inline-flex min-h-10 items-center gap-2 rounded-full border border-ink-200 bg-white px-5 py-2 text-sm font-medium text-ink-600 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300 dark:hover:border-signal-600 dark:hover:text-signal-400"
                        >
                            {{ __('Load more posts') }}
                        </button>
                    </div>
                @endif
            </div>

            {{-- Rail ------------------------------------------------------ --}}
            <aside class="space-y-8 lg:sticky lg:top-10" aria-label="{{ __('Feed context') }}">
                <div class="card-surface p-5" aria-labelledby="topics-heading">
                    <h3 id="topics-heading" class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">{{ __('Topics') }}</h3>
                    <ul class="mt-3 space-y-1">
                        <li>
                            <button
                                type="button"
                                wire:click="$set('category', null)"
                                @class([
                                    'group flex w-full items-center justify-between rounded-md px-2.5 py-2 text-left text-sm transition-colors',
                                    'bg-ink-100 font-medium text-ink-900 dark:bg-ink-800 dark:text-ink-50' => $category === null,
                                    'text-ink-600 hover:bg-ink-50 hover:text-ink-900 dark:text-ink-300 dark:hover:bg-ink-900 dark:hover:text-ink-100' => $category !== null,
                                ])
                                aria-pressed="{{ $category === null ? 'true' : 'false' }}"
                            >
                                {{ __('All topics') }}
                                <span class="font-mono text-2xs tabular-nums text-ink-400 dark:text-ink-500">{{ count($this->posts()) }}</span>
                            </button>
                        </li>
                        @foreach ($this->topics() as $topic)
                            <li>
                                <button
                                    type="button"
                                    wire:click="$set('category', '{{ $topic['slug'] }}')"
                                    @class([
                                        'group flex w-full items-center justify-between rounded-md px-2.5 py-2 text-left text-sm transition-colors',
                                        'bg-ink-100 font-medium text-ink-900 dark:bg-ink-800 dark:text-ink-50' => $category === $topic['slug'],
                                        'text-ink-600 hover:bg-ink-50 hover:text-ink-900 dark:text-ink-300 dark:hover:bg-ink-900 dark:hover:text-ink-100' => $category !== $topic['slug'],
                                    ])
                                    aria-pressed="{{ $category === $topic['slug'] ? 'true' : 'false' }}"
                                    wire:key="topic-{{ $topic['slug'] }}"
                                >
                                    {{ $topic['label'] }}
                                    <span class="font-mono text-2xs tabular-nums text-ink-400 dark:text-ink-500">{{ $topic['count'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="card-surface p-5" aria-labelledby="tags-heading">
                    <h3 id="tags-heading" class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">{{ __('Trending tags') }}</h3>
                    <ul class="mt-3 flex flex-wrap gap-1.5">
                        @foreach (array_slice($this->trendingTags(), 0, 10) as $trend)
                            <li wire:key="tag-{{ $trend['tag'] }}">
                                <button
                                    type="button"
                                    wire:click="$set('q', '{{ $trend['tag'] }}')"
                                    class="tag-pill"
                                    aria-label="{{ __('Filter by tag :tag', ['tag' => $trend['tag']]) }}"
                                >
                                    #{{ $trend['tag'] }} <span class="tabular-nums text-ink-400 dark:text-ink-500">{{ $trend['count'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="card-surface p-5" aria-labelledby="people-heading">
                    <h3 id="people-heading" class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">{{ __('Top contributors') }}</h3>
                    <ol class="mt-3 space-y-3">
                        @foreach ($this->contributors() as $index => $person)
                            <li class="flex items-center gap-3" wire:key="person-{{ $person['handle'] }}">
                                <span class="font-mono text-2xs tabular-nums text-ink-400 dark:text-ink-500">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ink-100 font-mono text-2xs font-semibold text-ink-700 dark:bg-ink-900 dark:text-ink-200">{{ $person['avatar'] }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-semibold text-ink-900 dark:text-ink-50">{{ $person['author'] }}</p>
                                    <p class="font-mono text-2xs text-ink-400 dark:text-ink-500">{{ $person['posts'] }} {{ __('posts') }}</p>
                                </div>
                                <span class="font-mono text-2xs tabular-nums text-signal-600 dark:text-signal-400">↑ {{ number_format($person['score']) }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <div class="card-surface p-5" aria-labelledby="rules-heading">
                    <h3 id="rules-heading" class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">{{ __('House guidelines') }}</h3>
                    <ul class="mt-3 space-y-2.5 text-xs leading-relaxed text-ink-600 dark:text-ink-300">
                        <li class="flex gap-2"><span class="font-mono text-2xs text-signal-600 dark:text-signal-400">01</span>{{ __('Questions get a clear title; the answer earns the solved mark.') }}</li>
                        <li class="flex gap-2"><span class="font-mono text-2xs text-signal-600 dark:text-signal-400">02</span>{{ __('Show your work — screenshots, snippets or numbers beat slogans.') }}</li>
                        <li class="flex gap-2"><span class="font-mono text-2xs text-signal-600 dark:text-signal-400">03</span>{{ __('Disagree with the idea, never the person.') }}</li>
                    </ul>
                </div>
            </aside>
        </div>
    </section>
</div>
