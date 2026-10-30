@php
    /**
     * The interlude.
     *
     * Dropped into the stream after the fifth commit: the log is a wall of
     * other people's work, and the useful thing to say in the middle of it is
     * "here is who is doing it, and how to find the rest of them". It sits on
     * the rail like a day marker so it reads as part of the log rather than as
     * an advertisement wedged between two commits.
     */
@endphp

<div class="dn-interlude" data-test="log-interlude">
    <div class="dn-rail" aria-hidden="true">
        <span class="dn-rail-line is-down is-dashed"></span>
    </div>

    <div class="dn-interlude-body">
        <p class="dn-interlude-label">{{ __('Active in this log') }}</p>

        <div class="dn-who">
            @foreach ($authors as $author)
                {{-- A corpus handle with no account behind it has nothing to
                     open, so it prints as a row without a link. --}}
                @if ($author['profileUrl'] !== null)
                    <a
                        class="dn-who-row"
                        href="{{ $author['profileUrl'] }}"
                        wire:navigate
                    >
                        <span class="dn-avatar" style="--dn-lane: {{ $author['lane'] }};">{{ $author['initials'] }}</span>
                        <span class="dn-who-body">
                            <span class="dn-who-name">{{ $author['name'] }}</span>
                            <span class="dn-who-meta">
                                {{ '@'.$author['handle'] }} ·
                                {{ trans_choice(':count post|:count posts', $author['commits'], ['count' => $author['commits']]) }}
                            </span>
                        </span>
                    </a>
                @else
                    <span class="dn-who-row">
                        <span class="dn-avatar" style="--dn-lane: {{ $author['lane'] }};">{{ $author['initials'] }}</span>
                        <span class="dn-who-body">
                            <span class="dn-who-name">{{ $author['name'] }}</span>
                            <span class="dn-who-meta">
                                {{ '@'.$author['handle'] }} ·
                                {{ trans_choice(':count post|:count posts', $author['commits'], ['count' => $author['commits']]) }}
                            </span>
                        </span>
                    </span>
                @endif
            @endforeach
        </div>

        <a class="dn-interlude-link" href="{{ route('log.search') }}" wire:navigate>
            {{ __('Find someone else') }}
            <x-feed.icon name="chevron-down" :size="12" />
        </a>
    </div>
</div>
