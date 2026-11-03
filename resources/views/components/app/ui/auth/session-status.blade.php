@props([
    'status',
])

@php
    /**
     * Session status banner.
     *
     * Fortify flashes a human-readable string here after actions like a
     * password reset link being sent. The prior implementation rendered it as
     * bare green text, which is easy to miss and carries no semantic weight
     * for assistive technology.
     *
     * A `role="status"` live region announces the message without stealing
     * focus — the visitor is usually mid-task when it appears.
     */
@endphp

@if ($status)
    <div role="status" {{ $attributes }}>
        <flux:callout variant="success" icon="check-circle">
            {{ $status }}
        </flux:callout>
    </div>
@endif
