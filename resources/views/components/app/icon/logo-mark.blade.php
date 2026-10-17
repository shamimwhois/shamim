@props([
    'size' => 'md',
])

@php
    /**
     * The `>_` wordmark mark.
     *
     * The terminal glyph that opens the public site, closes it in the footer's
     * shadow, and labels every dashboard rail. It was previously written out
     * inline in four places — `components/app/home/header`, three times in the
     * dashboard shell, and once in the About page's profile card — which is how
     * the four drifted apart: `rounded-xs` here, `rounded-md` there, `text-lg`
     * in one and `text-2xs` in the other, with no record of which was intended.
     *
     * This component is the single definition. It owns the glyph and the box
     * that holds it; it deliberately owns *nothing else*. The fill, the ink and
     * the border are the palette's business, not the glyph's, so they arrive
     * through `$attributes` and differ by surface — the public site inverts it
     * on `bg-ink-900`, the dashboard rail sets it on `bg-white`, the mobile bar
     * sets it on `bg-sui-accent`. That is why this is a prop-taking component and
     * not three theme variants: one component with three palettes is the same
     * information, stated once.
     *
     * The glyph is `aria-hidden` because it repeats the wordmark beside it. A
     * reader gets the name from the text, never from the punctuation.
     */
    $sizes = [
        'sm' => 'size-6 rounded-md text-2xs',
        'md' => 'size-7 rounded-md text-xs',
        'lg' => 'size-14 rounded-md text-lg',
    ];

    $box = $sizes[$size] ?? $sizes['md'];
@endphp

<span
    aria-hidden="true"
    {{ $attributes->class(['grid shrink-0 place-items-center font-bold', $box]) }}
>&gt;_</span>
