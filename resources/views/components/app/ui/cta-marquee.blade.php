<?php

/*
|--------------------------------------------------------------------------
| CTA marquee (About, between Contact and the footer)
|--------------------------------------------------------------------------
|
| The band that closes the about page, copied 1:1 from mhabir.dev's
| floor marquee: an endless, drifting repetition of an outlined display
| phrase — "Let's build something useful ·" — stroked in the accent color
| at low opacity so it reads as architecture, not content.
|
| Pure decoration, exactly like the reference: the whole band is
| aria-hidden (no announcement, no tab stops) and select-none, and the
| loop is CSS-only — the track carries the phrase six times, so
| translating -50% of its own width lands precisely one full phrase-set
| later and the scroll never shows a seam or a rewind.
|
*/

?>
@props([
    /** The repeated phrase. */
    'text' => "Let's build something useful",
    /** Phrase copies in the track; six keeps -50% loops seamless on ultrawide screens. */
    'repeat' => 6,
])

<div
    {{ $attributes->merge(['class' => 'cta-marquee overflow-hidden border-t border-ink-200 py-6 select-none dark:border-ink-800']) }}
    aria-hidden="true"
>
    <div class="cta-marquee__track flex w-max">
        @for ($i = 0; $i < $repeat; $i++)
            <span class="cta-marquee__stroke whitespace-nowrap pr-8 text-4xl font-extrabold uppercase tracking-tight text-transparent md:text-6xl">
                {{ $text }}&nbsp;·&nbsp;
            </span>
        @endfor
    </div>
</div>
