@php
    /**
     * DevFolio pagination.
     *
     * Replaces Laravel's default Tailwind view so page controls use the same
     * monospace/numeric language as the rest of the site. Kept deliberately
     * simple: previous, a window of page numbers, next. The full window is
     * always the same width, so the control does not shift between pages.
     */
    $window = 1;
@endphp

@if ($paginator->hasPages())
    <ul class="flex items-center gap-1" role="list">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <li>
                <span
                    aria-disabled="true"
                    class="grid size-8 cursor-not-allowed place-items-center rounded-md text-ink-400"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                    </svg>
                    <span class="sr-only">Previous page</span>
                </span>
            </li>
        @else
            <li>
                <a
                    href="{{ $paginator->previousPageUrl() }}"
                    rel="prev"
                    wire:navigate
                    class="grid size-8 place-items-center rounded-md text-ink-600 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-ink"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                    </svg>
                    <span class="sr-only">Previous page</span>
                </a>
            </li>
        @endif

        {{-- Page numbers --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <li>
                    <span class="grid size-8 place-items-center text-xs text-ink-400" aria-hidden="true">
                        {{ $element }}
                    </span>
                </li>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <li>
                            <span
                                aria-current="page"
                                class="grid size-8 place-items-center rounded-md bg-sui-accent text-xs font-semibold tabular-nums text-white shadow-soft-1"
                            >
                                {{ $page }}
                                <span class="sr-only">(current page)</span>
                            </span>
                        </li>
                    @else
                        <li>
                            <a
                                href="{{ $url }}"
                                wire:navigate
                                aria-label="Go to page {{ $page }}"
                                class="grid size-8 place-items-center rounded-md text-xs font-medium tabular-nums text-ink-600 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-ink"
                            >
                                {{ $page }}
                            </a>
                        </li>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <li>
                <a
                    href="{{ $paginator->nextPageUrl() }}"
                    rel="next"
                    wire:navigate
                    class="grid size-8 place-items-center rounded-md text-ink-600 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-ink"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                    <span class="sr-only">Next page</span>
                </a>
            </li>
        @else
            <li>
                <span
                    aria-disabled="true"
                    class="grid size-8 cursor-not-allowed place-items-center rounded-md text-ink-400"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                    <span class="sr-only">Next page</span>
                </span>
            </li>
        @endif
    </ul>
@endif
