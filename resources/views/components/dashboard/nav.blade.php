@php
    /**
     * Dashboard navigation.
     *
     * Rendered twice (desktop rail and mobile panel), so it is a component
     * rather than duplicated markup.
     *
     * The items come from {@see \App\Support\DashboardNavigation}, which filters
     * them by the signed-in account's permissions. This view does no access
     * control of its own — it renders whatever it is given. That split is what
     * keeps the menu and the route guards from disagreeing: there is one
     * declaration, read by both the menu and the middleware.
     *
     * Each item declares a route-name *pattern* so the active state is derived
     * from the current route rather than hand-maintained.
     */
    $sections = \App\Support\DashboardNavigation::for(auth()->user());
@endphp

<nav class="space-y-6" aria-label="Dashboard">
    @foreach ($sections as $section)
        <div>
            <p class="px-3 pb-2 font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                {{ $section['label'] }}
            </p>

            <div class="space-y-0.5">
                @foreach ($section['items'] as $item)
                    @php
                        $current = request()->routeIs($item['pattern']);
                        /* Always an int — DashboardNavigation resolves the marker. */
                        $badge = $item['badge'];
                    @endphp

                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        @if ($current) aria-current="page" @endif
                        @class([
                            'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                            'bg-ink-900 text-white dark:bg-ink-50 dark:text-ink-950' => $current,
                            'text-ink-600 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-100' => ! $current,
                        ])
                    >
                        <flux:icon :name="$item['icon']" class="size-4 shrink-0" />
                        <span class="flex-1">{{ $item['label'] }}</span>

                        @if ($badge > 0 && ! $current)
                            <span class="rounded-full bg-amber-100 px-1.5 py-0.5 font-mono text-2xs font-semibold tabular-nums text-amber-700 dark:bg-amber-950 dark:text-amber-400">
                                {{ $badge }}
                            </span>
                        @elseif ($badge > 0 && $current)
                            <span class="rounded-full bg-white/20 px-1.5 py-0.5 font-mono text-2xs font-semibold tabular-nums dark:bg-ink-950/20">
                                {{ $badge }}
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</nav>
