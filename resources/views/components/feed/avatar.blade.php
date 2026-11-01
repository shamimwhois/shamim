@props([
    'initials',
    'tone' => 'neutral', // neutral | azure | mint
    'size' => 'md', // sm | md | lg
])

@php
    $tones = [
        'neutral' => 'bg-feed-raised text-feed-ink-2',
        'azure' => 'bg-feed-azure/12 text-feed-azure',
        'mint' => 'bg-feed-mint/14 text-feed-mint',
    ];
    $sizes = [
        'sm' => 'size-7 text-[11px]',
        'md' => 'size-10 text-[13px]',
        'lg' => 'size-14 text-base',
    ];
@endphp

<span
    {{ $attributes->class(['grid shrink-0 place-items-center rounded-full font-mono font-semibold', $tones[$tone] ?? $tones['neutral'], $sizes[$size] ?? $sizes['md']]) }}
    aria-hidden="true"
>{{ $initials }}</span>
