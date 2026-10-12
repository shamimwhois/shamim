@props([
    'label',
    'value',
    'icon' => 'squares-2x2',
    'hint' => null,
    'href' => null,
    'tone' => 'default',
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
     * `tone="attention"` is used for the moderation queue, where a non-zero
     * value means there is work waiting. Everything else stays neutral so the
     * one number that needs action is the one that catches the eye. The
     * attention ink is the `sui-attention-ink` family: the plain
     * `sui-attention` (amber-700) measures 3.19:1 on white and would not
     * clear AA at this size.
     */
    $attention = $tone === 'attention' && (int) $value > 0;
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
                'text-ink-900' => ! $attention,
            ])>
                {{ is_numeric($value) ? number_format($value) : $value }}
            </p>

            @if ($hint)
                <p class="mt-1.5 text-xs leading-normal text-ink-500">{{ $hint }}</p>
            @endif
        </div>

        <div @class([
            'grid size-9 shrink-0 place-items-center rounded-md border transition duration-150 ease-soft',
            'border-sui-attention-border bg-sui-attention-wash text-sui-attention dark:border-sui-attention-border/40 dark:bg-sui-attention-wash/40 dark:text-sui-attention' => $attention,
            'border-sui-hairline bg-sui-well text-ink-400 group-hover:text-sui-accent-ink' => ! $attention,
        ])>
            <flux:icon :name="$icon" class="size-4.5" />
        </div>
    </div>
</{{ $Tag }}>
