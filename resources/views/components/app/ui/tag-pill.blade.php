@props([
    'tag',
    'active' => false,
    'count' => null,
])

@php
    /**
     * A tag chip. Two variants: a filter link (default) and a static "active"
     * state used to show the currently applied filter with a dismiss affordance.
     */
@endphp

<a
    href="{{ route('blog.index', ['tag' => $active ? null : $tag->slug]) }}"
    wire:navigate
    @if ($active) aria-current="true" @endif
    @class([
        'tag-pill',
        'border-signal-400 bg-signal-50 text-signal-700 dark:border-signal-600 dark:bg-signal-950 dark:text-signal-300' => $active,
    ])
>
    <span>{{ $tag->name }}</span>

    @if ($count !== null)
        <span aria-hidden="true" class="text-ink-400 dark:text-ink-500">{{ $count }}</span>
    @endif

    @if ($active)
        <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
        <span class="sr-only">Remove {{ $tag->name }} filter</span>
    @endif
</a>
