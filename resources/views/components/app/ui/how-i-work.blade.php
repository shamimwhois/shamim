@props([
    /** Ordinal for the section marker. */
    'number',
    'label' => 'How I work',
    'heading' => 'Two Speeds, One Gate',
    'lead' => 'Quick judgement when the problem is familiar, deliberate analysis when it is not — and the same six defaults breaking the tie either way.',
    /**
     * App\Services\AboutContent::thinkingModes()['modes'] — the two systems,
     * each carrying the ability cards that belong to it.
     *
     * @var array<int, array{key: string, name: string, description: string, accent: string, side: string, cards: array<int, array{key: string, name: string, description: string, icon: string|null, tags: array<int, string>, nodes: array<int, array{label: string, text: string}>}>}>
     */
    'modes' => [],
    /**
     * array<int, array{title: string, body: string}> — the operating
     * principles, as the six-row index under the panel.
     *
     * @var array<int, array{title: string, body: string}>
     */
    'principles' => [],
    /**
     * array<int, array{label: string, value: string}> — the technical summary.
     * This is the page's only renderer for the `focus` section, which admins
     * edit, so it stays — folded into the panel footer rather than left as a
     * fourth block under it.
     *
     * @var array<int, array{label: string, value: string}>
     */
    'facts' => [],
    'headingId' => 'how-i-work-heading',
])

@php
    /**
     * How I work — section 05.
     *
     * One panel, one argument: two speeds, one gate. Fast judgement on the
     * left, deliberate reasoning on the right, and between them the engineering
     * decision flow where the two meet, get checked, and only then ship.
     *
     * ## The centre is the argument, and it is wired
     *
     * The diagram used to be inert decoration beside two rails that ignored it.
     * It is now the thing the panel is read against: hovering a node lights the
     * branch it belongs to and the rail that feeds it, and hovering a rail lights
     * the branch back. Converged nodes light both, which is the whole claim. The
     * links are plain `:has()` selectors, so where `:has()` is unsupported the
     * panel simply stays the static diagram it always was.
     *
     * ## Three cards a side
     *
     * The vocabulary carries more abilities than the panel can hold at this
     * height. Three per side is the widest set that still reads as a pair of
     * columns rather than a list, so the payload is sliced — first three, in
     * taxonomy order, which is also display order.
     */
    $modeOrder = ['system_1', 'system_2'];
    $byKey = [];

    foreach ($modes as $mode) {
        $byKey[$mode['key']] = $mode;
    }

    $cardsFor = fn (string $key): array => array_slice($byKey[$key]['cards'] ?? [], 0, 3);

    // One line icon per discipline. Keyed by the taxonomy key so a renamed
    // card keeps its mark; an unknown key falls back to a plain dot rather
    // than an empty box.
    $icons = [
        'rapid-prototyping' => 'M9.6 1.6 4.3 9.2h3.4l-1.3 5.2 5.3-7.6H8.3l1.3-5.2Z',
        'pattern-recognition' => 'M3 3h3.2v3.2H3zM9.8 3H13v3.2H9.8zM3 9.8h3.2V13H3zM9.8 9.8H13V13H9.8z',
        'quick-debugging' => 'M8 4.6a3.4 3.4 0 100 6.8 3.4 3.4 0 000-6.8ZM8 1.6v2M8 12.4v2M1.6 8h2M12.4 8h2',
        'system-architecture' => 'M2.4 3.2h4.2v4.2H2.4zM9.4 8.6h4.2v4.2H9.4zM6.6 5.3h2.8v5.4',
        'database-engineering' => 'M3 4.4c0-1 2.2-1.9 5-1.9s5 .9 5 1.9-2.2 1.9-5 1.9-5-.9-5-1.9ZM3 4.4v7.2c0 1 2.2 1.9 5 1.9s5-.9 5-1.9V4.4M3 8c0 1 2.2 1.9 5 1.9s5-.9 5-1.9',
        'security-analysis' => 'M8 1.8 13 3.4v4.3c0 3-2.1 5.2-5 6.4-2.9-1.2-5-3.4-5-6.4V3.4L8 1.8Z',
    ];

    $facts = array_values($facts);

    /**
     * The flow, as single-subpath segments in the order the eye should read
     * them. One subpath per `<path>` because SVG dashing restarts at each
     * subpath: a multi-segment path would draw all of its pieces at once,
     * which is exactly the sequential beat this animation is for.
     *
     * @var array<int, array{branch: string, d: string, delay: int}>
     */
    $segments = [
        ['branch' => 'fast', 'd' => 'M170 42V56', 'delay' => 220],
        ['branch' => 'fast', 'd' => 'M62 56H170', 'delay' => 300],
        ['branch' => 'fast', 'd' => 'M62 56V72', 'delay' => 380],
        ['branch' => 'deep', 'd' => 'M170 56H278', 'delay' => 320],
        ['branch' => 'deep', 'd' => 'M278 56V72', 'delay' => 400],
        ['branch' => 'fast', 'd' => 'M62 102V132', 'delay' => 560],
        ['branch' => 'deep', 'd' => 'M278 102V132', 'delay' => 600],
        ['branch' => 'fast', 'd' => 'M62 162V178H170', 'delay' => 740],
        ['branch' => 'deep', 'd' => 'M278 162V178H170', 'delay' => 780],
        ['branch' => 'live', 'd' => 'M170 178V192', 'delay' => 900],
        ['branch' => 'live', 'd' => 'M170 222V252', 'delay' => 1020],
        ['branch' => 'live', 'd' => 'M170 282V312', 'delay' => 1140],
    ];
@endphp

<section class="w-full px-5 sm:px-8 lg:px-10" aria-labelledby="{{ $headingId }}">
    <div class="mx-auto max-w-[76rem]">
        {{-- The header: the page's ordinal idiom, then the claim, then the
             sentence that qualifies it. The heading is admin-editable and can
             be long, so the text column takes `min-w-0 flex-1` — without it a
             two-line heading pushes the ordinal onto its own row and the header
             eats another 100px of the section. --}}
        <div class="max-w-4xl" data-reveal>
            <p class="font-mono text-2xs font-medium uppercase tracking-widest text-app-ink-faint">
                {{ $label }}
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-4 md:gap-6">
                <span class="sec-ordinal relative inline-block shrink-0 select-none">
                    <span class="exp-ordinal-stroke block text-5xl font-extrabold leading-none md:text-6xl">{{ str_pad((string) $number, 2, '0', STR_PAD_LEFT) }}</span>
                    <span aria-hidden="true" class="absolute inset-0 block text-5xl font-extrabold leading-none text-signal-600 md:text-6xl dark:text-signal-400">{{ str_pad((string) $number, 2, '0', STR_PAD_LEFT) }}</span>
                </span>

                <div class="inline-block min-w-0 flex-1">
                    <h2 id="{{ $headingId }}" class="sec-heading text-4xl font-bold leading-none tracking-[-0.025em] text-app-ink md:text-5xl">
                        {{ $heading }}
                    </h2>

                    <svg aria-hidden="true" class="sec-rule mt-4 block h-[10px] w-[190px] overflow-visible text-signal-600 md:w-[230px] dark:text-signal-400" viewBox="0 0 230 10" fill="none">
                        <path d="M3 7C55 2.5 120 9.5 165 5.5S215 4 227 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" pathLength="100" />
                    </svg>
                </div>
            </div>

            @if (filled($lead))
                <p class="sec-lead mt-5 max-w-xl text-base leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ $lead }}
                </p>
            @endif
        </div>

        {{-- ============================================================ --
         | The panel. One surface, both themes: every colour inside it
         | reads a role from the `--color-hiw-*` set in app.css, so it
         | follows the light/dark theme and the accent the visitor picked
         | rather than carrying a private palette. It powers up once as it
         | enters view — see the `hiw-*` keyframes.
         --}}
        <div class="hiw-panel mt-10" data-reveal>
            <div class="hiw-panel__grid">
                @foreach ($modeOrder as $modeKey)
                    @php
                        $mode = $byKey[$modeKey] ?? null;
                        $cards = $cardsFor($modeKey);
                        $isFast = $modeKey === 'system_1';
                    @endphp

                    <div class="hiw-rail {{ $isFast ? 'hiw-rail--fast' : 'hiw-rail--deep' }}">
                        <header class="hiw-rail__head">
                            <p class="hiw-rail__tag">
                                {{ $isFast ? __('System 1') : __('System 2') }}
                            </p>
                            <h3 class="hiw-rail__title">
                                {{ $mode['name'] ?? ($isFast ? __('Fast thinking') : __('Deep thinking')) }}
                            </h3>
                            <p class="hiw-rail__count">{{ count($cards) }} {{ __('disciplines') }}</p>
                        </header>

                        <ul class="hiw-cards">
                            @foreach ($cards as $index => $card)
                                <li class="hiw-card" style="--hiw-rise-delay: {{ $isFast ? 40 + $index * 50 : 110 + $index * 50 }}ms">
                                    <div class="hiw-card__top">
                                        <span class="hiw-card__icon" aria-hidden="true">
                                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="{{ $icons[$card['key']] ?? 'M8 6.6a1.4 1.4 0 100 2.8 1.4 1.4 0 000-2.8Z' }}" />
                                            </svg>
                                        </span>

                                        <span class="hiw-card__idx" aria-hidden="true">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                    </div>

                                    <h4 class="hiw-card__name">{{ $card['name'] }}</h4>
                                    <p class="hiw-card__text">{{ $card['description'] }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if ($isFast)
                        {{-- The centre: the decision flow. Sits between the two
                             rails in the grid, and moves to its own row on
                             narrow screens. --}}
                        <div class="hiw-flow">
                            <svg class="hiw-flow__svg" viewBox="0 0 340 352" role="img" aria-labelledby="hiw-flow-title hiw-flow-desc" fill="none">
                                <title id="hiw-flow-title">Engineering decision flow</title>
                                <desc id="hiw-flow-desc">A problem splits into a fast path of reproduce and bisect and a deliberate path of model and schema. Both meet at a decision gate, pass a guardrail, and ship.</desc>

                                <g class="hiw-flow__grid" aria-hidden="true">
                                    <path d="M62 0V352M170 0V352M278 0V352" />
                                    <path d="M0 56H340M0 178H340M0 240H340M0 300H340" />
                                </g>

                                {{-- Edges. Each segment is one subpath so it can
                                     draw itself in sequence; the fast branch is a
                                     muted hairline, the deliberate branch takes
                                     the accent, and the converged spine is the
                                     only thing that keeps marching afterwards. --}}
                                <g class="hiw-flow__edges" aria-hidden="true">
                                    @foreach ($segments as $segment)
                                        <path
                                            class="hiw-edge hiw-edge--{{ $segment['branch'] }}"
                                            style="--hiw-draw-delay: {{ $segment['delay'] }}ms"
                                            d="{{ $segment['d'] }}"
                                            pathLength="100"
                                        />
                                    @endforeach

                                    {{-- The marching dash over the drawn spine. It
                                         fades in only after the trace has arrived,
                                         because a dash pattern and a draw-in cannot
                                         share one stroke-dasharray. --}}
                                    <path class="hiw-edge hiw-edge--live" d="M170 178V312" pathLength="100" />
                                </g>

                                <g class="hiw-flow__nodes">
                                    <g class="hiw-node hiw-node--start">
                                        <rect x="111" y="12" width="118" height="30" rx="7" />
                                        <text x="170" y="28">PROBLEM</text>
                                    </g>

                                    <g class="hiw-node hiw-node--fast">
                                        <rect x="3" y="72" width="118" height="30" rx="7" />
                                        <text x="62" y="88">REPRODUCE</text>
                                    </g>

                                    <g class="hiw-node hiw-node--deep">
                                        <rect x="219" y="72" width="118" height="30" rx="7" />
                                        <text x="278" y="88">MODEL</text>
                                    </g>

                                    <g class="hiw-node hiw-node--fast">
                                        <rect x="3" y="132" width="118" height="30" rx="7" />
                                        <text x="62" y="148">BISECT</text>
                                    </g>

                                    <g class="hiw-node hiw-node--deep">
                                        <rect x="219" y="132" width="118" height="30" rx="7" />
                                        <text x="278" y="148">SCHEMA</text>
                                    </g>

                                    <g class="hiw-node hiw-node--gate">
                                        <rect x="111" y="192" width="118" height="30" rx="7" />
                                        <text x="170" y="208">DECIDE</text>
                                    </g>

                                    <g class="hiw-node hiw-node--gate">
                                        <rect x="111" y="252" width="118" height="30" rx="7" />
                                        <text x="170" y="268">GUARDRAIL</text>
                                    </g>

                                    <g class="hiw-node hiw-node--ship">
                                        <rect x="111" y="312" width="118" height="30" rx="7" />
                                        <text x="170" y="328">SHIP</text>
                                    </g>
                                </g>
                            </svg>

                            <p class="hiw-flow__caption">
                                <span class="hiw-flow__swatch hiw-flow__swatch--fast" aria-hidden="true"></span>
                                {{ __('fast path') }}
                                <span class="hiw-flow__swatch hiw-flow__swatch--deep" aria-hidden="true"></span>
                                {{ __('deliberate path') }}
                            </p>
                        </div>
                    @endif
                @endforeach
            </div>

            {{-- The technical summary, folded into the panel as its readout
                 row rather than left as a fourth block underneath it. --}}
            @if ($facts !== [])
                <dl class="hiw-facts">
                    @foreach ($facts as $fact)
                        <div class="hiw-fact">
                            <dt class="hiw-fact__label">{{ $fact['label'] }}</dt>
                            <dd class="hiw-fact__value">{{ $fact['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>

        {{-- The six defaults. A colophon index rather than six boxes: the rule
             above each cell does the separating, so nothing here competes with
             the card grids in the sections either side of it. --}}
        @if ($principles !== [])
            <div class="mt-12" data-reveal>
                <div class="flex items-center gap-3">
                    <span class="h-px w-8 bg-ink-300 dark:bg-ink-700"></span>
                    <p class="font-mono text-2xs uppercase tracking-widest text-app-ink-faint">
                        {{ __('The defaults underneath all of it') }}
                    </p>
                </div>

                <ol class="hiw-index mt-4 grid gap-x-8 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($principles as $index => $principle)
                        <li
                            data-reveal
                            style="transition-delay: {{ min($index * 50, 200) }}ms"
                            class="principle-card group relative py-4"
                        >
                            <span aria-hidden="true" class="principle-card__num absolute end-0 top-4 select-none font-mono text-4xl font-extrabold leading-none">
                                {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                            </span>

                            <span aria-hidden="true" class="principle-card__bar absolute inset-x-0 top-0 h-px bg-gradient-to-r from-signal-500 to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100"></span>

                            <p class="font-mono text-2xs tabular-nums uppercase tracking-widest text-signal-600 dark:text-signal-400">
                                {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                            </p>
                            <h4 class="relative mt-2 max-w-[20ch] text-base font-semibold tracking-tight text-app-ink">
                                {{ $principle['title'] }}
                            </h4>
                            <p class="relative mt-2 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                {{ $principle['body'] }}
                            </p>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </div>
</section>
