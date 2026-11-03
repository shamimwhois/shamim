@php
    /**
     * Dashboard navigation, light sidenav form.
     *
     * Rendered by the desktop sidenav and the mobile drawer, so it is a
     * component rather than duplicated markup.
     *
     * The items come from {@see \App\Support\DashboardNavigation}, which filters
     * them by the signed-in account's permissions. This view does no access
     * control of its own — it renders whatever it is given. That split is what
     * keeps the menu and the route guards from disagreeing: there is one
     * declaration, read by both the menu and the middleware.
     *
     * `$scope` narrows the rail to a route prefix (the admin rail passes
     * `admin` so it lists only platform-administration routes). It is forwarded
     * straight to {@see \App\Support\DashboardNavigation::for()}; this view
     * stays presentation-only and never decides what is allowed.
     *
     * ## The pastel icon squares
     *
     * Each destination carries a white rounded square holding a glyph in one of
     * six pastel inks, assigned by position so the list reads as a set without
     * any item claiming a colour that belongs to its neighbour. The squares are
     * the one place a white chip sits on a white surface with nothing but a
     * shadow to separate them — the Soft UI idea in its smallest form.
     *
     * The active row swaps the square to the accent wash and the label to the
     * accent ink. The colour still says "you are here", but it now says it at
     * 7.13:1 rather than at the 3:1 a coloured fill would have managed.
     *
     * ## Groups render flat
     *
     * The support class still organises destinations into sections, groups and
     * items, but this view draws neither a group heading nor a disclosure
     * control. Two reasons, in the order they matter: a section's heading
     * ("MANAGE", "WRITING") already names the band, so a second label under it
     * only restates the obvious; and a chevron that hides a link behind a click
     * costs a navigation step to reach exactly the thing the sidebar exists to
     * surface. Every item renders as a peer directly under its section, in
     * definition order — the same flat list `nav-icons` draws, so the expanded
     * and collapsed rails cannot disagree about what is where.
     *
     * With no disclosure there is no open/closed state left to hold: the
     * per-account `localStorage` map, the `toggle()`/`isOpen()` helpers and the
     * `aria-expanded`/`aria-controls` pair are gone with it. The group's `key`,
     * `static` and `active` fields still arrive on the data — they describe the
     * tree and the navigation tests pin them down — this view just no longer
     * branches on them.
     *
     * ## `$label`
     *
     * The nav's accessible name. Each role's sidebar passes its own, so an
     * account moving between the author workspace, the storefront and platform
     * administration hears a landmark that says where it is rather than the
     * same generic "Dashboard" three times over.
     */
    $label ??= __('Dashboard');
    $scope ??= null;

    $sections = \App\Support\DashboardNavigation::for(auth()->user(), $scope);

    /*
     * The six pastel inks, in a fixed order. Position-assigned rather than
     * item-declared: the navigation definition stays free of presentation, and
     * six tones in rotation is enough to separate neighbours without any single
     * item owning a colour forever.
     */
    $tones = ['primary', 'info', 'success', 'warning', 'danger', 'secondary'];

    $toneIndex = 0;
@endphp

<nav
    class="space-y-5"
    aria-label="{{ $label }}"
>
    @foreach ($sections as $section)
        <div>
            <p class="sui-sidenav-group">
                {{ $section['label'] }}
            </p>

            <div class="space-y-1">
                @foreach ($section['groups'] as $group)
                    @foreach ($group['items'] as $item)
                        @php
                            $current = request()->routeIs($item['pattern']);
                            $tone = $tones[$toneIndex % count($tones)];
                            $toneIndex++;

                            /* Built here rather than interpolated inside the
                               `@class` key below: Laravel rewrites the double
                               quotes of a component-tag `@class` argument to
                               single quotes, and a single-quoted PHP string
                               does not interpolate. `"text-sui-pastel-{$tone}-ink"`
                               would arrive in the browser verbatim, as a class
                               that matches nothing. A pre-built string key has
                               no quotes to rewrite. */
                            $toneInkClass = 'text-sui-pastel-'.$tone.'-ink';
                        @endphp

                        <a
                            href="{{ route($item['route']) }}"
                            wire:navigate
                            @if ($current) aria-current="page" @endif
                            @class([
                                'sui-focus group flex items-center gap-3 rounded-md px-2.5 py-2 text-sm font-medium transition duration-150 ease-soft',
                                'sui-sidenav-item-active' => $current,
                                'sui-sidenav-item' => ! $current,
                            ])
                            data-nav-item="{{ $item['route'] }}"
                        >
                            <span
                                @class([
                                    'grid size-8 shrink-0 place-items-center rounded-md shadow-soft-1 transition-colors duration-150',
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

                            <span class="flex-1 truncate">{{ $item['label'] }}</span>

                            @if ($item['badge'] > 0)
                                <span class="rounded-full bg-sui-attention-badge px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-sui-attention-badge-ink">
                                    {{ $item['badge'] }}
                                </span>
                            @endif
                        </a>
                    @endforeach
                @endforeach
            </div>
        </div>
    @endforeach
</nav>
