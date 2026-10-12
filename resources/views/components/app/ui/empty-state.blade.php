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
     *
     * Shared between the dashboard and the public site, so it is the one
     * component that carries `dark:` variants on the *ink* tokens: the
     * `sui-*` surface tokens already resolve per theme (see the `.dark` block
     * in app.css), but the ink scale is still flipped by explicit utility on
     * the public side and the component has to work in both places.
     */
@endphp

<div {{ $attributes->merge(['class' => 'sui-empty rounded-xl border border-dashed border-sui-border bg-sui-well']) }}>
    <div class="grid size-11 place-items-center rounded-md border border-sui-hairline bg-sui-surface text-ink-400 dark:text-ink-500">
        <flux:icon :name="$icon" class="size-5" />
    </div>

    <p class="mt-4 text-base font-semibold tracking-tight text-ink-900 dark:text-ink-50">
        {{ $title }}
    </p>

    @if ($description)
        <p class="mt-1.5 max-w-sm text-sm leading-normal text-ink-500 dark:text-ink-400">
            {{ $description }}
        </p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-6">
            {{ $slot }}
        </div>
    @endif
</div>
