@php
    /**
     * The public site footer.
     *
     * Closes the page with the same terminal motif it opened with: a monospace
     * status line, then navigation, then a build stamp. No newsletter form, no
     * social wall — the footer is a signpost, not a funnel.
     */
    $columns = [
        'Writing' => [
            ['label' => 'All posts', 'route' => 'blog.index'],
            ['label' => 'RSS feed', 'route' => 'feed'],
            ['label' => 'Sitemap', 'route' => 'sitemap'],
        ],
        'Elsewhere' => collect(['github', 'linkedin', 'x'])
            ->map(fn (string $key) => config("portfolio.author.links.$key"))
            ->filter(fn (?array $link) => filled($link['url'] ?? null))
            ->map(fn (array $link) => [
                'label' => $link['label'],
                'url' => $link['url'],
                'external' => true,
            ])
            ->all(),
        'Contact' => array_values(array_filter([
            filled(config('portfolio.author.links.email.url'))
                ? [
                    'label' => 'Get in touch',
                    'url' => config('portfolio.author.links.email.url'),
                    'external' => false,
                ]
                : null,
            ['label' => 'About', 'route' => 'about'],
        ])),
    ];
@endphp

<footer class="mt-24 border-t border-ink-200 dark:border-ink-800">
    <div class="shell py-14">
        <div class="grid gap-10 md:grid-cols-[1.4fr_repeat(3,1fr)]">
            {{-- Identity + status line --}}
            <div>
                <p class="font-mono text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                    {{ config('portfolio.author.name') }}
                </p>
                <p class="mt-2 max-w-xs text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ config('portfolio.author.role') }}
                    @if ($location = config('portfolio.author.location'))
                        · {{ $location }}
                    @endif
                </p>

                @if ($availability = config('portfolio.author.availability'))
                    <p class="mt-5 inline-flex items-center gap-2 rounded-xs border border-ink-200 px-2.5 py-1 font-mono text-2xs font-medium text-ink-600 dark:border-ink-800 dark:text-ink-400">
                        <span class="relative flex size-1.5">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-signal-500 opacity-70"></span>
                            <span class="relative inline-flex size-1.5 rounded-full bg-signal-500"></span>
                        </span>
                        {{ $availability }}
                    </p>
                @endif
            </div>

            {{-- Link columns --}}
            @foreach ($columns as $heading => $links)
                <nav aria-label="{{ $heading }}">
                    <h2 class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        {{ $heading }}
                    </h2>
                    <ul class="mt-4 space-y-2.5">
                        @foreach ($links as $link)
                            <li>
                                @if (! empty($link['external']))
                                    <a
                                        href="{{ $link['url'] }}"
                                        target="_blank"
                                        rel="noopener noreferrer me"
                                        class="text-sm text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                                    >
                                        {{ $link['label'] }}<span class="sr-only"> (opens in a new tab)</span>
                                    </a>
                                @else
                                    <a
                                        href="{{ isset($link['route']) ? route($link['route']) : $link['url'] }}"
                                        @if (isset($link['route'])) wire:navigate @endif
                                        class="text-sm text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                                    >
                                        {{ $link['label'] }}
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach
        </div>

        <div class="hairline my-10"></div>

        {{-- Build stamp --}}
        <div class="flex flex-col gap-3 text-2xs text-ink-400 sm:flex-row sm:items-center sm:justify-between dark:text-ink-500">
            <p class="font-mono">
                &copy; {{ now()->year }} {{ config('portfolio.author.name') }} · All rights reserved.
            </p>
            <p class="font-mono">
                Built with <span class="text-ink-500 dark:text-ink-400">Laravel {{ Illuminate\Foundation\Application::VERSION }}</span>
                · <span class="text-ink-500 dark:text-ink-400">Livewire</span>
                · <span class="text-ink-500 dark:text-ink-400">Tailwind</span>
            </p>
        </div>
    </div>
</footer>
