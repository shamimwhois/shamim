@props([
    'href',
    'icon',
    'label',
])

{{--
    Sidenav utility link (View site, Settings).

    Extracted so the two share one hover treatment and one definition of the
    collapsed-sidenav behaviour: the label is hidden when the sidenav is
    narrow, which means the accessible name has to be supplied by the
    icon-only state rather than by the text node.

    The treatment lives in `sui-sidenav-item` so it can never drift from the
    navigation beside it.
--}}
<a
    href="{{ $href }}"
    wire:navigate
    {{ $attributes->merge([
        'class' => 'sui-focus sui-sidenav-item',
    ]) }}
    title="{{ $label }}"
>
    <flux:icon :name="$icon" class="size-4 shrink-0" />
    <span x-show="! collapsed" class="truncate">{{ $label }}</span>
    <span x-show="collapsed" class="sr-only">{{ $label }}</span>
</a>
