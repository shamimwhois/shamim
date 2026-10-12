@props([
    'paginator',
    'label' => null,
])

@php
    /**
     * Pagination wrapper.
     *
     * Renders the site's own pagination view (vendor/pagination/devfolio) so the
     * controls share the monospace numeric language used everywhere else, and
     * prepends the "showing X–Y of Z" count.
     */
@endphp

@if ($paginator->hasPages())
    <nav
        {{ $attributes->merge(['class' => 'flex flex-col items-center justify-between gap-4 border-t border-ink-200 pt-6 sm:flex-row dark:border-ink-800']) }}
        role="navigation"
        aria-label="{{ $label ?? 'Pagination' }}"
    >
        <p class="font-mono text-2xs text-ink-400 dark:text-ink-500">
            Showing
            <span class="text-ink-600 dark:text-ink-300">{{ $paginator->firstItem() }}</span>
            –
            <span class="text-ink-600 dark:text-ink-300">{{ $paginator->lastItem() }}</span>
            of
            <span class="text-ink-600 dark:text-ink-300">{{ $paginator->total() }}</span>
        </p>

        <div>
            {{ $paginator->onEachSide(1)->links('vendor.pagination.devfolio') }}
        </div>
    </nav>
@endif
