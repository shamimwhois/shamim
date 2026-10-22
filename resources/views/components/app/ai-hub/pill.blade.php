@props([
    'icon' => null,
    'tone' => 'neutral',
])

@php
    /**
     * Hub pill.
     *
     * The small uppercase chip that carries a difficulty, a format, a resource
     * kind or a topic name on the hub's cards and headers. It exists because four
     * different row types needed the same monospace chip and one of them
     * (`AiResourceKind`) renders its own icon — repeating that markup per card is
     * how the four shelves drift apart in practice.
     *
     * Two tones only. The hub's palette has one accent, and a difficulty ramp
     * drawn in four colours would be read as a quality score rather than as a
     * starting point; the label text already says which.
     */
    $tones = [
        'neutral' => 'border-ink-200 bg-ink-50 text-ink-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-300',
        'signal' => 'border-signal-300 bg-signal-50 text-signal-700 dark:border-signal-800 dark:bg-signal-950 dark:text-signal-300',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex min-w-0 items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-mono text-2xs uppercase tracking-widest '.$tones[$tone] ?? $tones['neutral']]) }}>
    @if ($icon)
        <flux:icon :name="$icon" class="size-3 shrink-0" />
    @endif

    <span class="truncate">{{ $slot }}</span>
</span>
