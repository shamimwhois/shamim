@props([
    'path',
])

@php
    /**
     * Hub learning-path card.
     *
     * A path is a promise about order, so the card states the two things that
     * make the promise cost something to accept: how many lessons and how many
     * minutes. Both come from the model, which sums reading time in the database
     * rather than multiplying a guess by a count.
     */
@endphp

<a
    href="{{ route('ai-hub.path', ['path' => $path->slug]) }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'focus-ring group relative flex h-full flex-col overflow-hidden rounded-xl border border-ink-200 bg-white p-5 transition duration-200 ease-soft hover:border-signal-400 hover:shadow-soft-2 dark:border-ink-800 dark:bg-ink-950 dark:hover:border-signal-600']) }}
>
    @if ($path->is_featured)
        <span class="absolute right-3 top-3 rounded-full border border-signal-300 bg-signal-50 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-widest text-signal-700 dark:border-signal-800 dark:bg-signal-950 dark:text-signal-300">
            {{ __('Featured') }}
        </span>
    @endif

    <div class="flex max-w-[80%] items-center gap-2">
        <span class="grid size-8 shrink-0 place-items-center rounded-md border border-ink-200 bg-ink-50 text-ink-500 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-400">
            <flux:icon name="map" class="size-4" />
        </span>

        <span class="min-w-0 truncate font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
            {{ $path->topic?->name ?? __('Learning path') }}
        </span>
    </div>

    <h3 class="mt-4 text-base font-semibold leading-snug tracking-tight text-ink-900 dark:text-ink-50">
        {{ $path->title }}
    </h3>

    @if (filled($path->description))
        <p class="mt-2 line-clamp-2 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
            {{ $path->description }}
        </p>
    @endif

    <div class="mt-auto pt-5">
        <dl class="grid grid-cols-2 gap-3">
            <div>
                <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Lessons') }}
                </dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-ink-900 dark:text-ink-50">
                    {{ $path->lessonCount() }}
                </dd>
            </div>

            <div>
                <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Estimate') }}
                </dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-ink-900 dark:text-ink-50">
                    {{ $path->estimatedMinutesLabel() }}
                </dd>
            </div>
        </dl>

        <div class="mt-4 flex items-center justify-between gap-3 border-t border-ink-200 pt-4 dark:border-ink-800">
            <x-app.ai-hub.pill>{{ $path->difficulty->label() }}</x-app.ai-hub.pill>

            <span class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-ink-500 transition-colors group-hover:text-signal-700 dark:text-ink-400 dark:group-hover:text-signal-400">
                {{ __('Start path') }}
                <span aria-hidden="true" class="transition-transform duration-150 group-hover:translate-x-0.5">&rarr;</span>
            </span>
        </div>
    </div>
</a>
