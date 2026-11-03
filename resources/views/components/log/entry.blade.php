@props([
    'href',
    'title',
    'eyebrow' => null,
    'description' => null,
    'meta' => [],
    'badge' => null,
    'glyph' => null,
])

@php
    /**
     * One row in a module's list.
     *
     * The log's grammar applied to things that are not commits: a title, an
     * optional eyebrow and description, and a line of mono meta. It is a row
     * with a hairline under it, never a card — the same rule the feed keeps,
     * so moving from the log to the blog does not change the page's physics.
     */
@endphp

<a {{ $attributes->class(['dn-entry']) }} href="{{ $href }}" wire:navigate>
    @if ($glyph !== null)
        <span class="dn-entry-glyph" aria-hidden="true">{{ $glyph }}</span>
    @endif

    <span class="dn-entry-main">
        <span class="dn-entry-head">
            @if ($eyebrow !== null)
                <span class="dn-entry-eyebrow">{{ $eyebrow }}</span>
            @endif

            <span class="dn-entry-title">{{ $title }}</span>

            @if ($badge !== null)
                <span class="dn-status-chip">{{ $badge }}</span>
            @endif
        </span>

        @if ($description !== null)
            <span class="dn-entry-desc">{{ $description }}</span>
        @endif

        @if ($meta !== [])
            <span class="dn-entry-meta">
                @foreach (array_filter($meta) as $item)
                    <span>{{ $item }}</span>
                @endforeach
            </span>
        @endif
    </span>

    <span class="dn-entry-arrow" aria-hidden="true">
        <x-feed.icon name="reply" :size="14" />
    </span>
</a>
