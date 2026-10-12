<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-ink-800 antialiased dark:bg-ink-950 dark:text-ink-200">
        {{-- Skip link — first tab stop on every page. --}}
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] focus:rounded-md focus:bg-ink-900 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white dark:focus:bg-ink-50 dark:focus:text-ink-900"
        >
            {{ __('Skip to content') }}
        </a>

        <x-app.home.header />

        <main id="main">
            {{ $slot }}
        </main>

        <x-app.home.footer />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>

{{--
    Guest layout — the home page and every other public page.

    The shell an unauthenticated visitor sees: the public header, the page
    itself, the public footer. It carries no `data-sui` scope, because the
    Soft UI tokens belong to the workspace; the public site has its own
    editorial palette declared in the same `@theme` block. Anything rendered
    inside this layout therefore uses `ink-*` and `signal-*`, never `sui-*`.

    ## It is also the default layout

    `config/livewire.php` names this view as `component_layout`, so every
    single-file Livewire page that declares no `#[Layout]` renders here. That
    is why there is no fourth "default" layout to keep in step with this one.

    One consequence is worth stating plainly: the authenticated settings pages
    (`pages/settings/*`) carry no `#[Layout]` attribute and therefore render
    in this guest shell. That is deliberate — settings is the one authenticated
    surface that does not want the workspace chrome around it, so it keeps the
    quiet public frame. It does mean those pages use the public palette rather
    than the dashboard's, which is why they are written with `signal-*` and
    `ink-*` utilities throughout.

    ## Naming

    `layouts::guest.home` reads as "the guest's home". The workspace layouts
    are `layouts::app.dashboard`, `layouts::seller.dashboard` and
    `layouts::admin.dashboard`, so the audience is always the first path
    segment and the surface the second.
--}}
