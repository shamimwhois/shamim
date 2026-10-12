@props([
    'href',
    'icon',
    'label',
])

{{--
    Sidebar utility link (View site, Settings).

    Extracted so the two share one hover treatment and one definition of the
    collapsed-rail behaviour: the label is hidden when the rail is narrow, which
    means the accessible name has to be supplied by the icon-only state rather
    than by the text node.

    The rail treatment lives in the `sui-rail-item` utility so it can never
    drift from the navigation beside it.
--}}
<a
    href="{{ $href }}"
    wire:navigate
    {{ $attributes->merge([
        'class' => 'sui-focus sui-rail-item',
    ]) }}
    title="{{ $label }}"
>
    <flux:icon :name="$icon" class="size-4 shrink-0" />
    <span x-show="! rail" class="truncate">{{ $label }}</span>
    <span x-show="rail" class="sr-only">{{ $label }}</span>
</a>
