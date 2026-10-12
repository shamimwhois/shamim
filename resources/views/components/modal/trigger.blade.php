@props([
    'name',
])

@php
    /**
     * Modal trigger.
     *
     * A thin pass-through to Flux's trigger, wrapped so that the modal *name*
     * is written in exactly one place per modal. The failure mode this
     * prevents is a renamed modal that is opened by a stale trigger: the
     * markup still renders, the button still looks right, and nothing happens
     * when it is clicked.
     *
     * Keeping the name as a required prop on both the modal and its trigger
     * means a rename is a two-line change in one file rather than a grep
     * across the view tree.
     */
@endphp

<flux:modal.trigger :name="$name" {{ $attributes }}>
    {{ $slot }}
</flux:modal.trigger>
