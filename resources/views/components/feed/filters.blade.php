@props([
    'sort',
    'type',
    'category',
    'tag',
    'topics',
    'tags' => [],
])

{{--
    Filter surface — the reference's tab row: sort tabs on the left, the
    topic / type / tag menus on the right.

    Everything here is URL-bound Livewire state on the parent (`?sort=`,
    `?type=`, `?category=`, `?tag=`), so every view is shareable and survives
    a reload. The tabs call `$set` on the parent; the three menus are plain
    Alpine disclosures. Search is not here — the app header owns it, next to
    the brand, so it is reachable at every scroll position.
--}}

@php
    $sortTabs = ['for-you' => __('For you'), 'following' => __('Following'), 'latest' => __('Latest'), 'trending' => __('Trending'), 'unanswered' => __('Unanswered')];
    $typeLabels = ['all' => __('All types'), 'question' => __('Questions'), 'discussion' => __('Discussions'), 'showcase' => __('Showcases'), 'snippet' => __('Snippets'), 'article' => __('Articles'), 'poll' => __('Polls')];
    $activeTopic = $category === null ? null : collect($topics['items'])->firstWhere('slug', $category);
@endphp

<div class="flex h-9 flex-wrap items-center justify-between gap-x-4 gap-y-2 lg:flex-nowrap lg:gap-2">
    {{-- Sort tabs --}}
    <div class="-mb-px flex h-9 items-center gap-5 overflow-x-auto" role="group" aria-label="{{ __('Sort posts') }}">
        @foreach ($sortTabs as $key => $label)
            <button
                type="button"
                wire:click="$set('sort', '{{ $key }}')"
                class="feed-tab h-9"
                aria-pressed="{{ $sort === $key ? 'true' : 'false' }}"
                wire:key="tab-sort-{{ $key }}"
            >
                {{ $label }}
                @if ($key === 'unanswered' && ($topics['unansweredCount'] ?? 0) > 0)
                    <span class="feed-tab-count">{{ $topics['unansweredCount'] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- The three filter menus --}}
    <div class="flex h-9 flex-wrap items-center gap-1.5 pb-1.5 lg:flex-nowrap lg:pb-0">
        {{-- Topics --}}
        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <button type="button" class="feed-pill h-7" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="listbox">
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 6h.008v.008H6V6z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>{{ $activeTopic['label'] ?? __('All topics') }}</span>
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M19.5 8.25l-7.5 7.5-7.5-7.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div x-show="open" x-cloak x-on:click.outside="open = false" class="feed-menu" role="listbox">
                <button type="button" wire:click="$set('category', null)" x-on:click="open = false" @class(['feed-menu-item', 'font-semibold' => $category === null]) aria-current="{{ $category === null ? 'true' : 'false' }}">
                    {{ __('All topics') }}
                </button>
                @foreach ($topics['items'] as $topic)
                    <button
                        type="button"
                        wire:click="$set('category', '{{ $topic['slug'] }}')"
                        x-on:click="open = false"
                        @class(['feed-menu-item', 'font-semibold' => $category === $topic['slug']])
                        aria-current="{{ $category === $topic['slug'] ? 'true' : 'false' }}"
                        wire:key="menu-topic-{{ $topic['slug'] }}"
                    >
                        {{ $topic['label'] }}
                        <span class="font-mono text-[10px] tabular-nums text-feed-ink-3">{{ $topic['count'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Content types --}}
        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <button type="button" class="feed-pill h-7" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="listbox">
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>{{ $typeLabels[$type] ?? __('All types') }}</span>
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M19.5 8.25l-7.5 7.5-7.5-7.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div x-show="open" x-cloak x-on:click.outside="open = false" class="feed-menu" role="listbox">
                @foreach ($typeLabels as $typeKey => $label)
                    <button
                        type="button"
                        wire:click="$set('type', '{{ $typeKey }}')"
                        x-on:click="open = false"
                        @class(['feed-menu-item', 'font-semibold' => $type === $typeKey])
                        aria-current="{{ $type === $typeKey ? 'true' : 'false' }}"
                        wire:key="menu-type-{{ $typeKey }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Tags --}}
        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <button type="button" class="feed-pill h-7" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="listbox">
                <span class="font-mono text-feed-azure">#</span>
                <span>{{ $tag ?? __('All tags') }}</span>
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M19.5 8.25l-7.5 7.5-7.5-7.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div x-show="open" x-cloak x-on:click.outside="open = false" class="feed-menu max-h-72 overflow-y-auto" role="listbox">
                <button type="button" wire:click="$set('tag', null)" x-on:click="open = false" @class(['feed-menu-item', 'font-semibold' => $tag === null]) aria-current="{{ $tag === null ? 'true' : 'false' }}">
                    {{ __('All tags') }}
                </button>
                @foreach (array_slice($tags, 0, 12) as $trend)
                    <button
                        type="button"
                        wire:click="$set('tag', '{{ $trend['tag'] }}')"
                        x-on:click="open = false"
                        @class(['feed-menu-item', 'font-semibold' => $tag === $trend['tag']])
                        aria-current="{{ $tag === $trend['tag'] ? 'true' : 'false' }}"
                        wire:key="menu-tag-{{ $trend['tag'] }}"
                    >
                        #{{ $trend['tag'] }}
                        <span class="font-mono text-[10px] tabular-nums text-feed-ink-3">{{ $trend['count'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>
</div>
