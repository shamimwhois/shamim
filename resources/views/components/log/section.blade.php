@props([
    'title',
    'count' => null,
    'href' => null,
    'linkLabel' => null,
    'description' => null,
    'anchor' => null,
])

@php
    /**
     * A titled group of entries inside the log's column.
     *
     * The module pages are lists, but some of them — the AI hub, the lab — have
     * more than one kind of thing in them. A section is how a page says "these
     * rows belong together" without leaving the log's grammar: a heading, an
     * optional count, an optional link out, and the rows themselves.
     *
     * It is a heading and a hairline, never a card. The rows below it are
     * `<x-log.entry>`, so the section is a divider, not a container.
     */
    $anchor = $anchor ?? 'dn-section-'.Illuminate\Support\Str::slug($title);
@endphp

<section class="dn-section" aria-labelledby="{{ $anchor }}">
    <div class="dn-section-head">
        <div class="dn-section-heading">
            <h2 id="{{ $anchor }}" class="dn-section-title">
                {{ $title }}
            </h2>

            @if ($description !== null)
                <p class="dn-section-description">{{ $description }}</p>
            @endif
        </div>

        @if ($count !== null)
            <span class="dn-section-count">{{ $count }}</span>
        @endif

        @isset($actions)
            <span class="dn-section-actions">{{ $actions }}</span>
        @endisset

        @if ($href !== null)
            <a href="{{ $href }}" wire:navigate class="dn-section-link">
                {{ $linkLabel ?? __('See all') }}
                <span aria-hidden="true">&rarr;</span>
            </a>
        @endif
    </div>

    @isset($filters)
        <div class="dn-section-filters">{{ $filters }}</div>
    @endisset

    {{ $slot }}
</section>
