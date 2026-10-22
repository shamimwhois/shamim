@props([
    /** Ordinal for the section marker. */
    'number',
    'label' => 'Thinking explorer',
    'accent' => false,
    /**
     * App\Services\AboutContent::thinkingExplorer() — modes + categories
     * (with their child nodes), already normalised through the taxonomy.
     */
    'explorer' => ['modes' => [], 'categories' => []],
    /**
     * array<int, array{title: string, body: string}> — the value claims.
     * The explorer replaced the old six-card grid, not the claims themselves:
     * they render as a rank-ordered rail beneath the band.
     */
    'reasons' => [],
    'ctaHref' => null,
    'ctaLabel' => 'Get in touch',
    'headingId' => 'thinking-explorer-heading',
])

@php
    /**
     * Thinking Explorer — the interactive rework of the "why hire me" band.
     *
     * The brain is the focal point: a procedural neural point cloud (canvas,
     * real perspective projection, pointer parallax — see thinking-explorer.js)
     * with one glowing hub per knowledge category. Two thinking modes retune
     * the signal behaviour; selecting a category lights its cluster and opens
     * an integrated mind map. All content is aria-live'd, keyboard reachable
     * and reduced-motion aware; without JavaScript the static SVG brain and
     * the claims rail still carry the section.
     *
     * The band keeps the always-dark instrument-panel treatment (deep navy,
     * cyan/teal accents) so it reads identically in both themes — the same
     * call as the AI Workflow panel. Decorative layers are aria-hidden.
     */
    $modes = $explorer['modes'] ?? [];
    $categories = array_values($explorer['categories'] ?? []);

    // Mind-map node positions are computed server-side (angle-based radial
    // layout) so the SVG edges and the HTML node buttons always agree.
    $nodePosition = function (int $index, int $count): array {
        $angle = -M_PI / 2 + ($index / max($count, 1)) * M_PI * 2;

        return [
            'x' => 50 + cos($angle) * 33,
            'y' => 50 + sin($angle) * 40,
        ];
    };

    $affinityBadge = [
        'fast' => ['label' => 'System 1', 'tone' => 'is-fast'],
        'deep' => ['label' => 'System 2', 'tone' => 'is-deep'],
        'both' => ['label' => 'System 1 × 2', 'tone' => 'is-both'],
    ];

    $payload = json_encode(
        ['modes' => $modes, 'categories' => $categories],
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT,
    );
@endphp

@vite('resources/js/thinking-explorer.js')

<section class="shell py-20" aria-labelledby="{{ $headingId }}">
    <x-app.ui.section-marker :number="$number" :label="$label" :accent="$accent" />

    <h2 id="{{ $headingId }}" class="mt-5 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
        How this mind works
    </h2>

    <p class="mt-4 max-w-2xl text-base leading-relaxed text-ink-500 dark:text-ink-400">
        Two thinking modes, six disciplines, one connected model. Hover a category to preview it, select one to open its map — and hold everything you read here to the claims listed underneath.
    </p>

    <div
        class="te-stage relative mt-12 overflow-hidden rounded-2xl"
        x-data="thinkingExplorer()"
    >
        <div aria-hidden="true" class="te-stage__grid"></div>

        {{-- Mode toggle: the two systems are a choice, not a loading state. --}}
        <div class="relative z-20 flex justify-center px-5 pt-8" role="group" aria-label="Thinking mode">
            <div class="te-modes" aria-live="polite">
                <button
                    type="button"
                    class="te-mode focus-ring"
                    :class="mode === 'system_1' && 'is-active'"
                    :aria-pressed="mode === 'system_1' ? 'true' : 'false'"
                    @click="mode = 'system_1'"
                >
                    Fast thinking
                </button>
                <button
                    type="button"
                    class="te-mode focus-ring"
                    :class="mode === 'system_2' && 'is-active'"
                    :aria-pressed="mode === 'system_2' ? 'true' : 'false'"
                    @click="mode = 'system_2'"
                >
                    Deep thinking
                </button>
            </div>
        </div>

        {{-- The brain stage: canvas over a static SVG fallback. Decorative —
             every interaction lives in the buttons below and beside it. --}}
        <div class="te-orbit relative mx-auto mt-6 w-full max-w-3xl px-5">
            <div class="te-brain relative mx-auto aspect-square w-full max-w-[26rem]">
                <svg
                    aria-hidden="true"
                    focusable="false"
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

            {{-- Category chips: in-flow wrap row on small screens, orbital
                 pills flanking the brain from lg up (CSS consumes the custom
                 properties only inside the lg media query). --}}
            <div class="te-chips relative z-20 mt-2 flex flex-wrap justify-center gap-2 lg:pointer-events-none lg:absolute lg:inset-0 lg:mt-0 lg:block">
                @foreach ($categories as $index => $category)
                    @php $chip = $index < count($categories) / 2 ? 'left' : 'right'; @endphp
                    <button
                        type="button"
                        class="te-chip focus-ring lg:pointer-events-auto"
                        :class="(active === '{{ $category['key'] }}' || preview === '{{ $category['key'] }}') && 'is-active'"
                        :aria-pressed="active === '{{ $category['key'] }}' ? 'true' : 'false'"
                        :style="'--chip-top: ' + chipPosition({{ $index }}, {{ count($categories) }}).top + '; --chip-left: ' + (chipPosition({{ $index }}, {{ count($categories) }}).left ?? 'auto') + '; --chip-right: ' + (chipPosition({{ $index }}, {{ count($categories) }}).right ?? 'auto')"
                        @click="toggle('{{ $category['key'] }}')"
                        @mouseenter="preview = '{{ $category['key'] }}'"
                        @mouseleave="preview = null"
                        @focus="preview = '{{ $category['key'] }}'"
                        @blur="preview = null"
                    >
                        <span aria-hidden="true" class="te-chip__dot"></span>
                        {{ $category['name'] }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Readout: the one place hover previews, selections and the mode
             description land, so nothing depends on hover alone. --}}
        <p class="te-readout relative z-20 mx-auto mt-4 max-w-xl px-6 text-center font-mono text-2xs uppercase tracking-widest" aria-live="polite">
            <span x-text="readout">{{ $modes['system_1']['description'] ?? '' }}</span>
        </p>

        {{-- Integrated mind-map panel: opens for the selected category. --}}
        <div class="te-panel relative z-20 mx-5 mb-8 mt-8 rounded-xl sm:mx-8" x-cloak x-show="active !== null">
            <template x-if="activeCategory">
                <div class="te-panel__inner p-5 sm:p-7">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="te-panel__title" x-text="activeCategory?.name"></h3>
                            <p class="te-panel__affinity" x-text="activeCategory?.affinity === 'fast' ? 'System 1 territory' : (activeCategory?.affinity === 'deep' ? 'System 2 territory' : 'Where both systems meet')"></p>
                        </div>
                        <button type="button" class="te-back focus-ring" @click="back()">
                            ← Back to overview
                        </button>
                    </div>

                    <p class="te-panel__description mt-3 max-w-prose text-sm leading-relaxed" x-text="activeCategory?.description"></p>

                    <div class="te-map relative mx-auto mt-6 aspect-[10/7] w-full max-w-xl">
                        <svg class="te-map__edges absolute inset-0 size-full" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                            @foreach ($categories as $edgeCategory)
                                <g x-show="active === '{{ $edgeCategory['key'] }}'" x-cloak>
                                    @foreach ($edgeCategory['nodes'] as $i => $node)
                                        @php $point = $nodePosition($i, count($edgeCategory['nodes'])); @endphp
                                        <line
                                            x1="50" y1="50"
                                            x2="{{ $point['x'] }}" y2="{{ $point['y'] }}"
                                            class="te-map__edge"
                                            :class="activeNode === {{ $i }} && 'is-active'"
                                        />
                                    @endforeach
                                </g>
                            @endforeach
                        </svg>

                        @foreach ($categories as $category)
                            <div class="absolute inset-0" x-show="active === '{{ $category['key'] }}'">
                                <span class="te-map__core" aria-hidden="true">{{ $category['name'] }}</span>

                                @foreach ($category['nodes'] as $i => $node)
                                    @php $point = $nodePosition($i, count($category['nodes'])); @endphp
                                    <button
                                        type="button"
                                        class="te-node focus-ring"
                                        :class="activeNode === {{ $i }} && 'is-active'"
                                        :aria-pressed="activeNode === {{ $i }} ? 'true' : 'false'"
                                        style="left: {{ $point['x'] }}%; top: {{ $point['y'] }}%;"
                                        @click="activeNode = (activeNode === {{ $i }} ? null : {{ $i }})"
                                    >
                                        {{ $node['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>

                    <div class="te-explanation" aria-live="polite">
                        <p class="te-explanation__hint" x-show="activeNode === null">Select a node to read the practice behind it.</p>
                        <p class="te-explanation__text" x-show="activeNode !== null" x-text="activeNodeText"></p>
                    </div>
                </div>
            </template>
        </div>

        {{-- Content payload: read by the Alpine component, never escaped
             into an attribute. --}}
        <script type="application/json" x-ref="payload">{{ $payload }}</script>
    </div>

    {{-- The value claims: the old six cards, demoted to the rank-ordered
         rail the brain now sits above. Same content, same numbering. --}}
    <div class="mt-14" data-reveal>
        <h3 class="text-2xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
            What you are actually getting
        </h3>
        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
            Skills are a commodity; how someone runs a project is not. These are the claims behind the model above, numbered so you can hold me to them.
        </p>

        <ol class="mt-6 grid gap-x-12 sm:grid-cols-2">
            @foreach ($reasons as $index => $reason)
                <li class="flex gap-5 border-b border-ink-200 py-5 dark:border-ink-800" data-reveal style="transition-delay: {{ min($index * 60, 240) }}ms">
                    <span class="mt-0.5 shrink-0 font-mono text-2xs tabular-nums text-signal-600 dark:text-signal-400">
                        {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <div>
                        <h4 class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                            {{ $reason['title'] }}
                        </h4>
                        <p class="mt-1.5 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ $reason['body'] }}
                        </p>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>

    @if ($ctaHref)
        <p class="mt-8 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-xs text-ink-500 dark:text-ink-400">
            <span>// the quickest proof is a conversation</span>
            <a
                href="{{ $ctaHref }}"
                class="group inline-flex items-center gap-1.5 font-medium text-signal-700 transition-colors hover:text-signal-600 dark:text-signal-400 dark:hover:text-signal-300"
            >
                {{ $ctaLabel }}
                <span aria-hidden="true" class="transition-transform group-hover:translate-x-0.5">→</span>
            </a>
        </p>
    @endif
</section>
