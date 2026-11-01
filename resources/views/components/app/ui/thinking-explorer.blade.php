@props([
    /** Ordinal for the section marker. */
    'number',
    'label' => 'Thinking explorer',
    'accent' => false,
    /**
     * The section heading and its lead. They used to be written into the
     * markup; they are props now so an operator can retitle the band
     * without touching the component.
     */
    'heading' => 'How this mind works',
    'lead' => 'The demonstration behind the sheet above. Two thinking modes, six disciplines, one connected model — hover a discipline to preview it, select one to open its map.',
    /**
     * App\Services\AboutContent::thinkingExplorer() — modes + categories
     * (with their child nodes), already normalised through the taxonomy.
     */
    'explorer' => ['modes' => [], 'categories' => []],
    'headingId' => 'thinking-explorer-heading',
])

@php
    /**
     * Thinking Explorer — the interactive rework of the "why hire me" band.
     *
     * ## The composition
     *
     * An annotated specimen plate, not a cockpit. The band is read in one
     * pass: a quiet rail of the six disciplines on the left, the model on
     * the right, and a single caption underneath that says what the visitor
     * is currently pointing at. Everything else that used to sit under the
     * canvas — a legend card per system, a renderer badge, a pause chip,
     * a centred readout in nine-pixel mono — has been folded into those
     * three things.
     *
     * The rail is the important move. The disciplines used to float around
     * the cortex as six tiny uppercase tags at six different angles, which
     * made them hard to read, hard to hit, and impossible to scan. In a
     * column they are a *list*: numbered, evenly spaced, one line each, the
     * same width, so the eye can run down them and the pointer always lands
     * on the same shape. The leader lines then turn the pair into a plate —
     * label, line, cluster — which is what makes the model legible rather
     * than decorative.
     *
     * ## Why the labels are in the flow
     *
     * The labels are the only way to reach a discipline, so they are the one
     * thing on the band that must never be a moving target. An earlier
     * version pinned each label to its cluster's projected position, which
     * meant the model turned underneath them at fifty pixels a second: a
     * label slid out from under the cursor between one press and the next,
     * and a cluster that rotated behind the cortex took its label — the only
     * route to that discipline — with it.
     *
     * They are now laid out by the browser, so "stationary" is a property of
     * the layout rather than something JavaScript has to be careful about.
     * The **anchor** — the glowing dot on the model — still travels with the
     * geometry, and a **leader line** ties the two together. Nothing that has
     * to be clicked is ever in motion.
     *
     * ## The interaction contract
     *
     *   - **Hover is a preview.** It lights one cluster and reads out its
     *     description. It never changes what is selected.
     *   - **Click is a selection.** It opens that category's knowledge graph
     *     and holds until another category is clicked.
     *
     * Nothing depends on hover alone: the readout is `aria-live`, the
     * categories are real buttons with `aria-pressed`, and every label is in
     * the markup whether or not JavaScript runs. Without JavaScript the band
     * is a readable list of disciplines beside a static brain.
     *
     * The band responds to the active color theme and accent, rendering with
     * appropriate canvas and panel colors in both light and dark mode while
     * reads as a sibling of the AI Workflow panel rather than a new idea.
     * Decorative layers are aria-hidden.
     */
    $modes = $explorer['modes'] ?? [];
    $categories = array_values($explorer['categories'] ?? []);

    // The two systems are fixed by the taxonomy; only their names and
    // descriptions are operator-managed. The switch therefore always says
    // which system it is ("System 1" / "System 2") and the legend below
    // carries the stored name, so a rename is visible rather than hidden.
    $modeOrder = ['system_1', 'system_2'];
    $modeChrome = [
        'system_1' => ['label' => 'System 1', 'side' => 'fast'],
        'system_2' => ['label' => 'System 2', 'side' => 'deep'],
    ];

    // The mind-map node positions are computed server-side (angle-based
    // radial layout) so the SVG edges and the HTML node buttons always
    // agree, and so the graph is correct before JavaScript runs.
    //
    // The map box is 4:3 and the SVG draws into a 400x300 viewBox, so the
    // scale is uniform and the arrow heads are not stretched. A node at
    // `left`/`top` percent of the box sits at `left * 4` / `top * 3` in
    // the viewBox.
    $nodePoint = function (int $index, int $count): array {
        $angle = -M_PI / 2 + ($index / max($count, 1)) * M_PI * 2;

        $x = 50 + cos($angle) * 30;
        $y = 50 + sin($angle) * 34;

        return [
            'left' => $x,
            'top' => $y,
            'vx' => $x * 4,
            'vy' => $y * 3,
        ];
    };

    // The rail is a single column, so a label's slot is decided by the
    // browser and the renderer measures it on resize. These three-letter
    // tags are the rail's right-hand column: compact enough that six of
    // them never read as a repeated sentence, specific enough that the
    // visitor can tell at a glance which system owns the discipline.
    $affinityBadge = [
        'fast' => ['tag' => 'S1', 'tone' => 'is-fast', 'sentence' => 'System 1 territory'],
        'deep' => ['tag' => 'S2', 'tone' => 'is-deep', 'sentence' => 'System 2 territory'],
        'both' => ['tag' => 'S1·S2', 'tone' => 'is-both', 'sentence' => 'Where both systems meet'],
    ];

    $payload = json_encode(
        ['modes' => $modes, 'categories' => $categories],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT,
    );
@endphp

@vite('resources/js/thinking-explorer.js')

{{-- Full bleed, like the AI Workflow and Capabilities bands: the section
     runs the width of the viewport and the page's own edge padding. --}}
<section class="w-full px-5 py-20 sm:px-8 lg:px-10" aria-labelledby="{{ $headingId }}">
    {{-- The header idiom every band on this page opens with: a ghost
         ordinal with the accent fill, the display heading, the hand-drawn
         squiggle and the muted lead. --}}
    <div class="mb-12 md:mb-16" data-reveal>
        <p class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-500 dark:text-ink-400">
            {{ $label }}
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-4 md:gap-6">
            <span class="sec-ordinal relative inline-block shrink-0 select-none">
                <span class="exp-ordinal-stroke block text-5xl font-extrabold leading-none md:text-6xl">{{ str_pad((string) $number, 02, '0', STR_PAD_LEFT) }}</span>
                <span aria-hidden="true" class="absolute inset-0 block text-5xl font-extrabold leading-none text-signal-600 md:text-6xl dark:text-signal-400">{{ str_pad((string) $number, 02, '0', STR_PAD_LEFT) }}</span>
            </span>

            <div class="inline-block">
                <h2 id="{{ $headingId }}" class="sec-heading text-4xl font-bold leading-none tracking-[-0.025em] text-ink-900 md:text-5xl dark:text-ink-50">
                    {{ $heading }}
                </h2>

                <svg aria-hidden="true" class="sec-rule mt-4 block h-[10px] w-[190px] overflow-visible text-signal-600 md:w-[230px] dark:text-signal-400" viewBox="0 0 230 10" fill="none">
                    <path d="M3 7C55 2.5 120 9.5 165 5.5S215 4 227 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" pathLength="100" />
                </svg>
            </div>
        </div>

        <p class="sec-lead mt-5 max-w-2xl text-base leading-relaxed text-ink-500 md:text-lg dark:text-ink-400">
            {{ $lead }}
        </p>
    </div>

    {{-- ============================================================ --
     | The stage. Three things only: a control bar, the plate (rail +
     | model), and the caption that reads out whatever is under the
     | pointer.
     --}}
    <div
        class="te-stage te-stage--explorer relative overflow-hidden rounded-2xl"
        :class="mode === 'system_2' && 'te-stage--deep'"
        x-data="thinkingExplorer()"
    >
        {{-- The control bar. The mode switch is a control, not a caption:
             it changes what the stage is showing. The renderer badge rides
             opposite it as instrumentation — honest, and quiet enough to
             ignore. --}}
        <div class="te-topbar">
            <div class="te-modes" role="group" aria-label="Thinking mode">
                @foreach ($modeOrder as $modeKey)
                    @php $chrome = $modeChrome[$modeKey]; @endphp
                    <button
                        type="button"
                        class="te-mode focus-ring"
                        :class="mode === '{{ $modeKey }}' && 'is-active'"
                        :aria-pressed="mode === '{{ $modeKey }}' ? 'true' : 'false'"
                        @click="mode = '{{ $modeKey }}'"
                    >
                        <span class="te-mode__dot" data-side="{{ $chrome['side'] }}" aria-hidden="true"></span>
                        {{ $chrome['label'] }}
                    </button>
                @endforeach
            </div>

            {{-- The renderer badge. Honest instrumentation: it says which of
                 the two paths is live, so the fallback is never mistaken for
                 a broken scene. The green dot is the band's only status
                 colour. --}}
            <p class="te-renderer" aria-hidden="true">
                <span class="te-renderer__dot"></span>
                <span x-show="renderer === 'webgl'">WebGL · 60 fps · <span x-text="categories.length"></span> clusters</span>
                <span x-show="renderer === 'canvas'" x-cloak>Canvas fallback · <span x-text="categories.length"></span> clusters</span>
                <span x-show="renderer === 'pending'" x-cloak>Starting renderer…</span>
            </p>
        </div>

        <div class="te-body">
            {{-- The rail: the six disciplines as a real list. Stationary by
                 construction — they are laid out by the browser, not pinned
                 to geometry — so every label is a stable click target and
                 the whole set can be scanned in one movement. --}}
            <div class="te-hubs" role="group" aria-label="Knowledge categories">
                <p class="te-hubs__label" aria-hidden="true">Disciplines</p>

                @foreach ($categories as $index => $category)
                    @php $badge = $affinityBadge[$category['affinity']] ?? $affinityBadge['both']; @endphp
                    <button
                        type="button"
                        class="te-hub focus-ring"
                        data-hub-key="{{ $category['key'] }}"
                        :class="(active === '{{ $category['key'] }}' || preview === '{{ $category['key'] }}') && 'is-active'"
                        :aria-pressed="active === '{{ $category['key'] }}' ? 'true' : 'false'"
                        @click="toggle('{{ $category['key'] }}')"
                        @mouseenter="preview = '{{ $category['key'] }}'"
                        @mouseleave="preview = null"
                        @focus="preview = '{{ $category['key'] }}'"
                        @blur="preview = null"
                    >
                        <span class="te-hub__dot" aria-hidden="true"></span>
                        <span class="te-hub__index" aria-hidden="true">{{ str_pad((string) ($index + 1), 02, '0', STR_PAD_LEFT) }}</span>
                        <span class="te-hub__name">
                            {{ $category['name'] }}
                            <span class="sr-only">, {{ $badge['sentence'] }}</span>
                        </span>
                        <span class="te-hub__badge {{ $badge['tone'] }}" aria-hidden="true">{{ $badge['tag'] }}</span>
                    </button>
                @endforeach
            </div>

            {{-- The model. The canvas is the picture; the static SVG
                 underneath is the no-JavaScript / no-canvas fallback. --}}
            <div
                class="te-scene"
                x-ref="scene"
                tabindex="0"
                role="group"
                aria-label="Interactive brain model. Use the arrow keys to turn it."
                @keydown="onSceneKeydown($event)"
            >
                <div class="te-brain">
                    <svg
                        aria-hidden="true"
                        focusable="false"
                        x-ref="fallback"
                        class="te-brain__fallback absolute inset-0 z-0 size-full"
                        viewBox="0 0 220 190"
                        fill="none"
                    >
                        <ellipse cx="110" cy="170" rx="82" ry="11" class="te-fallback__platform" />
                        <path
                            d="M110 14C74 14 46 38 42 70C39 92 46 112 60 128C74 146 92 162 110 162C128 162 146 146 160 128C174 112 181 92 178 70C174 38 146 14 110 14Z"
                            class="te-fallback__outline"
                        />
                        <path d="M110 14C106 36 114 56 110 78C106 100 114 122 110 162" class="te-fallback__fissure" />
                        <path d="M56 48C70 40 84 46 88 58" class="te-fallback__gyre" />
                        <path d="M48 76C62 68 78 74 86 88" class="te-fallback__gyre" />
                        <path d="M58 108C70 100 86 104 92 118" class="te-fallback__gyre" />
                        <path d="M164 48C150 40 136 46 132 58" class="te-fallback__gyre" />
                        <path d="M172 76C158 68 142 74 134 88" class="te-fallback__gyre" />
                        <path d="M162 108C150 100 134 104 128 118" class="te-fallback__gyre" />
                    </svg>

                    <canvas x-ref="canvas" aria-hidden="true" class="te-brain__canvas absolute inset-0 z-10 size-full"></canvas>
                </div>

                {{-- The connector lines. Only one is ever drawn: the line from
                     the label you are pointing at to the cluster it names.
                     Six permanent leaders turned the plate into a tangle of
                     dashes that crossed the cortex and each other; the rail's
                     numbers already carry the mapping, so the line is the
                     confirmation rather than the index. The viewBox is
                     written by the renderer so the coordinates are pixels. --}}
                <svg
                    x-ref="leads"
                    aria-hidden="true"
                    focusable="false"
                    class="te-leads absolute inset-0 z-20 size-full"
                    viewBox="0 0 100 100"
                    preserveAspectRatio="none"
                >
                    @foreach ($categories as $category)
                        <line
                            class="te-lead"
                            data-lead-key="{{ $category['key'] }}"
                            :class="(active === '{{ $category['key'] }}' || preview === '{{ $category['key'] }}') && 'is-live'"
                            x1="0"
                            y1="0"
                            x2="0"
                            y2="0"
                        />
                    @endforeach
                </svg>

                {{-- The travelling anchors: the numbered pins that ride the
                     clusters. They are decorative — the rail is the control —
                     and they carry the same numeral as the rail row, which is
                     what makes the pair read as one annotated plate. --}}
                <div class="te-anchors" aria-hidden="true">
                    @foreach ($categories as $category)
                        <span
                            class="te-anchor"
                            data-anchor-key="{{ $category['key'] }}"
                            :class="(active === '{{ $category['key'] }}' || preview === '{{ $category['key'] }}') && 'is-live'"
                        >{{ str_pad((string) ($loop->iteration), 2, '0', STR_PAD_LEFT) }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- The caption. This is the readout — the one place hover previews,
             selections and the mode description land, so nothing depends on
             hover alone. It is set as editorial copy rather than a mono
             strip: an eyebrow that says which system is speaking, the name
             of the thing, and its sentence. --}}
        <div class="te-caption">
            <div class="te-caption__body">
                <p class="te-caption__eyebrow" x-text="readoutEyebrow">Thinking mode</p>
                <p class="te-caption__title" x-text="readoutTitle">{{ $modes['system_1']['name'] ?? 'System 1' }}</p>
                <p class="te-readout" aria-live="polite">
                    <span x-text="readout">{{ $modes['system_1']['description'] ?? __('Hover a category to preview it. Select one to open its map.') }}</span>
                </p>
            </div>

            {{-- The motion switch. The scene moves on its own, and a scene
                 that moves on its own has to be stoppable — otherwise it is
                 moving content presented in parallel with the rest of the
                 page, with no way to stop it. --}}
            <div class="te-motion">
                <button
                    type="button"
                    class="te-motion__toggle focus-ring"
                    @click="toggleMotion()"
                    :aria-pressed="motionPaused ? 'true' : 'false'"
                    x-show="!motionLocked"
                    x-cloak
                >
                    <span class="te-motion__dot" aria-hidden="true"></span>
                    <span x-text="motionPaused ? 'Resume the motion' : 'Pause the motion'">Pause the motion</span>
                </button>

                <p class="te-motion__note" x-show="motionLocked" x-cloak>
                    Motion reduced by your system setting
                </p>
            </div>
        </div>

        {{-- The mode legend. The systems themselves are fixed; their names
             and descriptions are operator-managed rows, so they are rendered
             here — named, not paraphrased. The active one is lit; the other
             is present but dim, so the page still says what the model is.
             Typographic columns, not cards: the caption above already
             carries the live reading, so this is the footnote. --}}
        <ul class="te-mode-legend" aria-label="Thinking modes">
            @foreach ($modeOrder as $modeKey)
                @php $mode = $modes[$modeKey] ?? null; @endphp
                @if ($mode)
                    <li
                        class="te-mode-legend__item"
                        :class="mode === '{{ $modeKey }}' && 'is-active'"
                    >
                        <p class="te-mode-legend__name">{{ $mode['name'] }}</p>
                        <p class="te-mode-legend__text">{{ $mode['description'] }}</p>
                    </li>
                @endif
            @endforeach
        </ul>

        {{-- ============================================================ --
         | The knowledge graph. It opens as an overlay on the stage so
         | the visualisation never leaves the frame — the brain is
         | still there behind it, still lit.
         --}}
        <div
            class="te-drawer"
            x-cloak
            x-show="active !== null"
            @keydown.escape.window="active !== null && back()"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-4"
        >
            <div class="te-drawer__inner">
                <template x-if="activeCategory">
                    <div class="te-graph">
                        <div class="te-graph__head">
                            <div class="min-w-0">
                                <p class="te-graph__eyebrow" x-text="activeCategory?.affinity === 'fast' ? 'System 1 territory' : (activeCategory?.affinity === 'deep' ? 'System 2 territory' : 'Where both systems meet')"></p>
                                <h3 class="te-graph__title" x-text="activeCategory?.name"></h3>
                                <p class="te-graph__description" x-text="activeCategory?.description"></p>
                            </div>

                            <button type="button" class="te-back focus-ring shrink-0" @click="back()">
                                &larr; {{ __('Back to the brain') }}
                            </button>
                        </div>

                        {{-- The graph: the selected concept in the middle,
                             its child concepts around it, and the
                             pathways between them. Every edge is drawn
                             server-side so the graph is correct before
                             JavaScript runs; the active node's edge is
                             the one that lights up. --}}
                        <div class="te-map relative mx-auto mt-5 aspect-[4/3] w-full max-w-lg">
                            <svg class="te-map__edges absolute inset-0 size-full" viewBox="0 0 400 300" aria-hidden="true">
                                <defs>
                                    <marker id="te-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                        <path d="M0 0 L10 5 L0 10 z" fill="currentColor" />
                                    </marker>
                                </defs>

                                @foreach ($categories as $category)
                                    <g x-show="active === '{{ $category['key'] }}'" x-cloak>
                                        @foreach ($category['nodes'] as $i => $node)
                                            @php $point = $nodePoint($i, count($category['nodes'])); @endphp
                                            <line
                                                x1="200" y1="150"
                                                x2="{{ round($point['vx'], 2) }}" y2="{{ round($point['vy'], 2) }}"
                                                class="te-map__edge"
                                                :class="activeNode === {{ $i }} && 'is-active'"
                                                marker-end="url(#te-arrow)"
                                            />
                                        @endforeach
                                    </g>
                                @endforeach
                            </svg>

                            @foreach ($categories as $category)
                                <div class="absolute inset-0" x-show="active === '{{ $category['key'] }}'">
                                    <span class="te-map__core" aria-hidden="true">{{ $category['name'] }}</span>

                                    @foreach ($category['nodes'] as $i => $node)
                                        @php $point = $nodePoint($i, count($category['nodes'])); @endphp
                                        <button
                                            type="button"
                                            class="te-node focus-ring"
                                            :class="activeNode === {{ $i }} && 'is-active'"
                                            :aria-pressed="activeNode === {{ $i }} ? 'true' : 'false'"
                                            style="left: {{ round($point['left'], 2) }}%; top: {{ round($point['top'], 2) }}%;"
                                            @click="activeNode = (activeNode === {{ $i }} ? null : {{ $i }})"
                                        >
                                            {{ $node['label'] }}
                                        </button>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>

                        <div class="te-explanation" aria-live="polite">
                            <p class="te-explanation__hint" x-show="activeNode === null">{{ __('Select a node to read the practice behind it.') }}</p>
                            <p class="te-explanation__text" x-show="activeNode !== null">
                                <strong class="font-semibold text-white" x-text="activeCategory?.nodes?.[activeNode]?.label"></strong>
                                <span x-text="activeNodeText"></span>
                            </p>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Content payload: read by the Alpine component, never escaped
             into an attribute. The JSON flags above make it safe inside a
             script element. --}}
        <script type="application/json" data-thinking-explorer-payload>{!! $payload !!}</script>
    </div>

</section>
