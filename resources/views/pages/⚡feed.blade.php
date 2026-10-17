<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The social feed.
 *
 * A developer-focused community feed: posts, comments, reactions and replies,
 * modelled on the rhythm of a Facebook/LinkedIn feed but tuned for engineers,
 * designers and tech people. The page is server-rendered Livewire; reactions and
 * comment toggles are handled with Alpine for instant feedback without a round
 * trip.
 */
new #[Title('Feed')] class extends Component
{
    /**
     * The feed posts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function posts(): array
    {
        return [
            [
                'id' => 1,
                'author' => 'Shamim Hossain',
                'handle' => '@shamim',
                'avatar' => 'SH',
                'time' => '2h ago',
                'body' => 'Shipped a small but satisfying refactor today: pulled the N+1 out of the dashboard post list by eager-loading category + tags in a single query. Page went from 140ms to 18ms. The boring wins are the best wins.',
                'tags' => ['Laravel', 'Performance', 'Eloquent'],
                'reactions' => ['like' => 24, 'love' => 6, 'insight' => 3],
                'comments' => [
                    [
                        'author' => 'Aisha Rahman',
                        'handle' => '@aisha',
                        'avatar' => 'AR',
                        'time' => '1h ago',
                        'body' => 'The `with()` vs `load()` distinction still trips people up. Nice to see it named explicitly.',
                        'replies' => [
                            [
                                'author' => 'Shamim Hossain',
                                'handle' => '@shamim',
                                'avatar' => 'SH',
                                'time' => '45m ago',
                                'body' => 'Agreed — I added a note in the PR about why `with()` wins here (single query vs. lazy per-row).',
                            ],
                        ],
                    ],
                    [
                        'author' => 'Daniel Okafor',
                        'handle' => '@danielo',
                        'avatar' => 'DO',
                        'time' => '50m ago',
                        'body' => 'Did you measure with Telescope or just the response time? Curious about the query count delta.',
                    ],
                ],
            ],
            [
                'id' => 2,
                'author' => 'Priya Menon',
                'handle' => '@priya',
                'avatar' => 'PM',
                'time' => '5h ago',
                'body' => 'Hot take: most "design systems" are just component libraries with a marketing page. The actual system is the decisions — spacing scale, type ramp, motion language — not the button variants. (Yes, I will die on this hill.)',
                'tags' => ['Design', 'Systems', 'Opinion'],
                'reactions' => ['like' => 41, 'love' => 12, 'insight' => 9],
                'comments' => [
                    [
                        'author' => 'Tomás Herrera',
                        'handle' => '@tomas',
                        'avatar' => 'TH',
                        'time' => '4h ago',
                        'body' => 'Strong agree. The button variants are the easy part. The hard part is "when do we use a dialog vs. a sheet vs. a toast".',
                    ],
                ],
            ],
            [
                'id' => 3,
                'author' => 'Shamim Hossain',
                'handle' => '@shamim',
                'avatar' => 'SH',
                'time' => '1d ago',
                'body' => 'Wrote up how we cut our queue backlog by 60% without adding workers: switched from `sync` retries to exponential backoff with a dead-letter queue, then tuned the visibility timeout to match p99 job duration. Full breakdown on the blog.',
                'tags' => ['Queues', 'Redis', 'Laravel'],
                'reactions' => ['like' => 67, 'love' => 18, 'insight' => 22],
                'comments' => [],
            ],
            [
                'id' => 4,
                'author' => 'Kenji Sato',
                'handle' => '@kenji',
                'avatar' => 'KS',
                'time' => '2d ago',
                'body' => 'PSA: if you are on Tailwind v4 and your dark mode classes stopped working, check that you moved from `darkMode: \'class\'` to the new `@custom-variant dark` syntax. Spent an hour on this so you do not have to.',
                'tags' => ['Tailwind', 'CSS', 'Tips'],
                'reactions' => ['like' => 89, 'love' => 4, 'insight' => 31],
                'comments' => [
                    [
                        'author' => 'Shamim Hossain',
                        'handle' => '@shamim',
                        'avatar' => 'SH',
                        'time' => '2d ago',
                        'body' => 'This. The migration guide buries it. Bookmarked.',
                    ],
                ],
            ],
        ];
    }
}; ?>

<div class="min-w-0">
    <div class="mx-auto w-full max-w-3xl px-5 py-8 sm:px-6 lg:px-8">
        <p class="font-mono text-2xs font-medium uppercase tracking-widest text-signal-600 dark:text-signal-400">{{ __('Community') }}</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">{{ __('Feed') }}</h1>
        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
            {{ __('A working feed for engineers, designers and tech people. Posts, comments, reactions and replies — the rhythm of a social timeline tuned for the people who build things.') }}
        </p>
    </div>

    <div class="mx-auto w-full max-w-3xl space-y-8 px-5 pb-12 sm:px-6 lg:px-8">
        {{-- Composer --}}
        <section class="rounded-xl border border-ink-200 bg-white p-4 shadow-soft-1 dark:border-ink-800 dark:bg-ink-950" aria-labelledby="composer-heading">
            <h2 id="composer-heading" class="sr-only">{{ __('Create a post') }}</h2>
            <div class="flex items-start gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-signal-100 font-mono text-sm font-semibold text-signal-700 dark:bg-signal-900/40 dark:text-signal-300">SH</span>
                <div class="flex-1">
                    <textarea
                        rows="2"
                        placeholder="{{ __('What are you building? Share a win, a lesson, or a question…') }}"
                        class="w-full resize-none rounded-md border border-ink-200 bg-ink-50/60 px-3 py-2 text-sm text-ink-900 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-50 dark:placeholder:text-ink-500"
                    ></textarea>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="focus-ring inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-900 dark:hover:text-ink-100">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.659 5.659m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ __('Media') }}
                            </button>
                            <button type="button" class="focus-ring inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-900 dark:hover:text-ink-100">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 6h.008v.008H6V6z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ __('Tag') }}
                            </button>
                            <button type="button" class="focus-ring inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-900 dark:hover:text-ink-100">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ __('Schedule') }}
                            </button>
                        </div>
                        <button type="button" class="focus-ring inline-flex min-h-9 items-center rounded-md bg-signal-600 px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-signal-700 dark:bg-signal-500 dark:hover:bg-signal-400">
                            {{ __('Post') }}
                        </button>
                    </div>
                </div>
            </div>
        </section>

        {{-- Feed --}}
        <section data-reveal class="space-y-5" aria-labelledby="feed-heading">
            <h2 id="feed-heading" class="sr-only">{{ __('Feed') }}</h2>

            @foreach ($this->posts() as $post)
                <article
                    class="rounded-xl border border-ink-200 bg-white shadow-soft-1 dark:border-ink-800 dark:bg-ink-950"
                    x-data="{ liked: false, saved: false, showComments: false }"
                >
                    {{-- Author row --}}
                    <header class="flex items-start justify-between gap-3 p-4 sm:p-5">
                        <div class="flex items-start gap-3">
                            <span class="grid size-11 shrink-0 place-items-center rounded-full bg-ink-100 font-mono text-sm font-semibold text-ink-700 dark:bg-ink-900 dark:text-ink-200">
                                {{ $post['avatar'] }}
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ $post['author'] }}</p>
                                <p class="font-mono text-2xs text-ink-400 dark:text-ink-500">{{ $post['handle'] }} · {{ $post['time'] }}</p>
                            </div>
                        </div>
                        <button type="button" class="focus-ring grid size-8 place-items-center rounded-md text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-900 dark:hover:bg-ink-900 dark:hover:text-ink-100" aria-label="{{ __('More options') }}">
                            <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 6.75a1.5 1.5 0 110-3 1.5 1.5 0 010 3zm0 6.75a1.5 1.5 0 110-3 1.5 1.5 0 010 3zm0 6.75a1.5 1.5 0 110-3 1.5 1.5 0 010 3z"/></svg>
                        </button>
                    </header>

                    {{-- Body --}}
                    <div class="px-4 pb-2 sm:px-5">
                        <p class="text-sm leading-relaxed text-ink-800 dark:text-ink-200">{{ $post['body'] }}</p>

                        @if (! empty($post['tags']))
                            <ul class="mt-3 flex flex-wrap gap-1.5">
                                @foreach ($post['tags'] as $tag)
                                    <li class="rounded-full border border-ink-200 bg-ink-50 px-2.5 py-0.5 font-mono text-2xs text-ink-600 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-300">
                                        #{{ $tag }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- Reactions summary --}}
                    <div class="flex items-center justify-between px-4 py-2 text-2xs text-ink-500 dark:text-ink-400 sm:px-5">
                        <div class="flex items-center gap-1.5">
                            <span class="inline-flex items-center gap-0.5">
                                <span aria-hidden="true">👍</span>
                                <span aria-hidden="true">❤️</span>
                                <span aria-hidden="true">💡</span>
                            </span>
                            <span class="font-mono">
                                {{ $post['reactions']['like'] + $post['reactions']['love'] + $post['reactions']['insight'] }}
                            </span>
                        </div>
                        <button
                            type="button"
                            class="focus-ring font-mono transition-colors hover:text-signal-700 dark:hover:text-signal-400"
                            @click="showComments = ! showComments"
                        >
                            {{ count($post['comments']) }} {{ __('comments') }}
                        </button>
                    </div>

                    {{-- Action bar --}}
                    <div class="flex items-center gap-1 border-t border-ink-100 px-2 py-1 dark:border-ink-800">
                        <button
                            type="button"
                            class="focus-ring flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-xs font-medium transition-colors"
                            :class="liked ? 'text-signal-600 dark:text-signal-400' : 'text-ink-500 hover:bg-ink-50 dark:text-ink-400 dark:hover:bg-ink-900'"
                            @click="liked = ! liked"
                        >
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M6.633 10.25c.806 0 1.533-.446 2.031-1.08a9.041 9.041 0 012.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 00.322-1.672V2.75a.75.75 0 01.75-.75 2.25 2.25 0 012.25 2.25c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 01-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 00-1.423-.23H5.904M14.25 9h2.25M5.904 18.5c.083.205.173.405.27.602.197.4.542.723 1.007.846l.55.146c.782.208 1.588-.237 1.798-1.019l.892-3.341c.21-.782-.237-1.588-1.019-1.798l-.55-.146a1.697 1.697 0 01-1.007-.846 3.36 3.36 0 01-.27-.602M5.904 18.5a3.36 3.36 0 01-.27-.602m.27.602H5.25A2.25 2.25 0 013 16.25v-4.5A2.25 2.25 0 015.25 9.5h.654m0 0a3.36 3.36 0 01.27.602" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span x-text="liked ? '{{ __('Liked') }}' : '{{ __('Like') }}'"></span>
                        </button>
                        <button
                            type="button"
                            class="focus-ring flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-xs font-medium text-ink-500 transition-colors hover:bg-ink-50 dark:text-ink-400 dark:hover:bg-ink-900"
                            @click="showComments = ! showComments"
                        >
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.121 2.121 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ __('Comment') }}
                        </button>
                        <button
                            type="button"
                            class="focus-ring flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-xs font-medium text-ink-500 transition-colors hover:bg-ink-50 dark:text-ink-400 dark:hover:bg-ink-900"
                            @click="saved = ! saved"
                        >
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span x-text="saved ? '{{ __('Saved') }}' : '{{ __('Save') }}'"></span>
                        </button>
                        <button type="button" class="focus-ring flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-xs font-medium text-ink-500 transition-colors hover:bg-ink-50 dark:text-ink-400 dark:hover:bg-ink-900">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ __('Share') }}
                        </button>
                    </div>

                    {{-- Comments --}}
                    <div x-show="showComments" x-collapse class="border-t border-ink-100 px-4 py-4 dark:border-ink-800 sm:px-5">
                        {{-- Comment composer --}}
                        <div class="flex items-start gap-2">
                            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ink-100 font-mono text-2xs font-semibold text-ink-700 dark:bg-ink-900 dark:text-ink-200">SH</span>
                            <div class="flex-1">
                                <input
                                    type="text"
                                    placeholder="{{ __('Write a comment…') }}"
                                    class="w-full rounded-full border border-ink-200 bg-ink-50/60 px-4 py-2 text-xs text-ink-900 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-50 dark:placeholder:text-ink-500"
                                />
                            </div>
                        </div>

                        {{-- Comment list --}}
                        <ul class="mt-4 space-y-4">
                            @foreach ($post['comments'] as $comment)
                                <li class="flex items-start gap-2">
                                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ink-100 font-mono text-2xs font-semibold text-ink-700 dark:bg-ink-900 dark:text-ink-200">
                                        {{ $comment['avatar'] }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="rounded-2xl bg-ink-50 px-3 py-2 dark:bg-ink-900">
                                            <p class="text-xs font-semibold text-ink-900 dark:text-ink-50">{{ $comment['author'] }} <span class="font-mono font-normal text-ink-400 dark:text-ink-500">{{ $comment['handle'] }}</span></p>
                                            <p class="mt-0.5 text-xs leading-relaxed text-ink-700 dark:text-ink-300">{{ $comment['body'] }}</p>
                                        </div>
                                        <div class="mt-1 flex items-center gap-3 pl-1 font-mono text-2xs text-ink-400 dark:text-ink-500">
                                            <span>{{ $comment['time'] }}</span>
                                            <button type="button" class="focus-ring transition-colors hover:text-signal-700 dark:hover:text-signal-400">{{ __('Like') }}</button>
                                            <button type="button" class="focus-ring transition-colors hover:text-signal-700 dark:hover:text-signal-400">{{ __('Reply') }}</button>
                                        </div>

                                        {{-- Replies --}}
                                        @if (! empty($comment['replies']))
                                            <ul class="mt-3 space-y-3 pl-2">
                                                @foreach ($comment['replies'] as $reply)
                                                    <li class="flex items-start gap-2">
                                                        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-ink-100 font-mono text-2xs font-semibold text-ink-700 dark:bg-ink-900 dark:text-ink-200">
                                                            {{ $reply['avatar'] }}
                                                        </span>
                                                        <div class="min-w-0 flex-1">
                                                            <div class="rounded-2xl bg-ink-50 px-3 py-2 dark:bg-ink-900">
                                                                <p class="text-xs font-semibold text-ink-900 dark:text-ink-50">{{ $reply['author'] }} <span class="font-mono font-normal text-ink-400 dark:text-ink-500">{{ $reply['handle'] }}</span></p>
                                                                <p class="mt-0.5 text-xs leading-relaxed text-ink-700 dark:text-ink-300">{{ $reply['body'] }}</p>
                                                            </div>
                                                            <div class="mt-1 flex items-center gap-3 pl-1 font-mono text-2xs text-ink-400 dark:text-ink-500">
                                                                <span>{{ $reply['time'] }}</span>
                                                                <button type="button" class="focus-ring transition-colors hover:text-signal-700 dark:hover:text-signal-400">{{ __('Like') }}</button>
                                                                <button type="button" class="focus-ring transition-colors hover:text-signal-700 dark:hover:text-signal-400">{{ __('Reply') }}</button>
                                                            </div>
                                                        </div>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </article>
            @endforeach
        </section>

        {{-- Load more --}}
        <div class="flex justify-center pt-2">
            <button type="button" class="focus-ring inline-flex min-h-10 items-center gap-2 rounded-md border border-ink-200 bg-white px-5 py-2 text-sm font-medium text-ink-600 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300 dark:hover:border-signal-600 dark:hover:text-signal-400">
                {{ __('Load more') }}
            </button>
        </div>
    </div>
</div>