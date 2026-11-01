@props([
    'title',
    'description' => null,
    'eyebrow' => null,
])

@php
    /**
     * Auth screen heading.
     *
     * Sits inside the form column of the auth layout — *not* on the editorial
     * panel, which has its own headline. The two used to be visually similar
     * enough that a page carried the same message twice.
     *
     * The optional `eyebrow` is the monospace ordinal used throughout the
     * public site (`x-app.ui.section-marker`), so the transition from marketing pages
     * into authenticated screens reads as one continuous design rather than a
     * hand-off to a framework's default scaffolding.
     */
@endphp

<div {{ $attributes->merge(['class' => 'flex w-full flex-col gap-4 text-center']) }}>
    @if ($eyebrow)
        <div class="flex justify-center">
            <span class="font-mono text-2xs font-medium uppercase tracking-widest text-signal-600 dark:text-signal-400">
                {{ $eyebrow }}
            </span>
        </div>
    @endif

    <div class="space-y-2">
        <flux:heading size="2xl" level="1" class="tracking-tighter">{{ $title }}</flux:heading>

        @if ($description)
            <flux:subheading class="text-balance">{{ $description }}</flux:subheading>
        @endif
    </div>
</div>
