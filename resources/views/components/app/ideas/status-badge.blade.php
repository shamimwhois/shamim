@props([
    'status',
    'size' => 'sm',
])

@php
    /**
     * Module status badge.
     *
     * A status is a word, never a coloured dot. Each enum owns its own
     * `badgeColor()` mapping, so the workspace, the public card and the admin
     * console all say "Under review" in the same shade, and a re-tiered workflow is
     * one edit in the enum rather than a hunt through Blade. The component takes an
     * `IdeaStatus`, `PlanStatus` or `TaskStatus` for exactly that reason: there is
     * nothing idea-specific left to do here.
     */
@endphp

<flux:badge :color="$status->badgeColor()" :size="$size" {{ $attributes }}>
    {{ $status->label() }}
</flux:badge>
