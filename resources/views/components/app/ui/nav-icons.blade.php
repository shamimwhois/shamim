@php
    /**
     * Icon-only rail.
     *
     * Rendered when the desktop sidebar is collapsed. It carries the same
     * destinations as `components/app/ui/nav.blade.php`, flattened to
     * one icon per leaf.
     *
     * Deliberately built from the *same* DashboardNavigation data rather than a
     * parallel list: a second hand-maintained menu is exactly the drift this
     * navigation layer exists to prevent. Sections and group headers are dropped
     * because neither survives being reduced to an icon, and a group header with
     * no room for its label is not a useful affordance.
     *
     * Every icon needs a name a sighted user can read, so each link carries
     * `title` and an `aria-label`; the icon itself stays decorative.
     */
    /**
     * The nav's accessible name, inherited from the role's sidebar so the
     * collapsed rail announces the same landmark the expanded one does.
     */
    $label ??= __('Dashboard (compact)');

    $sections = \App\Support\DashboardNavigation::for(auth()->user());
@endphp

<nav class="space-y-1" aria-label="{{ $label }}">
    @foreach ($sections as $section)
        @foreach ($section['groups'] as $group)
            @foreach ($group['items'] as $item)
                @php $current = request()->routeIs($item['pattern']); @endphp

                <a
                    href="{{ route($item['route']) }}"
                    wire:navigate
                    @if ($current) aria-current="page" @endif
                    title="{{ $item['label'] }}"
                    aria-label="{{ $item['label'] }}"
                    @class([
                        'sui-focus group relative grid size-11 place-items-center rounded-md transition duration-150 ease-soft',
                        'bg-white/10 text-sui-rail-ink' => $current,
                        'text-sui-rail-ink-secondary hover:bg-white/10 hover:text-sui-rail-ink' => ! $current,
                    ])
                    data-nav-item="{{ $item['route'] }}"
                >
                    @if ($current)
                        <span aria-hidden="true" class="absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-sui-rail-accent"></span>
                    @endif

                    <flux:icon :name="$item['icon']" class="size-5 shrink-0" />

                    @if ($item['badge'] > 0)
                        <span class="absolute right-1 top-1 grid min-w-4 place-items-center rounded-full bg-sui-attention-badge px-1 text-2xs font-semibold tabular-nums text-sui-attention-badge-ink">
                            {{ $item['badge'] }}
                        </span>
                    @endif
                </a>
            @endforeach
        @endforeach
    @endforeach
</nav>
