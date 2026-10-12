@props([
    'items' => [],
    'title' => 'On this page',
    'id' => 'toc',
])

@php
    /**
     * Table of contents.
     *
     * `items` is the output of MarkdownRenderer::outline(): a list of
     * `{level, id, text}` entries built from the rendered h2/h3 elements. The
     * active entry is highlighted by an IntersectionObserver in app.js, which
     * writes `data-active` onto the matching link.
     *
     * Only rendered when there are at least two headings — a TOC for a single
     * section is noise.
     *
     * `id` must be unique per instance: the page renders this twice (a sticky
     * sidebar on desktop, a collapsed disclosure on mobile) and duplicate ids
     * would be invalid HTML and break the aria-labelledby association.
     */
    $headingId = $id.'-heading';
@endphp

@if (count($items) >= 2)
    <nav {{ $attributes->merge(['class' => '']) }} aria-labelledby="{{ $headingId }}" data-toc>
        <h2 id="{{ $headingId }}" class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
            {{ $title }}
        </h2>

        <ul class="mt-4 border-l border-ink-200 dark:border-ink-800">
            @foreach ($items as $item)
                <li>
                    <a
                        href="#{{ $item['id'] }}"
                        class="toc-link"
                        data-depth="{{ $item['level'] }}"
                        data-toc-target="{{ $item['id'] }}"
                    >
                        {{ $item['text'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
