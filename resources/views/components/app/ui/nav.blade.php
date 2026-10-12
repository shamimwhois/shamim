@php
    /**
     * Dashboard navigation.
     *
     * Rendered by the desktop rail and the mobile drawer, so it is a component
     * rather than duplicated markup.
     *
     * The items come from {@see \App\Support\DashboardNavigation}, which filters
     * them by the signed-in account's permissions. This view does no access
     * control of its own — it renders whatever it is given. That split is what
     * keeps the menu and the route guards from disagreeing: there is one
     * declaration, read by both the menu and the middleware.
     *
     * ## Collapse behaviour
     *
     * Which group is open is local UI state, held in Alpine and persisted per
     * account in localStorage. Two rules override the stored state:
     *
     *   - a group containing the current route is always open (`active`), so the
     *     link you are looking for is never hidden behind a collapsed header;
     *   - when the rail is collapsed to icon-only, groups are irrelevant and the
     *     list flattens to a single row of icons.
     *
     * The storage key is namespaced by user id so two accounts on one machine do
     * not inherit each other's layout.
     *
     * ## `$label`
     *
     * The nav's accessible name. Each role's sidebar passes its own, so an
     * account moving between the author workspace, the storefront and platform
     * administration hears a landmark that says where it is rather than the
     * same generic "Dashboard" three times over.
     */
    $label ??= __('Dashboard');

    $sections = \App\Support\DashboardNavigation::for(auth()->user());

    $storageKey = 'df.nav.groups.'.auth()->id();

    /*
     * Group keys that should start open: every group that is not static. Stored
     * as a map so Alpine can flip a single key without re-serialising an array.
     */
    $defaultOpen = [];

    foreach ($sections as $section) {
        foreach ($section['groups'] as $group) {
            $defaultOpen[$group['key']] = ! $group['static'];
        }
    }
@endphp

<nav
    class="space-y-5"
    aria-label="{{ $label }}"
    x-data="{
        groups: JSON.parse(localStorage.getItem(@js($storageKey)) ?? 'null') ?? @js($defaultOpen),
        toggle(key) {
            this.groups[key] = ! this.groups[key];
            localStorage.setItem(@js($storageKey), JSON.stringify(this.groups));
        },
        isOpen(key) {
            return this.groups[key] !== false;
        },
    }"
>
    @foreach ($sections as $section)
        <div>
            <p class="sui-rail-group">
                {{ $section['label'] }}
            </p>

            <div class="space-y-1">
                @foreach ($section['groups'] as $group)
                    @if ($group['static'])
                        {{-- A single-destination group has nothing to disclose. --}}
                        @php $item = $group['items'][0]; @endphp

                        <a
                            href="{{ route($item['route']) }}"
                            wire:navigate
                            @if (request()->routeIs($item['pattern'])) aria-current="page" @endif
                            @class([
                                'sui-focus group relative flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition duration-150 ease-soft',
                                'sui-rail-item-active' => request()->routeIs($item['pattern']),
                                'sui-rail-item' => ! request()->routeIs($item['pattern']),
                            ])
                            data-nav-item="{{ $item['route'] }}"
                        >
                            @if (request()->routeIs($item['pattern']))
                                <span aria-hidden="true" class="absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-sui-rail-accent"></span>
                            @endif

                            <flux:icon :name="$item['icon']" class="size-4.5 shrink-0" />
                            <span class="flex-1 truncate">{{ $item['label'] }}</span>

                            @if ($item['badge'] > 0)
                                <span class="rounded-full bg-sui-attention-badge px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-sui-attention-badge-ink">
                                    {{ $item['badge'] }}
                                </span>
                            @endif
                        </a>
                    @else
                        <div
                            class="rounded-md"
                            x-data="{ key: @js($group['key']), active: @js($group['active']) }"
                        >
                            {{-- Group header: a disclosure button, not a link. --}}
                            <button
                                type="button"
                                @click="toggle(key)"
                                :aria-expanded="isOpen(key) ? 'true' : 'false'"
                                aria-controls="nav-group-{{ $group['key'] }}"
                                @class([
                                    'sui-focus group flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm font-medium transition duration-150 ease-soft',
                                    'sui-rail-item-active' => $group['active'],
                                    'sui-rail-item' => ! $group['active'],
                                ])
                                data-nav-group="{{ $group['key'] }}"
                            >
                                <flux:icon :name="$group['icon']" class="size-4.5 shrink-0" />
                                <span class="flex-1 truncate">{{ $group['label'] }}</span>

                                @if ($group['active'])
                                    <span aria-hidden="true" class="size-1.5 shrink-0 rounded-full bg-sui-rail-accent"></span>
                                @endif

                                <flux:icon
                                    name="chevron-right"
                                    class="size-3.5 shrink-0 transition-transform duration-200"
                                    ::class="isOpen(key) ? 'rotate-90' : ''"
                                />
                            </button>

                            {{--
                                Nested items. `x-show` keeps the markup in the DOM
                                so in-page find and screen-reader list traversal
                                still work; `x-collapse` gives the height animation
                                without a fixed height.
                            --}}
                            <div
                                id="nav-group-{{ $group['key'] }}"
                                x-show="isOpen(key) || active"
                                x-collapse.duration.200ms
                                class="mt-0.5 space-y-0.5"
                            >
                                @foreach ($group['items'] as $item)
                                    @php $current = request()->routeIs($item['pattern']); @endphp

                                    <a
                                        href="{{ route($item['route']) }}"
                                        wire:navigate
                                        @if ($current) aria-current="page" @endif
                                        @class([
                                            /* The rail marking the active leaf — a visible spine, not just a fill. */
                                            'sui-focus group relative flex items-center gap-3 rounded-md py-2 pl-7 pr-3 text-sm transition duration-150 ease-soft',
                                            'sui-rail-item-active' => $current,
                                            'sui-rail-item' => ! $current,
                                        ])
                                        data-nav-item="{{ $item['route'] }}"
                                    >
                                        @if ($current)
                                            <span aria-hidden="true" class="absolute inset-y-1.5 left-2 w-0.5 rounded-full bg-sui-rail-accent"></span>
                                        @endif

                                        <flux:icon :name="$item['icon']" class="size-4.5 shrink-0" />
                                        <span class="flex-1 truncate">{{ $item['label'] }}</span>

                                        @if ($item['badge'] > 0)
                                            <span class="rounded-full bg-sui-attention-badge px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-sui-attention-badge-ink">
                                                {{ $item['badge'] }}
                                            </span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach
</nav>
