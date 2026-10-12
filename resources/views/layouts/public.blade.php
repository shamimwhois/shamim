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
            Skip to content
        </a>

        <x-site.header />

        <main id="main">
            {{ $slot }}
        </main>

        <x-site.footer />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
