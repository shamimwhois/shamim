@props([
    'label' => null,
])

@php
    /**
     * The navbar's breadcrumb: which workspace, then which section of it.
     *
     * Two levels, no more. The root names the role's own workspace and links
     * home — on the smallest screens it is also the only place the product
     * identifies itself, because the sidenav is behind the drawer there. The
     * leaf is the section of the navigation holding the current route, taken
     * from the same permission-filtered map the sidebar renders, so the
     * breadcrumb cannot name a section the account is not allowed to see.
     *
     * When nothing matches — the dashboard root, a settings page — the leaf
     * falls back to "Overview", which is always true of the role's home.
     */
    $label ??= __('Dashboard');

    $home = route(auth()->user()->dashboardRoute());

    $activeSection = null;

    foreach (\App\Support\DashboardNavigation::for(auth()->user()) as $section) {
        foreach ($section['groups'] as $group) {
            if ($group['active']) {
                $activeSection = $section['label'];

                break 2;
            }
        }
    }
@endphp

<nav aria-label="{{ __('Breadcrumb') }}" class="min-w-0">
    <ol class="flex items-center gap-1.5 text-sm">
        <li class="min-w-0">
            <a
                href="{{ $home }}"
                wire:navigate
                class="sui-focus flex min-w-0 items-center gap-2 rounded-md px-1 py-0.5 transition duration-150 ease-soft hover:text-sui-accent-ink"
            >
                <x-app.icon.logo-mark size="sm" class="bg-sui-accent text-sui-ink-on-accent" />
                <span class="truncate font-semibold text-sui-ink">{{ $label }}</span>
            </a>
        </li>

        <li aria-hidden="true" class="text-ink-300">/</li>

        <li class="truncate text-ink-500" aria-current="page">
            {{ $activeSection ?? __('Overview') }}
        </li>
    </ol>
</nav>
