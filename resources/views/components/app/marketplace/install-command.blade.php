@props([
    'command',
    'label' => null,
])

@php
    /**
     * A copyable shell command.
     *
     * Extracted from the marketplace pages, where the same block appeared three
     * times with three slightly different copy buttons. The Alpine state is
     * scoped to this component's root, so several of these on one page do not
     * share a "copied" flag.
     *
     * `navigator.clipboard` is optional-chained: it is unavailable on insecure
     * origins, and a storefront should degrade to "select the text yourself"
     * rather than throwing on click.
     */
@endphp

<div {{ $attributes->merge(['class' => 'rounded-lg bg-ink-900 text-ink-50 dark:border dark:border-ink-800']) }}>
    <div class="flex items-start justify-between gap-3 px-4 py-3.5">
        <div class="min-w-0">
            @if ($label)
                <p class="font-mono text-2xs uppercase tracking-widest text-ink-400">{{ $label }}</p>
            @endif

            <code x-ref="command" class="mt-1 block break-all font-mono text-xs leading-relaxed text-signal-300">{{ $command }}</code>
        </div>

        <div x-data="{ copied: false }" class="shrink-0">
            <button
                type="button"
                x-on:click="navigator.clipboard?.writeText($refs.command.textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 1600); })"
                class="focus-ring inline-flex min-h-8 items-center gap-1.5 rounded px-2 font-mono text-2xs uppercase tracking-widest text-ink-300 transition hover:bg-white/10 hover:text-white"
                aria-label="{{ __('Copy command') }}"
            >
                <flux:icon name="document-duplicate" class="size-3.5" x-show="! copied" />
                <flux:icon name="check" class="size-3.5" x-show="copied" x-cloak />
                <span x-show="! copied">{{ __('Copy') }}</span>
                <span x-show="copied" x-cloak aria-live="polite">{{ __('Copied') }}</span>
            </button>
        </div>
    </div>
</div>
