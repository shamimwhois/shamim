@php
    /**
     * Icon-only sidenav.
     *
     * Rendered when the desktop sidenav is collapsed. It carries the same
     * destinations as `components/app/ui/nav.blade.php`, flattened to one
     * icon square per leaf.
     *
     * Deliberately built from the *same* DashboardNavigation data rather than a
     * parallel list: a second hand-maintained menu is exactly the drift this
     * navigation layer exists to prevent. Section headings are dropped because
     * neither they nor their labels survive being reduced to an icon — the
     * expanded nav draws its groups flat for the same reason.
     *
     * The white squares survive the collapse — they are what makes the narrow
     * column readable, because a bare glyph column has nothing to anchor the
     * eye. Each keeps its pastel ink, assigned by the same rotation the expanded
     * list uses, so an item does not change colour when the sidenav narrows.
     *
     * Every icon needs a name a sighted user can read, so each link carries
     * `title` and an `aria-label`; the icon itself stays decorative.
     */
    /**
     * The nav's accessible name, inherited from the role's sidebar so the
     * collapsed sidenav announces the same landmark the expanded one does.
     */
    $label ??= __('Dashboard (compact)');
    $scope ??= null;

    $sections = \App\Support\DashboardNavigation::for(auth()->user(), $scope);

    /* The same six-tone rotation the expanded nav uses. */
    $tones = ['primary', 'info', 'success', 'warning', 'danger', 'secondary'];

    $toneIndex = 0;
@endphp

<nav class="space-y-1.5" aria-label="{{ $label }}">
    @foreach ($sections as $section)
        @foreach ($section['groups'] as $group)
            @foreach ($group['items'] as $item)
                @php
                    $current = request()->routeIs($item['pattern']);
                    $tone = $tones[$toneIndex % count($tones)];
                    $toneIndex++;

                    /* Pre-built for the same reason as the expanded nav: a
                       component-tag `@class` argument has its double quotes
                       rewritten to single quotes, so `"{$tone}"` interpolation
                       would survive as a literal class name. */
                    $toneInkClass = 'text-sui-pastel-'.$tone.'-ink';
                @endphp

                <a
                    href="{{ route($item['route']) }}"
                    wire:navigate
                    @if ($current) aria-current="page" @endif
                    title="{{ $item['label'] }}"
                    aria-label="{{ $item['label'] }}"
                    @class([
                        'sui-focus group relative grid size-11 place-items-center rounded-md transition duration-150 ease-soft',
                        'sui-sidenav-item-active' => $current,
                        'hover:bg-sui-hover' => ! $current,
                    ])
                    data-nav-item="{{ $item['route'] }}"
                >
                    <span
                        @class([
                            'grid size-8 place-items-center rounded-md shadow-soft-1 transition-colors duration-150',
                            'bg-sui-accent-soft text-sui-accent-ink' => $current,
                            'bg-sui-surface' => ! $current,
                        ])
                    >
                        <flux:icon
                            :name="$item['icon']"
                            @class([
                                'size-4',
                                'text-sui-accent-ink' => $current,
                                $toneInkClass => ! $current,
                            ])
                        />
                    </span>

                    @if ($item['badge'] > 0)
                        <span class="absolute right-0.5 top-0.5 grid min-w-4 place-items-center rounded-full bg-sui-attention-badge px-1 text-2xs font-semibold tabular-nums text-sui-attention-badge-ink">
                            {{ $item['badge'] }}
                        </span>
                    @endif
                </a>
            @endforeach
        @endforeach
    @endforeach
</nav>
