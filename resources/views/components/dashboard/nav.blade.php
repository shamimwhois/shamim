@php
    /**
     * Dashboard navigation.
     *
     * Rendered twice (desktop rail and mobile panel), so it is a component
     * rather than duplicated markup. Each item declares its own route-name
     * pattern so the active state is derived, not hand-maintained.
     *
     * The moderation badge is queried here rather than passed in by each page:
     * every dashboard screen renders this nav, and a badge that only appears on
     * the overview is a badge the author never sees while working. `once()`
     * keeps the double render down to a single count query.
     */
    $pendingComments = once(fn (): int => auth()->check()
        ? \App\Models\Comment::query()
            ->pending()
            ->whereHas('post', fn ($query) => $query->authoredBy(auth()->user()))
            ->count()
        : 0);

    $items = [
        [
            'label' => 'Overview',
            'route' => 'dashboard.index',
            'pattern' => 'dashboard.index',
            'icon' => 'squares-2x2',
        ],
        [
            'label' => 'Posts',
            'route' => 'dashboard.posts.index',
            'pattern' => 'dashboard.posts.*',
            'icon' => 'document-text',
        ],
        [
            'label' => 'Comments',
            'route' => 'dashboard.comments',
            'pattern' => 'dashboard.comments',
            'icon' => 'chat-bubble-left-right',
            'badge' => $pendingComments ?? 0,
        ],
        [
            'label' => 'Taxonomy',
            'route' => 'dashboard.taxonomy',
            'pattern' => 'dashboard.taxonomy',
            'icon' => 'tag',
        ],
    ];
@endphp

<div class="space-y-6">
    @foreach ($items as $item)
        @php $current = request()->routeIs($item['pattern']); @endphp

        <div>
            @if ($loop->first)
                <p class="px-3 pb-2 font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    Content
                </p>
            @endif

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

                @if (($item['badge'] ?? 0) > 0 && ! $current)
                    <span class="rounded-full bg-amber-100 px-1.5 py-0.5 font-mono text-2xs font-semibold tabular-nums text-amber-700 dark:bg-amber-950 dark:text-amber-400">
                        {{ $item['badge'] }}
                    </span>
                @elseif (($item['badge'] ?? 0) > 0 && $current)
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 font-mono text-2xs font-semibold tabular-nums dark:bg-ink-950/20">
                        {{ $item['badge'] }}
                    </span>
                @endif
            </a>
        </div>

        @if ($loop->first)
            <div class="hairline !my-4"></div>
        @endif
    @endforeach
</div>
