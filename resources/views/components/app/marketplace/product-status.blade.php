@props([
    'status',
    'size' => 'sm',
])

@php
    /**
     * Publication-state badge for a product listing.
     *
     * Takes a {@see \App\Enums\ProductStatus} so the label and the colour both
     * come from the enum — a new state cannot be added without deciding how it
     * reads, and no view has to keep its own `match` in step with the enum.
     */
@endphp

<flux:badge :color="$status->badgeColor()" :size="$size" {{ $attributes }}>
    {{ $status->label() }}
</flux:badge>
