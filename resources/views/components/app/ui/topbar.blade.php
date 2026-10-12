@props([
    'label' => null,
])

@php
    /**
     * The bar that replaces the rail below `lg`.
     *
     * Three things, in the order a thumb reaches them: the button that opens
     * the drawer, the wordmark that goes home, and the role badge that says
     * which workspace you are standing in. Nothing else — at this width the
     * bar is 56px tall and every additional control is a control you miss.
     *
     * The brand link carries `aria-label` because its visible text is the
     * author's short name, which says who owns the site and not where the link
     * goes. `$label` supplies the destination: "Selling dashboard", "Admin
     * dashboard", and so on, drawn from the role's own route by the role's
     * header component.
     */
    $user = auth()->user();
    $home = route($user->dashboardRoute());
    $label ??= __('Dashboard home');
@endphp

<header class="sticky top-0 z-30 border-b border-sui-hairline bg-sui-surface/85 shadow-soft-1 backdrop-blur-md lg:hidden">
    <div class="flex h-14 items-center justify-between gap-3 px-4">
        <div class="flex min-w-0 items-center gap-2">
            <button
                type="button"
                @click="drawer = true"
                class="sui-focus grid size-9 shrink-0 place-items-center rounded-md text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-accent-ink"
                aria-label="{{ __('Open navigation') }}"
                aria-controls="dashboard-drawer"
                data-test="drawer-open"
            >
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>

            <a
                href="{{ $home }}"
                wire:navigate
                aria-label="{{ $label }}"
                class="flex min-w-0 items-center gap-2 text-sm font-semibold text-sui-ink"
            >
                <x-app.icon.logo-mark size="sm" class="bg-sui-rail text-sui-rail-ink" />
                <span class="truncate">{{ config('portfolio.author.short_name', 'Dashboard') }}</span>
            </a>
        </div>

        <x-app.ui.role-badge class="shrink-0" />
    </div>
</header>
