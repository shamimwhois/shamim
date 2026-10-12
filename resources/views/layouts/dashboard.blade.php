<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-ink-50 text-ink-800 antialiased dark:bg-ink-950 dark:text-ink-200">
        <a
            href="#dashboard-main"
            class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] focus:rounded-md focus:bg-ink-900 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white dark:focus:bg-ink-50 dark:focus:text-ink-900"
        >
            Skip to content
        </a>

        <div class="flex min-h-screen">
            {{-- ========================================================= --
             | Sidebar (desktop)
             --}}
            <aside class="hidden w-64 shrink-0 border-r border-ink-200 bg-white lg:flex lg:flex-col dark:border-ink-800 dark:bg-ink-900">
                <div class="flex h-16 items-center border-b border-ink-200 px-5 dark:border-ink-800">
                    <a href="{{ route('dashboard.index') }}" wire:navigate class="group flex items-center gap-2.5 font-mono text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        <span aria-hidden="true" class="grid size-7 place-items-center rounded-xs border border-ink-900/15 bg-ink-900 font-mono text-xs font-bold text-signal-400 dark:border-ink-50/15 dark:bg-ink-50 dark:text-ink-950">
                            &gt;_
                        </span>
                        <span>{{ config('portfolio.author.short_name', config('app.name')) }}</span>
                    </a>
                </div>

                <nav class="flex-1 overflow-y-auto p-3" aria-label="Dashboard">
                    <x-dashboard.nav />
                </nav>

                <div class="border-t border-ink-200 p-3 dark:border-ink-800">
                    {{-- Account identity, with the role that governs the menu above. --}}
                    <div class="flex items-center gap-3 rounded-md px-3 py-2">
                        <div class="grid size-8 shrink-0 place-items-center rounded-md border border-ink-200 bg-ink-50 font-mono text-xs font-semibold text-ink-500 dark:border-ink-700 dark:bg-ink-800 dark:text-ink-300">
                            {{ Str::of(auth()->user()->name)->substr(0, 1)->upper() }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink-900 dark:text-ink-50">
                                {{ auth()->user()->name }}
                            </p>
                            <p class="truncate font-mono text-2xs text-ink-400 dark:text-ink-500">
                                {{ auth()->user()->email }}
                            </p>
                        </div>
                    </div>

                    {{-- Plan card: the account's commercial shape, one click from its dashboard home. --}}
                    <a
                        href="{{ route(auth()->user()->dashboardRoute()) }}"
                        wire:navigate
                        class="mt-1 block rounded-md border border-ink-200 p-3 transition-colors hover:border-ink-300 hover:bg-ink-50 dark:border-ink-800 dark:hover:border-ink-700 dark:hover:bg-ink-950/60"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <x-dashboard.role-badge size="md" />
                        </div>

                        <p class="mt-2 text-2xs leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ auth()->user()->plan()->tagline() }}
                        </p>
                    </a>

                    <div class="mt-1 space-y-0.5">
                        <a
                            href="{{ route('home') }}"
                            wire:navigate
                            class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-100"
                        >
                            <flux:icon name="arrow-top-right-on-square" class="size-4" />
                            View site
                        </a>

                        <a
                            href="{{ route('profile.edit') }}"
                            wire:navigate
                            class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-100"
                        >
                            <flux:icon name="cog-6-tooth" class="size-4" />
                            Settings
                        </a>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button
                                type="submit"
                                class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-left text-sm text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-100"
                            >
                                <flux:icon name="arrow-right-start-on-rectangle" class="size-4" />
                                Log out
                            </button>
                        </form>
                    </div>
                </div>
            </aside>

            {{-- ========================================================= --
             | Main column
             --}}
            <div class="flex min-w-0 flex-1 flex-col">
                {{-- Mobile header --}}
                <header
                    x-data="{ open: false }"
                    @keydown.escape.window="open = false"
                    class="sticky top-0 z-30 border-b border-ink-200 bg-white/90 backdrop-blur-md lg:hidden dark:border-ink-800 dark:bg-ink-900/90"
                >
                    <div class="flex h-14 items-center justify-between px-4">
                        <a href="{{ route('dashboard.index') }}" wire:navigate class="flex items-center gap-2 font-mono text-sm font-semibold text-ink-900 dark:text-ink-50">
                            <span aria-hidden="true" class="grid size-6 place-items-center rounded-xs bg-ink-900 text-2xs font-bold text-signal-400 dark:bg-ink-50 dark:text-ink-950">&gt;_</span>
                            Dashboard
                        </a>

                        <div class="flex items-center gap-2">
                            <x-dashboard.role-badge />

                            <button
                                type="button"
                                @click="open = ! open"
                                :aria-expanded="open"
                                class="grid size-9 place-items-center rounded-sm text-ink-600 transition-colors hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-800"
                                aria-label="Toggle dashboard navigation"
                            >
                                <svg x-show="! open" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                </svg>
                                <svg x-show="open" x-cloak class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <nav
                        x-show="open"
                        x-cloak
                        x-transition
                        class="border-t border-ink-200 bg-white p-3 dark:border-ink-800 dark:bg-ink-900"
                        aria-label="Dashboard mobile"
                    >
                        <x-dashboard.nav />
                    </nav>
                </header>

                <main id="dashboard-main" class="flex-1">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
