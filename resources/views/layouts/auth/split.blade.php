@props([
    'title' => null,
])

@php
    /**
     * The authentication layout.
     *
     * Every Fortify screen — login, registration, password reset, e-mail
     * verification, the two-factor challenge — renders inside this one layout.
     * That is deliberate: they are the same page with different questions on
     * it, and a visitor who moves through four of them in ninety seconds
     * should never see the frame shift.
     *
     * It is reached through `layouts::auth`, a one-line entry point that
     * resolves here. The indirection means switching the authentication design
     * is a change to that file alone, and no page has to know the split exists.
     *
     * Two things were wrong with the layout this replaces, and both are worth
     * naming because they were not cosmetic:
     *
     *   1. `<html class="dark">` was hardcoded. The application supports a
     *      light and a dark theme and lets the visitor choose; the sign-in
     *      page silently overrode that choice, so someone who had selected the
     *      light theme was flashed a dark page and then dropped into a light
     *      dashboard. The attribute is now absent, which hands the decision to
     *      the theme controller exactly as every other page does.
     *
     *   2. The left panel rendered a random `Inspiring` quote. A quotation
     *      generator is a placeholder standing where product voice belongs.
     *      It is replaced with copy that is actually true of this application.
     *
     * The panel is `lg:` only. On a phone there is no room for a second
     * column and no reason to make someone scroll past a manifesto to reach a
     * password field, so the split collapses to a centred card.
     *
     * This is a *public* surface, so it uses the public `ink-*`/`signal-*`
     * palette and carries no `data-sui` scope, exactly like
     * `layouts::guest.home`.
     */
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-ink-800 antialiased dark:bg-ink-950 dark:text-ink-200">
        {{-- The theme toggle floats clear of both columns: top-right of the
             viewport is over the form column on every breakpoint, and the
             form column is the one the visitor is looking at. --}}
        <div class="fixed right-5 top-5 z-20">
            <x-app.ui.theme-toggle />
        </div>

        <a
            href="#auth-form"
            class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] focus:rounded-md focus:bg-ink-900 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white dark:focus:bg-ink-50 dark:focus:text-ink-900"
        >
            {{ __('Skip to form') }}
        </a>

        <div class="grid min-h-svh lg:grid-cols-[1.1fr_1fr]">
            {{-- ------------------------------------------------------------------ --
             | Editorial panel — large screens only
             --}}
            <aside class="relative hidden overflow-hidden border-e border-ink-200 bg-ink-50 lg:flex lg:flex-col dark:border-ink-800 dark:bg-ink-900">
                {{-- Grid texture: the same 8px lattice used behind the portfolio hero. --}}
                <x-app.ui.placeholder-pattern
                    class="pointer-events-none absolute inset-0 size-full stroke-ink-300/40 dark:stroke-ink-700/40"
                    aria-hidden="true"
                />

                {{-- A single accent wash, anchored bottom-left, so the panel is not flat. --}}
                <div
                    aria-hidden="true"
                    class="pointer-events-none absolute -bottom-32 -left-24 size-[28rem] rounded-full bg-signal-400/10 blur-3xl dark:bg-signal-500/10"
                ></div>

                <div class="relative z-10 flex h-full flex-col justify-between p-10 xl:p-14">
                    <a href="{{ route('home') }}" class="group inline-flex items-center gap-3" wire:navigate>
                        <x-app.ui.app-logo-icon class="size-7 fill-current text-ink-900 transition-colors group-hover:text-signal-600 dark:text-ink-50 dark:group-hover:text-signal-400" />
                        <span class="font-medium tracking-tight">{{ config('app.name') }}</span>
                    </a>

                    <div class="max-w-md space-y-6">
                        <x-app.ui.section-marker number="01" :label="__('Account')" accent />

                        <flux:heading size="3xl" class="text-balance tracking-tighter">
                            {{ __('Sign in to your workspace.') }}
                        </flux:heading>

                        <flux:text class="text-pretty text-lg leading-relaxed">
                            {{ __('Writing, product listings and moderation — one account, scoped to your role.') }}
                        </flux:text>

                        <ul class="space-y-3 pt-2">
                            @foreach ([
                                __('Role-scoped dashboard — reader, seller or administrator'),
                                __('Two-factor authentication and passkey sign-in'),
                                __('Granular permissions — moderation, taxonomy and platform settings'),
                            ] as $capability)
                                <li class="flex items-start gap-3">
                                    <flux:icon.check-circle class="mt-0.5 size-4 shrink-0 text-signal-600 dark:text-signal-400" />
                                    <span class="text-sm text-ink-600 dark:text-ink-300">{{ $capability }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        {{ __('Secure area') }} &middot; {{ now()->year }}
                    </p>
                </div>
            </aside>

            {{-- ------------------------------------------------------------------ --
             | Form column
             --}}
            <main id="auth-form" class="flex flex-col items-center justify-center px-6 py-12 sm:px-10">
                <div class="flex w-full max-w-md flex-col gap-8">
                    {{-- Logo is the only brand affordance on small screens, where the panel is hidden. --}}
                    <a
                        href="{{ route('home') }}"
                        class="flex items-center justify-center gap-3 lg:hidden"
                        wire:navigate
                    >
                        <x-app.ui.app-logo-icon class="size-7 fill-current text-ink-900 dark:text-ink-50" />
                        <span class="font-medium tracking-tight">{{ config('app.name') }}</span>
                    </a>

                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>

                    <p class="text-center font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-600">
                        <a href="{{ route('home') }}" class="transition-colors hover:text-signal-600 dark:hover:text-signal-400" wire:navigate>
                            {{ __('← Back to site') }}
                        </a>
                    </p>
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
