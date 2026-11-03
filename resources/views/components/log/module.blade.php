@props([
    'active',
    'title',
    'meta' => null,
    'note' => null,
])

@php
    /**
     * A module page inside the log's frame.
     *
     * The blog, the community directory, the AI hub, Ideas and the Dev Lab are
     * not the log, but they belong to the same product, so they wear the same
     * frame: a sticky title bar, an optional row of filters, and a column of
     * entries. Only the entries differ, and those are `<x-log.entry>`.
     */
@endphp

<x-log.frame :active="$active" {{ $attributes }}>
    <x-slot:header>
        <div class="dn-header">
            <div class="dn-page-head">
                <h1 class="dn-page-title">{{ $title }}</h1>
                @if ($meta !== null)
                    <p class="dn-page-meta">{{ $meta }}</p>
                @endif
            </div>

            {{ $filters ?? '' }}
        </div>
    </x-slot:header>

    @if ($note !== null)
        <p class="dn-module-note">{{ $note }}</p>
    @endif

    {{ $slot }}
</x-log.frame>
