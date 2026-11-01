@props([
    'label',
    'value',
    'icon' => 'squares-2x2',
    'hint' => null,
    'href' => null,
    'tone' => 'primary',
])

@php
    /**
     * Dashboard metric tile.
     *
     * A `sui-card` that rises one shadow level on hover when it is also a
     * link — the lift is what tells you the whole tile is the target and not
     * just the label. When `href` is null it is a plain surface and does not
     * advertise a click it cannot deliver.
     *
     * ## The pastel square
     *
     * The glyph sits in a tinted square from the six-tone pastel family —
     * the Creative Tim signature, re-expressed so the wash/ink pair clears
     * 4.5:1 rather than leaning on a saturated fill that fails on white.
     * The tone is assigned per metric by the caller, not by index, because
     * a metric's identity should survive its position in the grid.
     *
     * `tone="attention"` is the one override: the moderation queue, where a
     * non-zero value means there is work waiting. It uses the amber
     * attention family and only engages when the value is actually above
     * zero, so a clear queue is a quiet tile rather than an amber one.
     */
    $pastels = ['primary', 'secondary', 'info', 'success', 'warning', 'danger'];

    $attention = $tone === 'attention' && (int) $value > 0;
    $pastel = in_array($tone, $pastels, true) ? $tone : 'primary';

    $Tag = $href ? 'a' : 'div';
@endphp

<{{ $Tag }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->merge([
        'class' => 'group block rounded-xl border bg-sui-surface transition duration-200 ease-soft '
            .($href ? 'hover:border-sui-border hover:shadow-soft-2 ' : '')
            .($attention
                ? 'border-sui-attention-border shadow-soft-1 dark:border-sui-attention-border/30'
                : 'border-sui-hairline shadow-soft-1'),
    ]) }}
>
    <div class="flex items-start justify-between gap-4 p-5">
        <div class="min-w-0">
            <p class="sui-label">
                {{ $label }}
            </p>

            <p @class([
                'mt-3 text-3xl font-semibold tabular-nums tracking-tightest',
                'text-sui-attention-ink dark:text-sui-attention-ink' => $attention,
                'sui-text-gradient' => ! $attention,
            ])>
                {{ is_numeric($value) ? number_format($value) : $value }}
            </p>

            @if ($hint)
                <p class="mt-1.5 text-xs leading-normal text-ink-500">{{ $hint }}</p>
            @endif
        </div>

        <div @class([
            'grid size-10 shrink-0 place-items-center rounded-md shadow-soft-1 transition duration-150 ease-soft',
            'border border-sui-attention-border bg-sui-attention-wash text-sui-attention dark:border-sui-attention-border/40 dark:bg-sui-attention-wash/40 dark:text-sui-attention' => $attention,
            "bg-sui-pastel-{$pastel}-wash text-sui-pastel-{$pastel}-ink" => ! $attention,
        ])>
            <flux:icon :name="$icon" class="size-5" />
        </div>
    </div>
</{{ $Tag }}>
