<?php

/*
|--------------------------------------------------------------------------
| Stat band (About, under the hero)
|--------------------------------------------------------------------------
|
| Four centered figures over hairline separators — the mhabir.dev stat
| band's grid, in this site's tokens. Data comes straight from
| `config('portfolio.author.stats')` (label + value strings); the component
| never invents figures.
|
| Progressive enhancement: the server renders each value already final, so
| no-JS readers see the real numbers. When `app.js` sees `data-countup` it
| counts the numeric part up once, the first time the figure scrolls into
| view — suffixes ("+", "M") ride along untouched, and single-digit values
| are displayed zero-padded ("9" → "09") to match the band's mono style.
|
*/

?>
@props([
    /** @var array<int, array{label: string, value: string}> $stats */
    'stats',
])

<div {{ $attributes->merge(['class' => 'border-b border-ink-200 dark:border-ink-800']) }}>
    {{-- Two columns on phones, four from md up — the exact breakpoint
         topology of the reference: every cell after the first is
         left-ruled, and the third loses its left rule on phones (it starts
         the second row) while gaining a top rule instead. --}}
    <div class="grid grid-cols-2 md:grid-cols-4">
        @foreach ($stats as $index => $stat)
            @php
                preg_match('/^(\d+)(.*)$/s', trim((string) $stat['value']), $parts);
                $numeric = isset($parts[1]);
                $target = $numeric ? (int) $parts[1] : null;
                $suffix = $numeric ? $parts[2] : '';
                $pad = $numeric && strlen($parts[1]) < 2;
                $display = $numeric
                    ? (($pad ? str_pad($parts[1], 2, '0', STR_PAD_LEFT) : $parts[1]).$suffix)
                    : $stat['value'];

                // mhabir.dev's cell rules, per column: first is naked, second
                // is left-ruled, the third swaps its left rule for a top rule
                // once the grid folds to two columns, the fourth keeps both.
                $edges = match ($index) {
                    0 => '',
                    1 => 'border-l',
                    2 => 'border-l max-md:border-t max-md:border-l-0',
                    default => 'border-l max-md:border-t',
                };
            @endphp

            <div
                data-reveal
                style="transition-delay: {{ min($index * 70, 280) }}ms"
                class="py-7 text-center md:py-9 {{ $edges }} {{ $index !== 0 ? 'border-ink-200 dark:border-ink-800' : '' }}"
            >
                <div
                    class="font-mono text-3xl font-semibold tracking-tight text-signal-600 tabular-nums md:text-4xl dark:text-signal-400"
                    @if ($numeric)
                        data-countup
                        data-count-target="{{ $target }}"
                        @if ($suffix !== '') data-count-suffix="{{ $suffix }}" @endif
                        @if ($pad) data-count-pad="2" @endif
                    @endif
                >{{ $display }}</div>

                <div class="mt-1.5 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ $stat['label'] }}
                </div>
            </div>
        @endforeach
    </div>
</div>
