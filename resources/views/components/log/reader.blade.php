@props([
    'active',
    'title',
    'meta' => [],
    'back' => null,
    'backLabel' => null,
    'eyebrow' => null,
])

@php
    /**
     * A reading page inside the log's frame.
     *
     * An article, a post, an idea or a snippet is a document, and a document in
     * this product is not a card: it is a column of prose with a title above it
     * and a way back to the list it came from. This is the chrome around that
     * column — the same frame every other surface wears, with the reader's own
     * header in place of a list.
     *
     * The slot may hold a table of contents as well as the body, so a long
     * document can put its outline above the prose.
     */
    $meta = array_filter($meta);
@endphp

<x-log.frame :active="$active" {{ $attributes }}>
    <x-slot:header>
        <div class="dn-reader-head">
            @if ($back !== null)
                <a href="{{ $back }}" wire:navigate class="dn-back">
                    <span aria-hidden="true">&larr;</span>
                    {{ $backLabel ?? __('Back') }}
                </a>
            @endif

            @if ($eyebrow !== null)
                <p class="dn-entry-eyebrow" style="margin-top: 10px;">{{ $eyebrow }}</p>
            @endif

            <h1 class="dn-reader-title">{{ $title }}</h1>

            @if ($meta !== [])
                <div class="dn-reader-meta">
                    @foreach ($meta as $item)
                        <span>{{ $item }}</span>
                    @endforeach
                </div>
            @endif

            @isset($byline)
                <div class="dn-reader-meta">{{ $byline }}</div>
            @endisset
        </div>
    </x-slot:header>

    {{ $slot }}
</x-log.frame>
