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

        {{-- The guest authentication dialogs. Renders nothing for a signed-in
             account, so the public shell always carries them: a guest bounced
             from a protected area returns here with `?signin=required` and the
             login dialog is already open; `?signup` / `?forgot` do the same
             for the redirected standalone auth URLs. --}}
        <x-auth.modals />

        @fluxScripts
    </body>
</html>

