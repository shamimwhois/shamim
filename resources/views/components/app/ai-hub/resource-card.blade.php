@props([
    'resource',
])

@php
    /**
     * Hub resource card.
     *
     * The one card in the hub that leaves the site, and it says so: the footer
     * reads "opens in a new tab" for a screen reader and shows an outward arrow
     * for everyone else. A card that looked like the article cards but threw a
     * new tab would be the sort of surprise that costs someone their place in a
     * learning path.
     *
     * The host is shown as well as the provider because the two answer different
     * questions — who maintains it, and where the link actually goes.
     */
@endphp

<a
    href="{{ $resource->url }}"
    target="_blank"
    rel="noopener noreferrer nofollow"
    aria-label="{{ __('View :name (opens in a new tab)', ['name' => $resource->title]) }}"
    {{ $attributes->merge(['class' => 'group relative flex h-full flex-col overflow-hidden rounded-xl border border-ink-200 bg-white transition duration-200 ease-soft hover:border-signal-400 hover:shadow-soft-2 dark:border-ink-800 dark:bg-ink-950 dark:hover:border-signal-600']) }}
>
    @if ($resource->is_featured)
        <span class="absolute right-3 top-3 z-10 rounded-full border border-signal-300 bg-signal-50 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-widest text-signal-700 dark:border-signal-800 dark:bg-signal-950 dark:text-signal-300">
            {{ __('Featured') }}
        </span>
    @endif

    <div class="focus-ring flex flex-1 flex-col p-5">
        <div class="flex max-w-[85%] items-center gap-2">
            <span class="grid size-8 shrink-0 place-items-center rounded-md border border-ink-200 bg-ink-50 text-ink-500 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-400">
                <flux:icon :name="$resource->kind->icon()" class="size-4" />
            </span>

            <span class="min-w-0 truncate font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                {{ $resource->kind->label() }}
            </span>
        </div>

        <h3 class="mt-4 text-base font-semibold leading-snug tracking-tight text-ink-900 dark:text-ink-50">
            {{ $resource->title }}
        </h3>

        @if (filled($resource->description))
            <p class="mt-2 line-clamp-2 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                {{ $resource->description }}
            </p>
        @endif

        <div class="mt-auto pt-5">
            <div class="flex flex-wrap items-center gap-2">
                @if (filled($resource->provider))
                    <x-app.ai-hub.pill>{{ $resource->provider }}</x-app.ai-hub.pill>
                @endif

                @if ($resource->topic)
                    <x-app.ai-hub.pill :icon="$resource->topic->icon ?? 'tag'">{{ $resource->topic->name }}</x-app.ai-hub.pill>
                @endif
            </div>

            <div class="mt-4 flex items-center justify-between gap-3 border-t border-ink-200 pt-4 dark:border-ink-800">
                <span class="min-w-0 truncate font-mono text-2xs text-ink-400 dark:text-ink-500">
                    {{ $resource->host() }}
                </span>

                <span class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-ink-500 transition-colors group-hover:text-signal-700 dark:text-ink-400 dark:group-hover:text-signal-400">
                    {{ __('Visit') }}
                    <flux:icon name="arrow-top-right-on-square" class="size-3.5" />
                </span>
            </div>
        </div>
    </div>
</a>
