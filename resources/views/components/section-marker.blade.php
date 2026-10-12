@props([
    'number',
    'label' => null,
    'accent' => false,
])

@php
    /**
     * The numbered monospace section marker — signature element #1.
     *
     * `01 — Selected work` renders as a two-character ordinal, an em dash, and
     * an uppercase tracked label. Used consistently at the top of every major
     * section so the page has a visible ribcage.
     */
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    <span @class([
        'font-mono text-2xs font-medium tabular-nums',
        'text-signal-600 dark:text-signal-400' => $accent,
        'text-ink-400 dark:text-ink-500' => ! $accent,
    ])>
        {{ str_pad((string) $number, 2, '0', STR_PAD_LEFT) }}
    </span>

    <span aria-hidden="true" class="h-px w-6 bg-ink-200 dark:bg-ink-800"></span>

    @if ($label)
        <span class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-500 dark:text-ink-400">
            {{ $label }}
        </span>
    @endif
</div>
