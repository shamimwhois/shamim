@php
    /**
     * The public site header.
     *
     * A single sticky bar. On desktop the nav is inline; below `md` it collapses
     * into a disclosure panel. The wordmark is monospace to establish the
     * terminal motif before the reader has scrolled anywhere.
     */
    $nav = [
        ['label' => 'Feed', 'route' => 'feed.index', 'pattern' => 'feed.*'],
        ['label' => 'Writing', 'route' => 'blog.index', 'pattern' => 'blog.*'],
        ['label' => 'AI Knowledge', 'route' => 'ai-hub.index', 'pattern' => 'ai-hub.*'],
        ['label' => 'Marketplace', 'route' => 'marketplace.index', 'pattern' => 'marketplace.*'],
        ['label' => 'About', 'route' => 'about', 'pattern' => 'about'],
    ];
@endphp

<header
    x-data="{ open: false }"
    @keydown.escape.window="open = false"
    class="sticky top-0 z-40 border-b border-ink-200/80 bg-white/85 backdrop-blur-md dark:border-ink-800/80 dark:bg-ink-950/85"
>
    <div class="shell flex h-16 items-center justify-between gap-6">
        {{-- Wordmark --}}
        <a
            href="{{ route('home') }}"
            wire:navigate
            class="focus-ring group flex items-center gap-2.5 rounded-sm font-mono text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50"
        >
            <x-app.icon.logo-mark
                size="md"
                class="border border-ink-900/15 bg-ink-900 font-mono text-signal-400 transition-colors group-hover:border-signal-500/40 dark:border-ink-50/15 dark:bg-ink-50 dark:text-ink-950"
            />
            <span>{{ config('portfolio.author.short_name', config('app.name')) }}</span>
        </a>

        {{-- Desktop nav --}}
        <nav class="hidden items-center gap-1 md:flex" aria-label="Primary">
            @foreach ($nav as $item)
                @php $current = request()->routeIs($item['pattern']); @endphp
                <a
                    href="{{ route($item['route']) }}"
                    wire:navigate
                    @if ($current) aria-current="page" @endif
                    @class([
                        'focus-ring relative rounded-sm px-3 py-2 text-sm font-medium transition-colors',
                        'text-ink-900 dark:text-ink-50' => $current,
                        'text-ink-500 hover:text-ink-900 dark:text-ink-400 dark:hover:text-ink-100' => ! $current,
                    ])
                >
                    {{ $item['label'] }}
                    @if ($current)
                        <span aria-hidden="true" class="absolute inset-x-3 -bottom-px h-px bg-signal-500 dark:bg-signal-400"></span>
                    @endif
                </a>
            @endforeach

            <span aria-hidden="true" class="mx-2 h-4 w-px bg-ink-200 dark:bg-ink-800"></span>

            <x-app.ui.theme-toggle />

            <x-app.ui.accent-picker panel-id="accent-picker-desktop" />

            <flux:button
                variant="ghost"
                size="sm"
                :href="route('blog.index')"
                icon="magnifying-glass"
                aria-label="Search writing"
                title="Search writing"
            />

            @auth
                {{-- Points at the dashboard the account's role owns, not a fixed path. --}}
                <flux:button variant="filled" size="sm" :href="route(auth()->user()->dashboardRoute())" icon="squares-2x2">
                    Dashboard
                </flux:button>
            @else
                <flux:button variant="primary" size="sm" :href="route('login')">
                    Sign in
                </flux:button>
            @endauth
        </nav>

        {{-- Mobile trigger --}}
        <button
            type="button"
            class="focus-ring -mr-2 grid size-10 place-items-center rounded-sm text-ink-600 transition-colors hover:bg-ink-100 md:hidden dark:text-ink-300 dark:hover:bg-ink-900"
            @click="open = ! open"
            :aria-expanded="open"
            aria-controls="mobile-nav"
            aria-label="Toggle navigation"
        >
            <svg x-show="! open" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
            <svg x-show="open" x-cloak class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    {{-- Mobile nav panel --}}
    <div
        id="mobile-nav"
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2"
        class="border-t border-ink-200 bg-white md:hidden dark:border-ink-800 dark:bg-ink-950"
    >
        <nav class="shell flex flex-col gap-1 py-4" aria-label="Primary mobile">
            @foreach ($nav as $item)
                @php $current = request()->routeIs($item['pattern']); @endphp
                <a
                    href="{{ route($item['route']) }}"
                    wire:navigate
                    @if ($current) aria-current="page" @endif
                    @class([
                        'focus-ring rounded-sm px-3 py-3 text-base font-medium transition-colors',
                        'bg-ink-50 text-ink-900 dark:bg-ink-900 dark:text-ink-50' => $current,
                        'text-ink-600 hover:bg-ink-50 dark:text-ink-300 dark:hover:bg-ink-900' => ! $current,
                    ])
                >
                    {{ $item['label'] }}
                </a>
            @endforeach

            <div class="hairline my-3"></div>

            {{-- Theme — a labelled row rather than a bare icon, because a
                 phone has no tooltip to explain a sun glyph. --}}
            <div class="flex items-center justify-between px-3 py-2">
                <span class="text-base font-medium text-ink-600 dark:text-ink-300">
                    {{ __('Theme') }}
                </span>
                <x-app.ui.theme-toggle />
            </div>

            <div class="flex items-center justify-between px-3 py-2">
                <span class="text-base font-medium text-ink-600 dark:text-ink-300">
                    {{ __('Accent color') }}
                </span>
                <x-app.ui.accent-picker panel-id="accent-picker-mobile" />
            </div>

            @auth
                <a
                    href="{{ route(auth()->user()->dashboardRoute()) }}"
                    wire:navigate
                    class="focus-ring rounded-sm px-3 py-3 text-base font-medium text-ink-600 transition-colors hover:bg-ink-50 dark:text-ink-300 dark:hover:bg-ink-900"
                >
                    {{ __('Dashboard') }}
                </a>
            @else
                <a
                    href="{{ route('login') }}"
                    class="focus-ring rounded-sm px-3 py-3 text-base font-medium text-ink-600 transition-colors hover:bg-ink-50 dark:text-ink-300 dark:hover:bg-ink-900"
                >
                    {{ __('Sign in') }}
                </a>
            @endauth
        </nav>
    </div>
</header>
