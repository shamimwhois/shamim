@props([
    'title' => 'Nothing here yet',
    'description' => null,
    'icon' => 'magnifying-glass',
])

@php
    /**
     * Empty state.
     *
     * Used for: no search results, an empty category, a post with no comments,
     * and an empty moderation queue. Always offers a way out via the slot.
     */
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-lg border border-dashed border-ink-300 px-6 py-16 text-center dark:border-ink-700']) }}>
    <div class="grid size-11 place-items-center rounded-md border border-ink-200 bg-ink-50 text-ink-400 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-500">
        <flux:icon :name="$icon" class="size-5" />
    </div>

    <p class="mt-4 text-base font-semibold tracking-tight text-ink-900 dark:text-ink-50">
        {{ $title }}
    </p>

    @if ($description)
        <p class="mt-1.5 max-w-sm text-sm leading-relaxed text-ink-500 dark:text-ink-400">
            {{ $description }}
        </p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-6">
            {{ $slot }}
        </div>
    @endif
</div>
