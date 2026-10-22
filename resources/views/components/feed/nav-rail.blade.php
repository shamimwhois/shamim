@props([
    'sort',
    'category',
    'type' => 'all',
    'topics',
    'typeCounts',
    'communities' => [],
    'communityCount' => 0,
])

{{--
    Navigation rail — the PRD's left pane, in the reference's four sections.

    NAVIGATION is the feed's own view state (plus the site home), COMMUNITIES
    is the real directory with live member counts — its first row opens the
    full directory page, so the rail is a vantage point, not a walled list —
    and FORUM and SHOWCASE are the corpus' own sections under their forum
    names. Every control is the parent component's URL-bound state
    (`?sort=`, `?category=`, `?type=`), so a rail choice survives a reload and
    can be shared. Counts are corpus- or directory-derived — never the
    reference's screenshot numbers.

    On desktop it is a sticky vertical rail; below `xl` each section's list
    becomes a horizontal scroller so the rail never towers over the stream.
--}}

@php
    $viewIcons = [
        'for-you' => 'M11.48 3.5a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
        'following' => 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
        'latest' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'trending' => 'M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.281m5.94 2.28l-2.28 5.941',
        'unanswered' => 'M9.879 7.519c1.171-1.025 3.07-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z',
    ];
@endphp

<aside class="space-y-3 xl:sticky xl:top-[6.25rem]" aria-label="{{ __('Feed navigation') }}">
    {{-- ===== NAVIGATION — the feed's views and the site home ============= --}}
    <div class="feed-card p-2">
        <p class="feed-eyebrow flex items-center gap-2 px-2 py-1.5 font-semibold">
            <span class="feed-rail-index">01</span>
            {{ __('Navigation') }}
        </p>
        <nav class="flex flex-col gap-0.5" aria-label="{{ __('Feed views') }}">
            <a href="{{ route('home') }}" wire:navigate class="feed-rail-link" wire:key="rail-home">
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" /></svg>
                <span class="flex-1 text-left">{{ __('Home') }}</span>
            </a>
            @foreach (['for-you' => __('For you'), 'following' => __('Following'), 'latest' => __('Latest'), 'trending' => __('Trending'), 'unanswered' => __('Unanswered')] as $key => $label)
                <button
                    type="button"
                    wire:click="$set('sort', '{{ $key }}')"
                    class="feed-rail-link"
                    aria-current="{{ $sort === $key ? 'page' : 'false' }}"
                    wire:key="rail-sort-{{ $key }}"
                >
                    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $viewIcons[$key] }}" /></svg>
                    <span class="flex-1 text-left">{{ $label }}</span>
                    @if ($key === 'unanswered' && ($topics['unansweredCount'] ?? 0) > 0)
                        <span class="feed-chip is-error">{{ $topics['unansweredCount'] }}</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ===== COMMUNITIES — the real directory, busiest first ============== --}}
    <div class="feed-card p-2">
        <p class="feed-eyebrow flex items-center justify-between px-2 py-1.5 font-semibold">
            <span class="flex items-center gap-2"><span class="feed-rail-index">02</span>{{ __('Communities') }}</span>
            <span class="tabular-nums">{{ $communityCount }}</span>
        </p>
        <nav class="flex gap-1 overflow-x-auto pb-0.5 xl:flex-col xl:gap-0.5 xl:overflow-visible" aria-label="{{ __('Communities') }}">
            {{-- The directory itself — the rail lists the busiest circles below,
                 this row opens the whole catalogue with its own search. --}}
            <a href="{{ route('communities.index') }}" wire:navigate class="feed-rail-link" wire:key="rail-communities-all">
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                <span class="flex-1 text-left">{{ __('All communities') }}</span>
                <span class="feed-count">{{ $communityCount }}</span>
            </a>
            @foreach ($communities as $community)
                <button
                    type="button"
                    wire:click="$set('category', '{{ $community['topic'] }}')"
                    class="feed-rail-link"
                    aria-current="{{ $category === $community['topic'] ? 'page' : 'false' }}"
                    wire:key="rail-community-{{ $community['slug'] }}"
                    title="{{ $community['invite_only'] ? __('Invite only') : __('Open community') }}"
                >
                    <span class="grid size-6 shrink-0 place-items-center rounded-md bg-feed-well font-mono text-[10px] font-semibold text-feed-ink-2">{{ $community['glyph'] }}</span>
                    <span class="flex-1 truncate text-left">{{ $community['label'] }}</span>
                    @if ($community['invite_only'])
                        <svg class="size-3 shrink-0 text-feed-ink-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                    @endif
                    @if ($community['joined'])
                        <span class="size-1.5 shrink-0 rounded-full bg-feed-mint" title="{{ __('Joined') }}"></span>
                    @endif
                    <span class="feed-count">{{ $community['members'] }}</span>
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ===== FORUM — the corpus' Q&A sections, live counts ================= --}}
    <div class="feed-card p-2">
        <p class="feed-eyebrow flex items-center gap-2 px-2 py-1.5 font-semibold">
            <span class="feed-rail-index">03</span>
            {{ __('Forum') }}
        </p>
        <nav class="flex gap-1 overflow-x-auto pb-0.5 xl:flex-col xl:gap-0.5 xl:overflow-visible" aria-label="{{ __('Forum') }}">
            <button
                type="button"
                wire:click="clearFilters"
                class="feed-rail-link"
                wire:key="rail-forum-all"
            >
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.056-4.019-.163-2.01-.24-3.86-1.14-3.86-3.955v-4.286c0-3.135 2.34-4.11 5.34-4.11h3.14c3 0 5.4 1.06 5.4 4.11zM12.02 3.638l-1.02-.163C9.47 3.474 7.5 4.436 7.5 6.22v3.71" /></svg>
                <span class="flex-1 text-left">{{ __('All discussions') }}</span>
                <span class="feed-count">{{ $topics['total'] ?? 0 }}</span>
            </button>
            {{-- Every row is a real corpus type with its own live count. The
                 reference also lists code reviews, architecture and debugging
                 rows; the corpus has no such type, so they are absent rather
                 than invented. --}}
            @foreach (['question' => __('Questions & answers'), 'discussion' => __('Discussions'), 'poll' => __('Polls'), 'article' => __('Tutorials')] as $typeKey => $label)
                <button
                    type="button"
                    wire:click="$set('type', '{{ $typeKey }}')"
                    class="feed-rail-link"
                    aria-current="{{ $type === $typeKey ? 'page' : 'false' }}"
                    wire:key="rail-forum-{{ $typeKey }}"
                >
                    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                    <span class="flex-1 text-left">{{ $label }}</span>
                    <span class="feed-count">{{ $typeCounts[$typeKey] ?? 0 }}</span>
                </button>
            @endforeach
            <button
                type="button"
                wire:click="$set('sort', 'unanswered')"
                class="feed-rail-link"
                aria-current="{{ $sort === 'unanswered' ? 'page' : 'false' }}"
                wire:key="rail-forum-unanswered"
            >
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.07-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" /></svg>
                <span class="flex-1 text-left">{{ __('Unanswered') }}</span>
                <span class="feed-chip is-error">{{ $topics['unansweredCount'] ?? 0 }}</span>
            </button>
            <button
                type="button"
                wire:click="$set('sort', 'solved')"
                class="feed-rail-link"
                aria-current="{{ $sort === 'solved' ? 'page' : 'false' }}"
                wire:key="rail-forum-solved"
            >
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <span class="flex-1 text-left">{{ __('Solved') }}</span>
                <span class="font-mono text-[10px] tabular-nums text-feed-mint">{{ $typeCounts['solved'] ?? 0 }}</span>
            </button>
        </nav>
    </div>

    {{-- ===== SHOWCASE — the marketplace and the corpus' own showcases ====== --}}
    <div class="feed-card p-2">
        <p class="feed-eyebrow flex items-center gap-2 px-2 py-1.5 font-semibold">
            <span class="feed-rail-index">04</span>
            {{ __('Showcase') }}
        </p>
        <nav class="flex gap-1 overflow-x-auto pb-0.5 xl:flex-col xl:gap-0.5 xl:overflow-visible" aria-label="{{ __('Showcase') }}">
            <a href="{{ route('marketplace.index') }}" wire:navigate class="feed-rail-link" wire:key="rail-showcase-projects">
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0118 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 004.5 9v.878m13.5-3V9c.828 0 1.5.672 1.5 1.5v3m0 0V15a1.5 1.5 0 01-1.5 1.5H21M4.5 9.878V15a1.5 1.5 0 001.5 1.5h.75m11.25-6.378a2.25 2.25 0 012.25 2.25v3.75m-2.25-2.25a2.25 2.25 0 00-2.25 2.25v.75m-8.25-3.75h9m-9 3H12" /></svg>
                <span class="flex-1 text-left">{{ __('Projects') }}</span>
            </a>
            @foreach (['showcase' => __('Showcases'), 'snippet' => __('Code samples')] as $typeKey => $label)
                <button
                    type="button"
                    wire:click="$set('type', '{{ $typeKey }}')"
                    class="feed-rail-link"
                    aria-current="{{ $type === $typeKey ? 'page' : 'false' }}"
                    wire:key="rail-showcase-{{ $typeKey }}"
                >
                    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75L2.25 12l4.179 2.25m0-4.5l5.571 3 5.571-3m-11.142 0L2.25 12m12.32-7.5L2.25 12l12.32 7.5m-5.57-4.5l5.571 3" /></svg>
                    <span class="flex-1 text-left">{{ $label }}</span>
                    <span class="feed-count">{{ $typeCounts[$typeKey] ?? 0 }}</span>
                </button>
            @endforeach
        </nav>
    </div>
</aside>
