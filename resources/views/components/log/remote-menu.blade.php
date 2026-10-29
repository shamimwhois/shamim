@props([
    'remoteId',
    'title',
    'pinned' => false,
    'archived' => false,
    'muted' => false,
    'direct' => true,
    'handle' => null,
    'muteMenu' => false,
    'blocking' => false,
])

@php
    /**
     * The conversation's action menu, shared by its row and its header.
     *
     * One component for both places because the reference offers the same four
     * decisions wherever you are: pin it, stash it, silence it, block it. The
     * mute step replaces the menu rather than nesting a submenu — the durations
     * are four buttons and a nested flyout inside a 260px popover is a worse
     * target than the list itself.
     *
     * Every row here calls back into the page's Livewire component; the menu is
     * a view, not a component with state of its own.
     */
    $muteAction = $muted ? "mute({$remoteId}, 'off')" : 'showMutes';
@endphp

<div
    {{ $attributes->class(['dn-pop']) }}
    role="menu"
    x-data
    x-on:click.outside="$wire.closeMenu()"
    x-on:keydown.escape.window="$wire.closeMenu()"
>
    @if ($muteMenu)
        <p class="dn-pop-label">{{ __('Mute :title', ['title' => $title]) }}</p>

        @foreach ([
            'hour' => __('For one hour'),
            'tomorrow' => __('Until tomorrow'),
            'week' => __('For one week'),
            'forever' => __('Forever'),
        ] as $duration => $label)
            <button type="button" role="menuitem" class="dn-pop-item" wire:click="mute({{ $remoteId }}, '{{ $duration }}')">
                {{ $label }}
            </button>
        @endforeach

        <button type="button" role="menuitem" class="dn-pop-item dn-pop-back" wire:click="$set('muteMenu', false)">
            <x-feed.icon name="chevron-left" :size="13" />
            {{ __('Back') }}
        </button>
    @else
        @if ($direct && $handle !== null)
            <a role="menuitem" class="dn-pop-item" href="{{ route('profile.show', ['handle' => $handle]) }}" wire:navigate>
                <x-feed.icon name="profile" :size="14" />
                {{ __('View profile') }}
            </a>
        @endif

        <button type="button" role="menuitem" class="dn-pop-item" wire:click="pinRemote({{ $remoteId }})" data-test="remote-pin-toggle">
            <x-feed.icon name="pin" :size="14" />
            {{ $pinned ? __('Unpin') : __('Pin') }}
        </button>

        <button type="button" role="menuitem" class="dn-pop-item" wire:click="archive({{ $remoteId }})" data-test="remote-archive">
            <x-feed.icon name="archive" :size="14" />
            {{ $archived ? __('Pop') : __('Stash') }}
        </button>

        <button
            type="button"
            role="menuitem"
            class="dn-pop-item"
            wire:click="{{ $muteAction }}"
            data-test="remote-mute"
        >
            <x-feed.icon :name="$muted ? 'bell' : 'bell-off'" :size="14" />
            {{ $muted ? __('Unmute') : __('Mute') }}
        </button>

        <hr class="dn-pop-rule" />

        <button type="button" role="menuitem" class="dn-pop-item is-danger" wire:click="confirmBlock({{ $remoteId }})" data-test="remote-block">
            <x-feed.icon name="ban" :size="14" />
            {{ __('Block :title', ['title' => $title]) }}
        </button>
    @endif

    @if ($blocking)
        <div class="dn-pop-confirm">
            <p class="dn-pop-confirm-text">
                {{ __('This conversation goes away for both of you, and neither of you can push to the other. They will not be told.') }}
            </p>
            <div class="dn-pop-confirm-actions">
                <button type="button" class="dn-btn dn-btn-sm dn-btn-ghost" wire:click="confirmBlock({{ $remoteId }})">{{ __('Cancel') }}</button>
                <button type="button" class="dn-btn dn-btn-sm dn-btn-destructive" wire:click="block({{ $remoteId }})" data-test="remote-block-confirm">
                    {{ __('Block') }}
                </button>
            </div>
        </div>
    @endif
</div>
