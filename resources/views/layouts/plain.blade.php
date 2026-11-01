<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />

        {{--
            The document shell.

            A receipt is printed, so it gets the stylesheet, the fonts and the
            theme boot script, and nothing else: no marketing header, no footer,
            no toast group, no auth dialogs. Metadata comes from the scoped Seo
            instance like every other page, and a document that should never be
            indexed says so.
        --}}
        @php $seo = app(App\Support\Seo::class); @endphp

        <title>{{ $seo->resolvedTitle() }} — {{ config('app.name') }}</title>

        <meta name="robots" content="noindex, nofollow" />
        <meta name="color-scheme" content="light dark" />

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
        @include('partials.theme-boot')
    </head>

    <body class="min-h-screen bg-white text-ink-800 antialiased dark:bg-ink-950 dark:text-ink-200">
        <main id="main">
            {{ $slot }}
        </main>

        @fluxScripts
    </body>
</html>
