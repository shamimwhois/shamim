@props([
    'title',
    'description' => null,
    'series' => [],
    'labels' => [],
    'empty' => null,
])

@php
    /**
     * The activity chart card.
     *
     * An area chart drawn as inline SVG rather than pulled from a charting
     * library: the dashboard needs one chart, and a 30kB dependency that
     * renders axes, tooltips and eleven legends is the wrong trade for one
     * sparkline-plus-area. The SVG scales from its viewBox, so it is crisp
     * at any width, and every colour in it is a token — the fill is a
     * gradient from the accent at 25% to nothing, which is the only
     * gradient in the system that carries no text and therefore has no
     * contrast obligation beyond "visible".
     *
     * The series arrives as plain integers from the page — this component
     * does no querying. The caller owns what "activity" means; the card
     * owns what it looks like.
     *
     * ## The maths
     *
     * Straight segments with round joins, deliberately: a Catmull-Rom
     * smoothing would overshoot and invent peaks the data does not
     * contain. The scale is zero-based and the baseline padded 10px, so a
     * week with nothing published still sits on the axis rather than on
     * the very edge of the viewBox.
     */
    $count = count($series);

    $width = 600;
    $height = 170;
    $pad = 10;

    $max = $count > 0 ? (int) max($series) : 0;
    $scaleMax = $max > 0 ? $max : 1;

    /* Two conditions, and both are about not drawing a chart that says
       nothing. A single point has no shape, so it is a number rather than a
       chart. A series that sums to zero would draw a flat line riding the
       baseline — which looks exactly like data, and is the opposite: it is
       absence, and absence belongs in the empty state, not on a grid. */
    $points = [];

    if ($count > 1 && array_sum($series) > 0) {
        $step = ($width - 2 * $pad) / ($count - 1);

        foreach ($series as $index => $value) {
            $points[] = [
                round($pad + $index * $step, 2),
                round($height - $pad - ((int) $value / $scaleMax) * ($height - 2 * $pad), 2),
            ];
        }
    }

    $linePath = '';

    if ($points !== []) {
        $linePath = 'M '.$points[0][0].','.$points[0][1];

        foreach ($points as $index => $point) {
            /* The move-to already placed the first point; a `L` back to it
               would be a zero-length segment at the head of the path. */
            if ($index === 0) {
                continue;
            }

            $linePath .= ' L '.$point[0].','.$point[1];
        }
    }

    $areaPath = '';

    if ($linePath !== '' && $count > 1) {
        /* Close the shape down to the baseline. The last point is skipped when
           it already rests on the baseline — a zero-length segment there is
           invisible, but it is also a stroke the path never needed. `round()`
           hands back a float, so the comparison is numeric rather than
           strict, or 160.0 would never match 160. */
        $baseline = (float) ($height - $pad);
        $tail = ((float) $points[$count - 1][1] === $baseline) ? '' : ' L '.$points[$count - 1][0].','.$baseline;

        $areaPath = $linePath.$tail.' L '.$points[0][0].','.$baseline.' Z';
    }

    /* One label tick per point, thinned so the axis never collides. */
    $labelStride = $count > 10 ? 2 : 1;

    $ticks = [];

    foreach ($labels as $index => $label) {
        if ($index % $labelStride !== 0) {
            continue;
        }

        $position = $count > 1 ? ($index / ($count - 1)) * 100 : 50;

        $ticks[] = [
            'label' => $label,
            'left' => round($position, 2),
        ];
    }
@endphp

<section class="sui-card p-5 sm:p-6" aria-label="{{ $title }}">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-sui-ink">{{ $title }}</h3>

            @if ($description)
                <p class="mt-1 text-xs leading-normal text-ink-500">{{ $description }}</p>
            @endif
        </div>

        @if ($actions ?? null)
            <div class="flex shrink-0 items-center gap-2">
                {{ $actions }}
            </div>
        @endif
    </div>

    @if ($points === [])
        <div class="mt-6 px-4 py-12 text-center text-sm text-ink-500">
            {{ $empty ?? __('Not enough activity to chart yet.') }}
        </div>
    @else
        <div class="mt-6">
            <svg
                viewBox="0 0 {{ $width }} {{ $height }}"
                class="h-auto w-full"
                role="img"
                aria-label="{{ $title }}"
                preserveAspectRatio="xMidYMid meet"
            >
                <defs>
                    <linearGradient id="chartAreaFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" style="stop-color: var(--color-sui-accent); stop-opacity: 0.22;" />
                        <stop offset="100%" style="stop-color: var(--color-sui-accent); stop-opacity: 0;" />
                    </linearGradient>
                </defs>

                {{-- Three soft rules to read the shape against. --}}
                <line x1="0" y1="{{ $height * 0.25 }}" x2="{{ $width }}" y2="{{ $height * 0.25 }}" class="stroke-sui-hairline" stroke-width="1" />
                <line x1="0" y1="{{ $height * 0.5 }}" x2="{{ $width }}" y2="{{ $height * 0.5 }}" class="stroke-sui-hairline" stroke-width="1" />
                <line x1="0" y1="{{ $height * 0.75 }}" x2="{{ $width }}" y2="{{ $height * 0.75 }}" class="stroke-sui-hairline" stroke-width="1" />

                @if ($areaPath !== '')
                    <path d="{{ $areaPath }}" fill="url(#chartAreaFill)" />
                @endif

                <path
                    d="{{ $linePath }}"
                    fill="none"
                    stroke="var(--color-sui-accent)"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    vector-effect="non-scaling-stroke"
                />
            </svg>

            <div class="relative mt-2 h-4">
                @foreach ($ticks as $tick)
                    <span
                        class="absolute top-0 -translate-x-1/2 text-2xs tabular-nums text-ink-400"
                        style="left: {{ $tick['left'] }}%"
                    >
                        {{ $tick['label'] }}
                    </span>
                @endforeach
            </div>
        </div>

        @if ($legend ?? null)
            <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-sui-hairline pt-4">
                {{ $legend }}
            </div>
        @endif
    @endif
</section>
