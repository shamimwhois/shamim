@props([
    'title',
    'description' => null,
    'eyebrow' => null,
])

@php
    /**
     * Dashboard page header.
     *
     * Consistent across every dashboard surface: an optional monospace eyebrow,
     * a title, a one-line description, and a right-hand actions slot.
     */
@endphp

<header {{ $attributes->merge(['class' => 'border-b border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900']) }}>
    <div class="px-6 py-6 lg:px-10 lg:py-8">
        <div class="flex flex-wrap items-start justify-between gap-6">
            <div class="min-w-0">
                @if ($eyebrow)
                    <p class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        {{ $eyebrow }}
                    </p>
                @endif

                <h1 class="mt-2 text-2xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    {{ $title }}
                </h1>

                @if ($description)
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                        {{ $description }}
                    </p>
                @endif
            </div>

            @if ($actions ?? null)
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endif
        </div>
    </div>
</header>
