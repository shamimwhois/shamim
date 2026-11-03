@props([
    /** Ordinal for the section marker. */
    'number',
    'eyebrow' => 'Why Hire Me?',
    'heading' => 'Your Ideas, My Engineering Focus.',
    /** The word the heading accents; dropped when the heading does not contain it. */
    'accentWord' => 'Engineering',
    'description' => 'I build reliable, scalable and maintainable web applications using Laravel and PHP. From APIs to full systems, I focus on clean architecture, real solutions and long-term value.',
    /**
     * App\Services\AboutContent::reasons() — the six claims. Empty falls
     * back to the built-in copy below, so the orbit is never blank.
     *
     * @var array<int, array{title: string, body: string}>
     */
    'claims' => [],
    /**
     * The three specialisation pills under the actions: a label, a caption
     * and a Flux glyph. Specified line by line, like the audience panels.
     *
     * @var array<int, array{title: string, caption: string, icon: string}>
     */
    'tags' => [],
    /**
     * The two audience panels at the foot of the band, as prose. No
     * presenter behind them, so they live here as prop defaults.
     */
    'companies' => 'An engineer who thinks beyond the ticket. I bring problem-solving, clean architecture and a focus on long-term maintainability.',
    'clients' => 'Your requirements, my engineering focus. From ideas to production-ready solutions, I build with clarity, quality and care.',
    'githubHref' => 'https://github.com/shamimwhois',
    'contactHref' => '#about-contact',
    'headingId' => 'why-hire-heading',
])

@php
    /**
     * Why hire me — section 02.
     *
     * The pitch as a composition: the argument on the left, the engineer at
     * the centre of it on the right, and the same value read twice for the
     * two audiences who actually decide along the bottom. The portrait wears
     * the page hero's two theme renders — the light JPG under the shared
     * knockout treatment and the dark baked-alpha WebP — so the section
     * never ships a second identity.
     *
     * ## Where the copy lives
     *
     * The six claims come from the Reasons section, like every other band on
     * this page: `defaultReasons()` carries this wording, and a stored row
     * still replaces it. The eyebrow, the heading, the pills and the two
     * audience panels have no presenter behind them — they were specified
     * line by line and are not edited per-row anywhere else — so they live
     * here as prop defaults.
     *
     * ## The orbit
     *
     * Six claims, six stations on one circle around the portrait: the
     * composition is positional, so the chrome (glyph + station) is mapped
     * by index in `$cardChrome` rather than stored per claim — an operator's
     * row keeps its own words and inherits the station it lands on. From
     * lg the stations are only the resting frame — the cards walk the circle
     * as one rigid system (the hub ring and the wires walk with them) while
     * each card counter-rotates so its claim stays upright; narrower, and
     * under prefers-reduced-motion, the same stations simply hold. A seventh
     * claim would have nowhere to orbit, so extras render as a plain grid
     * under the stage instead of overlapping the composition.
     *
     * ## What it deliberately does not say
     *
     * No job titles, no client names, no metrics, no testimonials. Every
     * sentence is a statement of approach, which is the only kind of claim a
     * portfolio can make without evidence behind it.
     */
    $defaultClaims = [
        ['title' => 'Backend Engineering', 'body' => 'Clean, secure & maintainable code'],
        ['title' => 'API Development', 'body' => 'Integrations & third-party services'],
        ['title' => 'Scalable Architecture', 'body' => 'Modular & future-ready applications'],
        ['title' => 'Automation & Tools', 'body' => 'Save time with efficient workflows'],
        ['title' => 'Quality & Reliability', 'body' => 'Testing, validation & clean structure'],
        ['title' => 'Client & Team Focus', 'body' => 'Clear communication and real solutions'],
    ];

    $defaultTags = [
        ['title' => 'Laravel & PHP', 'caption' => 'Core Specialization', 'icon' => 'code-bracket'],
        ['title' => 'APIs & Integrations', 'caption' => 'Connect & Automate', 'icon' => 'link'],
        ['title' => 'SaaS & Web Apps', 'caption' => 'Scalable & Maintainable', 'icon' => 'square-3-stack-3d'],
    ];

    /**
     * One station per orbit card, in claim order: the glyph that answers the
     * claim, and the angle it rides at — clockwise from 3 o'clock, which is
     * the zero CSS `rotate()` reads and the direction it turns. The reference
     * composition alternates up the circle (top, right-upper, right-lower,
     * bottom, left-lower, left-upper), so the six stations sit 60° apart and
     * the array order — which is the order the claims are *written* in, not
     * the order they appear — maps onto them by index. An operator's row keeps
     * its own words and inherits the station it lands on.
     *
     * @var array<int, array{icon: string, angle: int}>
     */
    $cardChrome = [
        ['icon' => 'code-bracket', 'angle' => 270],
        ['icon' => 'link', 'angle' => 210],
        ['icon' => 'square-3-stack-3d', 'angle' => 330],
        ['icon' => 'cog-6-tooth', 'angle' => 150],
        ['icon' => 'shield-check', 'angle' => 30],
        ['icon' => 'users', 'angle' => 90],
    ];

    $claims = array_values($claims) ?: $defaultClaims;
    $tags = array_values($tags) ?: $defaultTags;

    /** The six the orbit composes; anything beyond orbits nowhere and lists below. */
    $orbitClaims = array_slice($claims, 0, 6);
    $extraClaims = array_slice($claims, 6);

    /** The heading with its accent word wrapped — and a swash drawn under it. */
    $swash = '<svg class="whm-swash" viewBox="0 0 92 10" fill="none" preserveAspectRatio="none" aria-hidden="true"><path d="M3 7.2C21 2.6 40 8.6 57 5.4S80 2.4 89 5.6" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" pathLength="100"/></svg>';
    $accentedHeading = str_replace(
        e($accentWord),
        '<span class="whm-accent">'.e($accentWord).$swash.'</span>',
        e($heading),
    );
@endphp

<section class="w-full px-5 py-20 sm:px-8 lg:px-10" aria-labelledby="{{ $headingId }}">
    <div class="mx-auto max-w-[76rem]">
        <div class="grid gap-12 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:items-center lg:gap-10">
            {{-- The argument: ordinal + eyebrow, the claim, the paragraph that
                 qualifies it, the two actions, the three specialisations. --}}
            <div data-reveal>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-3 md:gap-x-5">
                    <span class="sec-ordinal relative inline-block shrink-0 select-none">
                        <span class="exp-ordinal-stroke block text-4xl font-extrabold leading-none md:text-5xl">{{ str_pad((string) $number, 2, '0', STR_PAD_LEFT) }}</span>
                        <span aria-hidden="true" class="absolute inset-0 block text-4xl font-extrabold leading-none text-signal-600 md:text-5xl dark:text-signal-400">{{ str_pad((string) $number, 2, '0', STR_PAD_LEFT) }}</span>
                    </span>

                    <span class="inline-flex items-center gap-2 rounded-full border border-signal-200 bg-signal-50 px-3.5 py-1.5 font-mono text-2xs font-semibold uppercase tracking-widest text-signal-700 dark:border-signal-800/70 dark:bg-signal-950/40 dark:text-signal-400">
                        <span aria-hidden="true" class="size-1.5 shrink-0 rounded-full bg-signal-500 animate-pulse"></span>
                        {{ $eyebrow }}
                    </span>
                </div>

                <h2 id="{{ $headingId }}" class="sec-heading mt-6 text-4xl font-bold leading-[1.1] tracking-[-0.025em] text-ink-900 md:text-5xl md:leading-[1.08] dark:text-ink-50">
                    {!! $accentedHeading !!}
                </h2>

                <p class="sec-lead mt-5 max-w-[46ch] text-base leading-relaxed text-ink-500 md:text-lg dark:text-ink-400">
                    {{ $description }}
                </p>

                {{-- One pair of actions: the proof and the invitation. --}}
                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <a
                        href="{{ $githubHref }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="group inline-flex items-center gap-2.5 rounded-full bg-signal-600 px-6 py-3 text-sm font-semibold text-white transition-colors duration-200 hover:bg-signal-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-signal-500 dark:bg-signal-400 dark:text-ink-950 dark:hover:bg-signal-300"
                    >
                        <svg aria-hidden="true" class="size-[18px]" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82a7.4 7.4 0 012-.27c.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0016 8c0-4.42-3.58-8-8-8z" />
                        </svg>
                        Explore My GitHub
                        <svg aria-hidden="true" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </a>

                    <a
                        href="{{ $contactHref }}"
                        class="group inline-flex items-center gap-2.5 rounded-full border border-ink-300 bg-white px-6 py-3 text-sm font-semibold text-ink-800 transition-colors duration-200 hover:border-ink-900 hover:text-ink-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-signal-500 dark:border-ink-700 dark:bg-transparent dark:text-ink-100 dark:hover:border-ink-400 dark:hover:text-ink-50"
                    >
                        <flux:icon name="paper-airplane" variant="solid" class="size-[18px]" />
                        Let's Work Together
                        <svg aria-hidden="true" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </a>
                </div>

                {{-- The three specialisations: the stack, the surface and the
                     shape of the work, one pill each. --}}
                <ul class="mt-9 flex flex-wrap gap-2.5">
                    @foreach ($tags as $tagIndex => $tag)
                        <li
                            class="inline-flex items-center gap-2.5 rounded-xl border border-ink-200 bg-white px-3.5 py-2.5 dark:border-ink-800 dark:bg-ink-950/60"
                            data-reveal
                            style="transition-delay: {{ min($tagIndex * 70, 140) }}ms"
                        >
                            <flux:icon :name="$tag['icon']" variant="solid" class="size-5 shrink-0 text-signal-600 dark:text-signal-400" />
                            <span class="flex flex-col leading-tight">
                                <span class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ $tag['title'] }}</span>
                                <span class="mt-0.5 text-xs text-ink-500 dark:text-ink-400">{{ $tag['caption'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- The engineer, at the centre of the argument: the portrait
                 between two dotted orbits, the six claims riding the outer
                 one, each wired back to the middle. Below lg the same cards
                 read as a plain grid under the portrait. --}}
            <div class="whm-stage">
                {{-- Both rings pivot on the portrait's centre. The hub is no
                     decoration: it is the ring the wires cross and the four
                     nodes ride, so it walks with the cards as one system. The
                     crown stays the outer marker on its own slow tumble. The
                     reveal sits on this wrapper and never on the movers —
                     they are positioned against it, and a transformed mover
                     would re-anchor its own rotation origin. --}}
                <div class="whm-rings" aria-hidden="true" data-reveal>
                    <svg class="whm-ring whm-ring--a" viewBox="0 0 100 100" fill="none" aria-hidden="true">
                        <circle cx="50" cy="50" r="49.5" />
                    </svg>

                    <div class="whm-hub">
                        <svg class="whm-ring whm-ring--b" viewBox="0 0 100 100" fill="none" aria-hidden="true">
                            <circle cx="50" cy="50" r="49.5" />
                        </svg>

                        <span class="whm-node whm-node--n"></span>
                        <span class="whm-node whm-node--e"></span>
                        <span class="whm-node whm-node--s"></span>
                        <span class="whm-node whm-node--w"></span>
                    </div>
                </div>

                <div class="whm-portrait" data-reveal aria-hidden="true">
                    <span class="whm-portrait__glow"></span>

                    {{-- Both renders of the portrait stay in one positional
                         stack, but only the light one paints. Its white studio
                         field is knocked out by the hero's filter, so the figure
                         stands *inside* the emerald disc rather than being
                         pasted onto it — and since `.whm-portrait` paints the
                         same emerald in both themes, that reads correctly in
                         either mode. The dark render is pinned by the feature
                         test and kept for reference, but its baked alpha
                         includes the design's dotted orbit arc crossing the
                         disc, so CSS holds it out of both themes. --}}
                    <img
                        class="whm-portrait__img whm-portrait__img--light"
                        src="{{ asset('images/hero-portrait-light.jpg') }}"
                        alt=""
                        width="1536"
                        height="1024"
                        loading="lazy"
                        decoding="async"
                    />
                    <img
                        class="whm-portrait__img whm-portrait__img--dark"
                        src="{{ asset('images/darkmode.webp') }}"
                        alt=""
                        width="1502"
                        height="1047"
                        loading="lazy"
                        decoding="async"
                    />
                </div>

                <ul class="whm-cards">
                    @foreach ($orbitClaims as $claimIndex => $claim)
                        @php $chrome = $cardChrome[$claimIndex]; @endphp
                        <li class="whm-card" style="--whm-a: {{ $chrome['angle'] }}deg">
                            {{-- The counter-rotation, on its own element: the
                                 card inherits the stage's revolution and undoes
                                 it on the same clock, so the claim stays
                                 upright while its station travels the circle.
                                 Keeping it off the inner card leaves the
                                 reveal's transform alone. --}}
                            <div class="whm-card__up">
                                {{-- Glyph disc and claim on one line, the
                                     qualifier under the title at the card's
                                     full width: a narrow card with the text
                                     squeezed beside the glyph wraps to nine
                                     lines and reads as a paragraph, not a
                                     label. --}}
                                <div
                                    class="whm-card__inner group"
                                    data-reveal
                                    style="transition-delay: {{ min(200 + $claimIndex * 60, 500) }}ms"
                                >
                                    <span class="whm-card__icon" aria-hidden="true">
                                        <flux:icon :name="$chrome['icon']" variant="solid" class="size-[42%]" />
                                    </span>

                                    <span class="whm-card__body">
                                        <h3 class="whm-card__title">{{ $claim['title'] }}</h3>
                                        <p class="whm-card__text">{{ $claim['body'] }}</p>
                                    </span>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                {{-- A claim with no station left to orbit: a plain grid under
                     the stage, at every breakpoint. It keeps the orbit card's
                     own idiom — glyph disc beside the claim — so an operator's
                     seventh row never reads as a different component. --}}
                @if ($extraClaims !== [])
                    <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($extraClaims as $extraIndex => $claim)
                            <li
                                class="whm-card__inner"
                                data-reveal
                                style="transition-delay: {{ min($extraIndex * 60, 240) }}ms"
                            >
                                <span class="whm-card__icon" aria-hidden="true">
                                    <flux:icon name="sparkles" variant="solid" class="size-[42%]" />
                                </span>

                                <span class="whm-card__body">
                                    <h3 class="whm-card__title">{{ $claim['title'] }}</h3>
                                    <p class="whm-card__text">{{ $claim['body'] }}</p>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- The same argument, twice: once for the team that has to maintain
             the work, once for the client who has to live with it. --}}
        <div class="mt-14 grid gap-4 lg:grid-cols-2">
            @foreach ([
                ['icon' => 'briefcase', 'title' => 'For Companies', 'body' => $companies],
                ['icon' => 'user', 'title' => 'For Clients', 'body' => $clients],
            ] as $panelIndex => $panel)
                <div
                    class="whm-panel relative overflow-hidden rounded-2xl border border-signal-100 bg-signal-50/70 p-6 md:p-7 dark:border-signal-800/50 dark:bg-signal-950/30"
                    data-reveal
                    style="transition-delay: {{ min($panelIndex * 80, 160) }}ms"
                >
                    <div class="relative z-[1] flex gap-4">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-signal-600 text-white dark:bg-signal-400 dark:text-ink-950">
                            <flux:icon :name="$panel['icon']" variant="solid" class="size-5" />
                        </span>

                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold tracking-[-0.015em] text-ink-900 dark:text-ink-50">
                                {{ $panel['title'] }}
                            </h3>
                            <p class="mt-2 max-w-[54ch] text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                                {{ $panel['body'] }}
                            </p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
