<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    {{--
        The log's own shell: no site header, no footer, no editorial chrome.

        dev-null's layout is an application frame — the feed column is the page,
        and a marketing header above it would put a second navigation on top of
        the one the design already draws. The tokens live on `body` (class
        `dn`), so everything inside, dialogs included, resolves against the
        log's palette rather than the site's.
    --}}
    <body class="dn min-h-svh">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] focus:rounded-md focus:bg-ink-900 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white"
        >
            {{ __('Skip to content') }}
        </a>

        {{ $slot }}

        {{-- The site's authentication dialogs. A guest sent here with
             `?signin=required` lands with the login dialog already open, which
             is how every other surface asks for an account. --}}
        <x-auth.modals />

        @fluxScripts
    </body>
</html>
