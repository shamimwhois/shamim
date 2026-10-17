<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Marketplace package catalogue preview.
 *
 * The catalogue uses one verified public package as a clearly attributed
 * example. It does not imply that DevFolio currently publishes or sells packages.
 *
 * Written in the public site's own palette (`ink-*`, `signal-*`) rather than the
 * workspace's `sui-*` tokens, because this is a storefront a guest reads, not a
 * dashboard a signed-in operator works in. Same frame as the about page: public
 * header, numbered section markers, dark and light both designed.
 */
new #[Title('Developer Marketplace')] class extends Component
{
    //
}; ?>

<div>
    {{-- ================================================================== --
     | 01 — Hero
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-16 lg:py-20">
            <x-app.ui.section-marker :number="1" label="Marketplace" accent />

            <div class="mt-8 grid items-start gap-12 lg:grid-cols-[minmax(0,7fr)_minmax(0,4fr)] lg:gap-16">
                <div>
                    <h1 class="max-w-3xl text-[clamp(2.25rem,5vw,3.75rem)] font-semibold leading-[1.02] tracking-tightest text-ink-900 dark:text-ink-50">
                        Good software starts with good building&nbsp;blocks.
                    </h1>

                    <p class="mt-6 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                        {{ __('A considered starting point for exploring developer packages, checking compatibility and finding clear setup guidance.') }}
                    </p>

                    <div class="mt-8 flex flex-wrap items-center gap-4">
                        <a
                            href="{{ route('marketplace.show', ['package' => 'media-library-for-laravel']) }}"
                            wire:navigate
                            class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-full bg-signal-600 px-7 py-3 text-sm font-semibold text-white transition-colors hover:bg-signal-700 dark:bg-signal-500 dark:hover:bg-signal-400"
                        >
                            {{ __('Explore the example package') }}
                            <span aria-hidden="true">&rarr;</span>
                        </a>
                        <span class="font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                            {{ __('One reference listing · no checkout') }}
                        </span>
                    </div>
                </div>

                {{-- Featured example: the dark panel, the one place the page
                     inverts against the canvas so the package has a poster. --}}
                <aside class="relative overflow-hidden rounded-2xl bg-ink-900 text-white dark:border dark:border-ink-800" aria-label="{{ __('Package preview') }}">
                    <div aria-hidden="true" class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(52,211,153,0.28),transparent_62%)]"></div>

                    <div class="relative p-6 sm:p-8">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-mono text-2xs uppercase tracking-widest text-ink-300">{{ __('Featured example') }}</p>
                            <span class="rounded-full border border-signal-400/30 bg-signal-400/10 px-2.5 py-0.5 font-mono text-2xs uppercase tracking-widest text-signal-300">
                                {{ __('External') }}
                            </span>
                        </div>

                        <h2 class="mt-6 text-xl font-semibold tracking-tight text-white sm:text-2xl">
                            {{ __('Media Library for Laravel') }}
                        </h2>
                        <p class="mt-3 text-sm leading-relaxed text-ink-200">
                            {{ __('An example of a focused package overview: attach media to models, generate image variants and serve files from Laravel disks.') }}
                        </p>

                        <div class="mt-7 border-t border-white/10 pt-5">
                            <p class="font-mono text-2xs uppercase tracking-widest text-ink-300">{{ __('Install command') }}</p>
                            <code class="mt-3 block break-all rounded-lg bg-ink-950/70 px-3 py-3 font-mono text-xs leading-relaxed text-signal-300">composer require roundly-consulting/media-library-for-laravel</code>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Package catalogue
     --}}
    <section data-reveal class="shell py-16 lg:py-20" aria-labelledby="catalog-heading">
        <x-app.ui.section-marker :number="2" label="Catalogue" />

        <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
            <h2 id="catalog-heading" class="max-w-2xl text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('A package, properly introduced') }}
            </h2>
            <p class="max-w-md text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                {{ __('A real public package is used as an attributed design example—not as a DevFolio product listing.') }}
            </p>
        </div>

        <article class="card-surface mt-10 overflow-hidden shadow-soft-1">
            <div class="grid lg:grid-cols-[minmax(0,1fr)_19rem]">
                <div class="p-6 sm:p-8">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="tag-pill border-signal-300 bg-signal-50 text-signal-700 dark:border-signal-800 dark:bg-signal-950 dark:text-signal-300">
                            {{ __('Content & media') }}
                        </span>
                        <span class="tag-pill">{{ __('Reference package') }}</span>
                    </div>

                    <h3 class="mt-5 text-2xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50 sm:text-3xl">
                        {{ __('Media Library for Laravel') }}
                    </h3>
                    <p class="mt-3 max-w-2xl text-sm leading-relaxed text-ink-600 dark:text-ink-300 sm:text-base">
                        {{ __('Attach files to named buckets on Eloquent models—or keep them global. The package handles storage, image variants, responsive sources, private streaming and deduplication through Laravel-native foundations.') }}
                    </p>

                    <dl class="mt-6 grid gap-x-8 gap-y-4 border-y border-ink-200 py-5 dark:border-ink-800 sm:grid-cols-3">
                        <div>
                            <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Maintainer') }}</dt>
                            <dd class="mt-1 text-sm font-medium text-ink-800 dark:text-ink-200">Roundly Consulting</dd>
                        </div>
                        <div>
                            <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Framework') }}</dt>
                            <dd class="mt-1 text-sm font-medium text-ink-800 dark:text-ink-200">Laravel 12 / 13</dd>
                        </div>
                        <div>
                            <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Runtime') }}</dt>
                            <dd class="mt-1 text-sm font-medium text-ink-800 dark:text-ink-200">PHP 8.4+</dd>
                        </div>
                    </dl>

                    <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                        <a
                            href="{{ route('marketplace.show', ['package' => 'media-library-for-laravel']) }}"
                            wire:navigate
                            class="focus-ring inline-flex min-h-10 items-center gap-2 rounded-full bg-signal-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-signal-700 dark:bg-signal-500 dark:hover:bg-signal-400"
                        >
                            {{ __('Open example package') }}
                            <span aria-hidden="true">&rarr;</span>
                        </a>
                        <a
                            href="https://roundly-consulting.com/open-source/docs/media-library-for-laravel"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="focus-ring inline-flex min-h-10 items-center gap-1.5 rounded-full px-3 text-sm font-medium text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
                        >
                            {{ __('View maintainer docs') }}
                            <span aria-hidden="true">&#8599;</span>
                        </a>
                    </div>
                </div>

                <aside class="border-t border-ink-200 bg-ink-50/70 p-6 dark:border-ink-800 dark:bg-ink-900/40 lg:border-l lg:border-t-0">
                    <p class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">{{ __('Install with Composer') }}</p>
                    <div x-data="{ copied: false }" class="mt-3 rounded-lg bg-ink-900 px-4 py-4 text-ink-50 dark:border dark:border-ink-800">
                        <code x-ref="command" class="block break-all font-mono text-xs leading-relaxed text-signal-300">composer require roundly-consulting/media-library-for-laravel</code>
                        <button
                            type="button"
                            x-on:click="navigator.clipboard?.writeText($refs.command.textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 1600); })"
                            class="focus-ring mt-3 inline-flex min-h-8 items-center rounded px-2 text-xs font-medium text-ink-200 transition hover:bg-white/10 hover:text-white"
                            aria-label="{{ __('Copy Composer install command') }}"
                        >
                            <span x-show="! copied">{{ __('Copy command') }}</span>
                            <span x-show="copied" x-cloak aria-live="polite">{{ __('Copied') }}</span>
                        </button>
                    </div>
                    <p class="mt-4 text-xs leading-relaxed text-ink-500 dark:text-ink-400">
                        {{ __('Compatibility and installation details are summarized from the maintainer’s public documentation.') }}
                    </p>
                </aside>
            </div>
        </article>
    </section>

    {{-- ================================================================== --
     | 03 — What to expect
     --}}
    <section data-reveal class="border-t border-ink-200 py-16 dark:border-ink-800 lg:py-20" aria-labelledby="path-heading">
        <div class="shell grid gap-10 lg:grid-cols-[minmax(0,0.7fr)_minmax(0,1.3fr)] lg:gap-14">
            <div>
                <x-app.ui.section-marker :number="3" label="Getting started" />
                <h2 id="path-heading" class="mt-6 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    {{ __('From discovery to first install') }}
                </h2>
                <p class="mt-4 max-w-md text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ __('The example detail view brings compatibility, setup commands and focused documentation into one readable path.') }}
                </p>
            </div>

            <ol class="grid gap-px overflow-hidden rounded-lg border border-ink-200 bg-ink-200 dark:border-ink-800 dark:bg-ink-800 sm:grid-cols-3">
                @foreach ([__('Understand the package'), __('Check requirements'), __('Follow the install guide')] as $step => $label)
                    <li class="bg-white p-6 transition-colors hover:bg-ink-50/80 dark:bg-ink-950 dark:hover:bg-ink-900/60">
                        <span class="font-mono text-2xs font-semibold tabular-nums text-signal-600 dark:text-signal-400">0{{ $step + 1 }}</span>
                        <p class="mt-3 text-sm leading-relaxed text-ink-700 dark:text-ink-200">{{ $label }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <div class="border-t border-ink-200 py-8 dark:border-ink-800">
        <div class="shell">
            <aside class="text-xs leading-relaxed text-ink-500 dark:text-ink-400" role="note">
                <span class="font-semibold text-ink-700 dark:text-ink-200">{{ __('Preview status:') }}</span>
                {{ __('This page demonstrates the package discovery and documentation experience. It does not provide DevFolio package submissions, purchases, protected downloads or seller fulfillment. The featured package is maintained by Roundly Consulting.') }}
            </aside>
        </div>
    </div>
</div>