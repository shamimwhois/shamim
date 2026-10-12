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
     * `tone="attention"` is used for the moderation queue, where a non-zero
     * value means there is work waiting. Everything else stays neutral so the
     * one number that needs action is the one that catches the eye.
     */
    $attention = $tone === 'attention' && (int) $value > 0;
    $Tag = $href ? 'a' : 'div';
@endphp

<{{ $Tag }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->merge([
        'class' => 'group block rounded-lg border bg-white p-5 transition-all duration-200 dark:bg-ink-900 '
            .($attention
                ? 'border-amber-300 hover:border-amber-400 dark:border-amber-500/40 dark:hover:border-amber-500/70'
                : 'border-ink-200 hover:border-ink-300 hover:shadow-lift dark:border-ink-800 dark:hover:border-ink-700'),
    ]) }}
>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                {{ $label }}
            </p>

            <p @class([
                'mt-3 text-3xl font-semibold tabular-nums tracking-tightest',
                'text-amber-600 dark:text-amber-400' => $attention,
                'text-ink-900 dark:text-ink-50' => ! $attention,
            ])>
                {{ is_numeric($value) ? number_format($value) : $value }}
            </p>

            @if ($hint)
                <p class="mt-1.5 text-xs text-ink-400 dark:text-ink-500">{{ $hint }}</p>
            @endif
        </div>

        <div @class([
            'grid size-9 shrink-0 place-items-center rounded-md border transition-colors',
            'border-amber-300 bg-amber-50 text-amber-600 dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-400' => $attention,
            'border-ink-200 bg-ink-50 text-ink-400 group-hover:border-signal-400 group-hover:text-signal-600 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-500 dark:group-hover:border-signal-600 dark:group-hover:text-signal-400' => ! $attention,
        ])>
            <flux:icon :name="$icon" class="size-4.5" />
        </div>
    </div>
</{{ $Tag }}>
