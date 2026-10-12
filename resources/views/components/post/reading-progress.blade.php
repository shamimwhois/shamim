@props([
    'target' => '[data-reading-target]',
])

@php
    /**
     * Reading progress indicator — a 2px accent rule pinned to the top of the
     * viewport, scaling from 0 to 1 as the article body passes the fold.
     *
     * Implemented in vanilla JS via a CSS custom property rather than Alpine,
     * because it runs on every scroll frame and Alpine's reactive overhead is
     * measurable there. Degrades to invisible if JS is unavailable, which is
     * the correct failure mode for a purely decorative affordance.
     */
@endphp

<div
    class="reading-progress"
    role="progressbar"
    aria-label="Reading progress"
    aria-valuemin="0"
    aria-valuemax="100"
    aria-valuenow="0"
    data-reading-progress
    data-progress-target="{{ $target }}"
    x-data
></div>
