@props([
    /** Ordinal for the section marker, so each page numbers its own ribcage. */
    'number',
    'label' => 'Why hire me',
    /** Accent the marker — the site's convention for a page-opening section. */
    'accent' => false,
    /**
     * array<int, array{title: string, body: string}> — the value claims.
     * Supplied by the page because the copy is prose, and prose belongs
     * next to the data it describes.
     */
    'reasons' => [],
    /** Optional closing CTA; renders a mono aside + link when present. */
    'ctaHref' => null,
    'ctaLabel' => 'Get in touch',
    /** Distinct per instance — one page should render one instance. */
    'headingId' => 'why-hire-me-heading',
])

@php
    /**
     * Why hire me — a self-contained value-proposition section.
     *
     * The pitch a hiring manager is actually reading for: numbered, concrete
     * claims in the site's hairline-grid visual language, without icons so it
     * reads as a rank ordering rather than a feature wall.
     */
@endphp

<section class="shell py-20" aria-labelledby="{{ $headingId }}">
    <x-app.ui.section-marker :number="$number" :label="$label" :accent="$accent" />

    <h2 id="{{ $headingId }}" class="mt-5 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
        What you are actually getting
    </h2>

    <p class="mt-4 max-w-2xl text-base leading-relaxed text-ink-500 dark:text-ink-400">
        Skills are a commodity; how someone runs a project is not. These are the claims this page is making, numbered so you can hold me to them.
    </p>

    <div class="mt-12 grid gap-px overflow-hidden rounded-lg border border-ink-200 bg-ink-200 sm:grid-cols-2 dark:border-ink-800 dark:bg-ink-800">
        @foreach ($reasons as $index => $reason)
            <div
                data-reveal
                style="transition-delay: {{ min($index * 60, 240) }}ms"
                class="group bg-white p-6 transition-colors hover:bg-ink-50/80 dark:bg-ink-950 dark:hover:bg-ink-900/60"
            >
                <span class="font-mono text-2xs tabular-nums text-signal-600 dark:text-signal-400">
                    {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                </span>

                <h3 class="mt-3 text-base font-semibold tracking-tight text-ink-900 transition-colors group-hover:text-signal-700 dark:text-ink-50 dark:group-hover:text-signal-400">
                    {{ $reason['title'] }}
                </h3>
                <p class="mt-2.5 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ $reason['body'] }}
                </p>
            </div>
        @endforeach
    </div>

    @if ($ctaHref)
        <p class="mt-8 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-xs text-ink-500 dark:text-ink-400">
            <span>// the quickest proof is a conversation</span>
            <a
                href="{{ $ctaHref }}"
                class="group inline-flex items-center gap-1.5 font-medium text-signal-700 transition-colors hover:text-signal-600 dark:text-signal-400 dark:hover:text-signal-300"
            >
                {{ $ctaLabel }}
                <span aria-hidden="true" class="transition-transform group-hover:translate-x-0.5">→</span>
            </a>
        </p>
    @endif
</section>
