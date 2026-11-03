@props([
    'items' => [],
    'title' => null,
    'id' => 'dn-toc',
])

@php
    /**
     * The table of contents, in the log's grammar.
     *
     * `items` is the output of `MarkdownRenderer::outline()`: a list of
     * `{level, id, text}` entries built from the rendered headings. The site's
     * own TOC component paints with the `ink-*` scale and cannot be reused here
     * for the same reason the prose cannot: that scale is not inverted by the
     * log's dark theme.
     *
     * The active entry is highlighted by the same IntersectionObserver that
     * drives the site's TOC — it writes `data-active` onto the matching link,
     * and this stylesheet reads it.
     */
    $headingId = $id.'-heading';
@endphp

@if (count($items) >= 2)
    <nav {{ $attributes->merge(['class' => 'dn-toc']) }} aria-labelledby="{{ $headingId }}" data-toc>
        <h2 id="{{ $headingId }}" class="dn-toc-title">
            {{ $title ?? __('On this page') }}
        </h2>

        <ul class="dn-toc-list">
            @foreach ($items as $item)
                <li>
                    <a
                        href="#{{ $item['id'] }}"
                        class="dn-toc-link"
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
