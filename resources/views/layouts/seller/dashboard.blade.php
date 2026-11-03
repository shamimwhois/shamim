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
                <x-app.seller.sidebar />
            </x-slot:sidebar>

            <x-slot:sidebarIcons>
                <x-app.seller.sidebar variant="icons" />
            </x-slot:sidebarIcons>

            <x-slot:header>
                <x-app.seller.header />
            </x-slot:header>

            <x-slot:footer>
                <x-app.seller.footer />
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
    Seller layout — the selling workspace at `/seller/dashboard`.

    ## Who this is for

    Accounts holding the seller role, whose home is the Selling dashboard
    rather than the author workspace. `pages/dashboard/⚡index.blade.php`
    forwards a seller here on mount, so the URL always names the surface and
    the sidebar's active state and the browser's history both stay honest.

    ## Why this exists rather than pointing at `layouts::app.dashboard`

    Because the *layout* is not the product — the chrome is. Everything below
    the shell tags is identical to the app layout, and that is the point: the
    shared shell in `components/app/ui/dashboard-shell.blade.php` is one
    implementation, and this layout supplies the seller's own chrome
    components in its place. When the selling workspace needs a payout balance
    in the rail footer or a pending-orders count in the top bar, that change
    lands in `components/app/seller/footer.blade.php` and reaches exactly the
    sellers — not the authors, and not the admins.

    `layouts::app.dashboard` is the fallback for a seller on a non-seller
    surface (a marketplace page, say). That is intentional: a module page is
    not part of the selling workspace and does not need the selling chrome.

    ## Notes on the regions

    `brand` is deliberately not passed. The shell's default wordmark points at
    `auth()->user()->dashboardRoute()`, which for a seller resolves to
    `seller.dashboard`, so the rail's home link is correct without restating it.
--}}
