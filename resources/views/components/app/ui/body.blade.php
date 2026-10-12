@props([
    'max' => 'wide',
])

@php
    /**
     * The body wrapper every dashboard page sits in.
     *
     * Exists so page padding, max width and vertical rhythm are decided once.
     * Before this, each page repeated `px-6 py-8 lg:px-10` and a couple had
     * drifted to different values, which is the kind of inconsistency nobody
     * notices individually and everybody notices collectively.
     *
     * `max` picks the reading width:
     *   full  — tables and anything that benefits from the whole viewport
     *   wide  — the default; dashboards and multi-column layouts
     *   prose — a single column of text or a form
     */
    $width = match ($max) {
        'full' => 'max-w-none',
        'prose' => 'max-w-3xl',
        default => 'max-w-7xl',
    };
@endphp

<div {{ $attributes->merge([
    'class' => 'mx-auto w-full '.$width.' space-y-8 px-5 py-8 sm:px-6 lg:px-10',
]) }}>
    {{ $slot }}
</div>
