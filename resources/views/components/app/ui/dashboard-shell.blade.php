@props([])

@php
    /**
     * The dashboard shell.
     *
     * Everything every authenticated surface shares, and nothing any one
     * surface owns: the skip link, the floating sidenav, the floating navbar,
     * the mobile drawer with its hand-rolled focus trap, the `<main>` that
     * page content lands in, and the `data-sui` scope that makes all of it
     * Soft UI.
     *
     * ## Why this is a component and not a layout
     *
     * There are three role layouts — `layouts::app.dashboard`,
     * `layouts::seller.dashboard`, `layouts::admin.dashboard` — and they share
     * this shell rather than each carrying a copy of it. The sidenav and the
     * drawer are ~300 lines of interdependent machinery: the Alpine state that
     * drives them, the teleport that keeps the drawer out of a clipped
     * ancestor, the focus trap that makes it modal in fact as well as in
     * `aria-modal`, and the escape handler that closes it. Duplicating that
     * three times would mean three places to fix the same bug, and the
     * three would drift. The layouts therefore compose; only this file
     * implements.
     *
     * ## The regions
     *
     * The shell is organised into named regions, each a slot the role layout
     * fills from its own chrome component:
     *
     * | Slot           | Region                                            |
     * |----------------|---------------------------------------------------|
     * | `brand`        | the wordmark, in the sidenav and again in the drawer |
     * | `sidebar`      | the sidenav's scrolling navigation                |
     * | `sidebarIcons` | the sidenav's collapsed, icon-only navigation     |
     * | `footer`       | the sidenav's pinned account block                |
     * | `header`       | the navbar's breadcrumb (the role's workspace)    |
     * | default slot   | `<main id="dashboard-main">` — the page itself    |
     *
     * Two regions reuse the same slot content on purpose. The brand appears in
     * both the sidenav and the drawer, and the navigation appears in the
     * sidenav, the sidenav's collapsed form, and the drawer: rendering
     * `{{ $brand }}` twice costs nothing because a slot is already a rendered
     * string, and it guarantees the drawer cannot disagree with the sidenav
     * about where "home" is.
     *
     * ## The Creative Tim shape
     *
     * Both surfaces float. The sidenav is a white card inset from the viewport
     * edge with 16px corners and one soft shadow; the navbar is a white pill
     * floating inside the content column with the canvas showing around it on
     * all four sides. Nothing is flush, nothing is a full-width band, and the
     * only separation between surfaces is shadow — the neumorphic thesis,
     * applied at the layout level rather than only to the cards.
     *
     * ## State
     *
     * The shell owns two independent pieces of layout state:
     *
     *   collapsed — desktop only. Collapses the sidenav to an icon strip.
     *            Persisted, because it is a durable workspace preference.
     *   drawer — below `lg`. The sidenav becomes an off-canvas panel with a
     *            backdrop, scroll lock and Escape-to-close.
     *
     * Both keys are namespaced by user id so a shared machine does not leak
     * one account's layout into another's session.
     *
     * The control that flips `collapsed` is the icon button in the sidenav's
     * brand row — top-right of the wordmark, present in both widths so the
     * strip can always be widened back. It lives here rather than in the
     * pinned footer because it belongs to the *shell*: the footer is a role
     * component, and a rail that cannot be re-widened is a trap.
     */
    $user = auth()->user();
    $home = route($user->dashboardRoute());
    $authorName = config('portfolio.author.short_name', config('app.name'));
@endphp

{{--
    The skip link comes first in the tab order of every dashboard page. It is
    the only element that lives outside the `x-data` root, because it must be
    reachable even when the sidenav is collapsed and the drawer is closed.
--}}
<a
    href="#dashboard-main"
    class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] focus:rounded-md focus:bg-sui-accent focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white"
>
    {{ __('Skip to content') }}
</a>

<div
    class="flex min-h-screen"
    x-data="{
        collapsed: JSON.parse(localStorage.getItem(@js('df.sidenav-collapsed.'.auth()->id())) ?? 'false'),
        drawer: false,
        toggleCollapsed() {
            this.collapsed = ! this.collapsed;
            localStorage.setItem(@js('df.sidenav-collapsed.'.auth()->id()), JSON.stringify(this.collapsed));
        },
    }"
    @keydown.escape.window="drawer = false"
>
    {{-- ========================================================= --
     | Sidenav (desktop)
     |
     | The floating white card: inset 12px from the viewport edge,
     | 16px corners, one soft shadow, and a full-height sticky
     | column that never scrolls with the page. Light surfaces
     | throughout — the dark surface of the previous shell is gone, and
     | with it the last surface that needed its own ink scale.
     --}}
    <aside
        :class="collapsed ? 'lg:w-[76px]' : 'lg:w-[250px]'"
        class="sui-sidenav sticky top-3 hidden h-[calc(100vh-1.5rem)] shrink-0 flex-col ms-3 transition-[width] duration-200 ease-soft lg:flex"
        aria-label="{{ __('Workspace navigation') }}"
    >
        <div class="flex h-16 shrink-0 items-center gap-2.5 px-4">
            <a
                href="{{ $home }}"
                wire:navigate
                x-show="! collapsed"
                class="group flex min-w-0 items-center gap-2.5 text-sm font-semibold text-sui-ink"
                title="{{ $authorName }}"
            >
                <x-app.icon.logo-mark size="md" class="bg-sui-accent text-sui-ink-on-accent" />
                <span class="truncate">{{ $authorName }}</span>
            </a>

            {{--
                Collapse/expand. Wordmark beside it already spends the row's
                label budget, so the icon stays label-free and mirrored when
                the rail is narrow.

                In the icon rail the brand link yields the row to this handle
                (76 px cannot hold both the chip and the button), so the
                button only appears next to the wordmark — never twice.
            --}}
            <button
                type="button"
                @click="toggleCollapsed()"
                :aria-expanded="collapsed ? 'false' : 'true'"
                :class="collapsed ? 'mx-auto' : 'ms-auto'"
                aria-label="{{ __('Collapse or expand the sidebar') }}"
                class="sui-focus grid size-8 shrink-0 place-items-center rounded-md text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-ink"
                data-test="sidenav-toggle"
            >
                <flux:icon
                    name="chevron-double-left"
                    class="size-4 shrink-0 transition-transform duration-200"
                    ::class="collapsed ? 'rotate-180' : ''"
                />
            </button>
        </div>

        {{--
            The nav is the scrolling region, the footer is pinned. Only
            the nav gets the overflow, so the account block can never be
            scrolled out of reach.
        --}}
        <div
            class="flex-1 overflow-y-auto overscroll-contain px-3 py-4"
            x-show="! collapsed"
        >
            {{ $sidebar ?? null }}
        </div>

        {{--
            Icon-only sidenav. The full nav is hidden rather than shrunk:
            a tooltip per icon is a worse experience than expanding the
            sidenav, and this keeps one source of truth for the item list.
        --}}        <div
            class="flex-1 overflow-y-auto overscroll-contain px-2 py-4"
            x-show="collapsed" x-cloak
        >
            {{ $sidebarIcons ?? null }}
        </div>

        <div class="shrink-0 p-3">
            {{ $footer ?? null }}
        </div>
    </aside>

    {{-- ========================================================= --
     | Main column — the floating navbar and the page
     --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <x-app.ui.navbar>
            <x-slot:crumb>
                {{ $header ?? null }}
            </x-slot:crumb>
        </x-app.ui.navbar>

        <main id="dashboard-main" class="flex-1">
            {{ $slot }}
        </main>
    </div>

    {{-- ========================================================= --
     | Mobile drawer
     |
     | Teleported to <body> so no transformed/overflow ancestor can
     | clip it.
     |
     | The focus trap is hand-rolled rather than `x-trap`: Flux bundles
     | the `collapse` plugin only, so `x-trap` is not registered in this
     | app and using it would fail silently — the dialog would look
     | modal while Tab happily walked out into the page behind it. The
     | three parts that actually matter are implemented here explicitly:
     | lock body scroll, cycle Tab within the panel, restore focus on
     | close.
     --}}
    <template x-teleport="body">
        <div
            x-data="{
                previousFocus: null,
                focusables() {
                    return Array.from(this.$el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex=\'-1\'])'))
                        .filter((el) => el.offsetParent !== null);
                },
                onOpen() {
                    this.previousFocus = document.activeElement;
                    document.documentElement.classList.add('overflow-hidden');
                    this.$nextTick(() => (this.focusables()[0]?.focus() ?? this.$el.focus()));
                },
                onClose() {
                    document.documentElement.classList.remove('overflow-hidden');
                    this.previousFocus?.focus?.();
                },
                cycle(event) {
                    const items = this.focusables();

                    if (items.length === 0) {
                        return;
                    }

                    const first = items[0];
                    const last = items[items.length - 1];

                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (! event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                },
            }"
            x-effect="drawer ? onOpen() : onClose()"
            x-show="drawer"
            x-cloak
            @keydown.tab="cycle($event)"
            class="fixed inset-0 z-50 lg:hidden"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('Dashboard navigation') }}"
            id="dashboard-drawer"
        >
            <div
                x-show="drawer"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="drawer = false"
                class="absolute inset-0 bg-ink-950/40 backdrop-blur-sm"
                aria-hidden="true"
            ></div>

            <div
                x-show="drawer"
                x-transition:enter="transition ease-out duration-250"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                class="sui-sidenav absolute inset-y-0 left-0 flex w-[280px] max-w-[85vw] flex-col rounded-none shadow-soft-3"
            >
                <div class="flex h-16 shrink-0 items-center justify-between gap-2 px-4">
                    <a
                        href="{{ $home }}"
                        wire:navigate
                        class="flex min-w-0 items-center gap-2.5 text-sm font-semibold text-sui-ink"
                    >
                        <x-app.icon.logo-mark size="md" class="bg-sui-accent text-sui-ink-on-accent" />
                        <span class="truncate">{{ $authorName }}</span>
                    </a>

                    <button
                        type="button"
                        @click="drawer = false"
                        class="sui-focus grid size-9 shrink-0 place-items-center rounded-md text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-ink"
                        aria-label="{{ __('Close navigation') }}"
                        data-test="drawer-close"
                    >
                        <flux:icon name="x-mark" class="size-5" />
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto overscroll-contain px-3 py-4">
                    {{ $sidebar ?? null }}
                </div>

                <div class="shrink-0 p-3">
                    {{ $footer ?? null }}
                </div>
            </div>
        </div>
    </template>
</div>
