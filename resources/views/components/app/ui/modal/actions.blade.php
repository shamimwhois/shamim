@props([
    'cancel' => null,
    'submit' => null,
    'variant' => 'primary',
    'cancelVariant' => 'filled',
    'danger' => false,
])

@php
    /**
     * Modal footer action row.
     *
     * Extracted because the button row is the part of a modal most likely to be
     * got subtly wrong — a missing `type="submit"`, a cancel button that does
     * not actually close the dialog, a destructive action styled as neutral.
     *
     * `cancel` defaults to a closing button, because the overwhelmingly common
     * case is "dismiss this dialog", and a modal whose escape hatch does not
     * work is worse than one with no escape hatch at all.
     *
     * `danger` is separate from `variant` so a destructive modal can have a
     * neutral secondary button alongside its red primary one without the
     * caller having to remember which of the two gets which variant.
     */
    $cancelLabel = $cancel ?? __('Cancel');
    $submitLabel = $submit ?? __('Confirm');
    $submitVariant = $danger ? 'danger' : $variant;
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col-reverse gap-2 sm:flex-row sm:justify-end']) }}>
    {{-- Slot content, when present, replaces the default cancel button. --}}
    @if ($slot->isNotEmpty())
        {{ $slot }}
    @else
        <flux:modal.close>
            <flux:button :variant="$cancelVariant" class="w-full sm:w-auto">
                {{ $cancelLabel }}
            </flux:button>
        </flux:modal.close>
    @endif
</div>
