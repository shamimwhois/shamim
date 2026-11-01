<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    {{--
        `data-sui` is the Soft UI scope documented in resources/css/app.css and
        docs/SOFT-UI.md. It sits on <body> — not on an inner wrapper — because
        the drawer and the toast group are teleported straight onto the body,
        and anything outside the scope would silently fall back to the public
        site's palette.
    --}}
    <body class="min-h-screen antialiased" data-sui>
        <x-app.ui.dashboard-shell>
            <x-slot:sidebar>
                <x-app.user.sidebar />
            </x-slot:sidebar>

            <x-slot:sidebarIcons>
                <x-app.user.sidebar variant="icons" />
            </x-slot:sidebarIcons>

            <x-slot:header>
                <x-app.user.header />
            </x-slot:header>

            <x-slot:footer>
                <x-app.user.footer />
            </x-slot:footer>

            {{ $slot }}
        </x-app.ui.dashboard-shell>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>

{{--
    App layout — the general authenticated dashboard.

    ## Who this is for

    Every authenticated account that is not being served by the seller or the
    admin layout. In practice that is the reader and author workspace at
    `/dashboard` plus the four platform modules — Ideas, Developer Lab, AI Hub
    and the Marketplace — which are tools available to any permission holder
    rather than a role's own home. A page declares it with
    `#[Layout('layouts::app.dashboard')]`.

    ## What this file owns, and what it does not

    The document, the `data-sui` scope and the toast group: the parts that are
    about being a page. Everything between the shell tags is the workspace
    itself — rail, drawer, focus trap, `<main>` — and that lives in
    `components/app/ui/dashboard-shell.blade.php` rather than here, because
    `layouts::seller.dashboard` and `layouts::admin.dashboard` need exactly the
    same machinery. Three copies of a focus trap is three places to fix the
    same bug.

    So this layout is a composition: the shell supplies the regions, and the
    role's own chrome components fill them. Changing the shape of the workspace
    means editing the shell. Changing what a user sees inside it means editing
    `components/app/user/*`.

    ## Notes on the regions

    `sidebar` and `sidebarIcons` are the same navigation in two forms — the
    expanded list and the collapsed icon rail. The shell renders them side by
    side and Alpine shows one, so there is no conditional to get wrong.

    `brand` is deliberately not passed. The shell's default wordmark points at
    `auth()->user()->dashboardRoute()`, which is already role-aware, so every
    role gets the correct home link without restating it.

    ## The page body

    `{{ $slot }}` lands inside `<main id="dashboard-main">` — the skip link's
    target, the one `id` the accessibility contract of this layout promises.
--}}
