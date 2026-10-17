<?php

/*
|--------------------------------------------------------------------------
| Expertise marquee (About, under the hero)
|--------------------------------------------------------------------------
|
| A full-bleed, horizontally scrolling band of the author's expertise
| domains — one pill per item from `config('portfolio.author.expertise')`,
| separated by signal dots, duplicated once so the CSS translate loop is
| seamless. The design is copied from mhabir.dev's capability strip; the
| content and tokens are this site's.
|
| Every pill links# to the page's Expertise section, so the band is also
| the section's navigation. Only a pill's first occurrence is focusable:
| the duplicate copy is aria-hidden and its links carry tabindex="-1", so
| keyboard users tab through the list exactly once no matter how wide the
| viewport.
|
*/

?>
@props([
    /** @var array<int, array{title: string, icon: string, ...}> $items */
    'items',
    /** Heading id the pills anchor to. */
    'anchor' => 'expertise-heading',
])

<div {{ $attributes->merge(['class' => 'about-marquee overflow-hidden border-b border-ink-200 bg-ink-50/60 dark:border-ink-800 dark:bg-ink-900/30']) }}>
    {{-- The track holds the sequence twice; translating -50% of its own
         width lands exactly at the start of the second copy, which is what
         makes a `w-max` loop seamless without measuring anything in JS. --}}
    <div class="about-marquee__track flex w-max items-center">
        @foreach ([false, true] as $duplicate)                <div class="flex items-center"@if ($duplicate) aria-hidden="true"@endif>
                @foreach ($items as $item)
                    <a
                        href="#{{ $anchor }}"
                        title="{{ $item['title'] }} — see Expertise"
                        @if ($duplicate) tabindex="-1" @endif
                        class="group flex shrink-0 items-center gap-2.5 rounded-full px-5 py-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-signal-500"
                    >
                        <span class="relative grid size-8 shrink-0 place-items-center md:size-9" aria-hidden="true">
                            {{-- Soft halo behind the icon — the marquee's one
                                 piece of glow, kept faint so the band reads
                                 as type first, color second. --}}
                            <span class="absolute size-full rounded-full bg-signal-500/20 blur-md transition-colors group-hover:bg-signal-500/40"></span>
                            <flux:icon :name="$item['icon']" class="relative size-4 text-signal-600 transition-colors dark:text-signal-400" />
                        </span>

                        <span class="whitespace-nowrap font-mono text-2xs uppercase tracking-widest text-ink-500 transition-colors group-hover:text-signal-600 dark:text-ink-400 dark:group-hover:text-signal-400">
                            {{ $item['title'] }}
                        </span>
                    </a>

                    <span class="h-1.5 w-1.5 shrink-0 self-center rounded-full bg-signal-500/40" aria-hidden="true"></span>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
