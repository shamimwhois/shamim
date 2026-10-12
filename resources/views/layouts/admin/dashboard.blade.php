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
                <x-app.admin.sidebar />
            </x-slot:sidebar>

            <x-slot:sidebarIcons>
                <x-app.admin.sidebar variant="icons" />
            </x-slot:sidebarIcons>

            <x-slot:header>
                <x-app.admin.header />
            </x-slot:header>

            <x-slot:footer>
                <x-app.admin.footer />
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
    Admin layout — platform administration at `/admin/dashboard`.

    ## Who this is for

    Accounts that may reach the admin panel. Their home is the platform health
    dashboard; the Users screen is the second destination in the same space.

    ## Why this exists rather than pointing at `layouts::app.dashboard`

    Because the *layout* is not the product — the chrome is. Everything below
    the shell tags is identical to the app layout, and that is the point: the
    shared shell in `components/app/ui/dashboard-shell.blade.php` is one
    implementation, and this layout supplies the admin's own chrome components
    in its place. When platform administration needs a system-health link in the
    rail footer or an account-search control in the top bar, that change lands
    in `components/app/admin/footer.blade.php` and reaches exactly the admins.

    ## A note on the navigation itself

    The Administration *group* in the sidebar is not added here. It is part of
    the one navigation declaration in `App\Support\DashboardNavigation`, which
    filters every item by permission, so the admin sees the group and no other
    role does — without this layout, or the chrome components it composes,
    knowing anything about who is looking at them. That is the property worth
    protecting: the menu and the route guards read the same declaration and
    therefore cannot disagree.

    ## Notes on the regions

    `brand` is deliberately not passed. The shell's default wordmark points at
    `auth()->user()->dashboardRoute()`, which for an admin resolves to
    `admin.dashboard`, so the rail's home link is correct without restating it.
--}}
