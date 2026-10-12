@props([
    'user' => null,
    'size' => 'sm',
])

@php
    /**
     * Account role badge.
     *
     * Reads the role off the account rather than taking it as a prop, so a
     * badge can never disagree with the permission set the page was rendered
     * under. The plan name is shown alongside the role because the two are
     * linked today but will diverge once plan entitlements become per-account.
     */
    $user ??= auth()->user();
    $role = $user->accountRole();
    $plan = $user->plan();
@endphp

<span
    {{ $attributes->merge([
        'class' => 'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 font-mono text-2xs font-medium '
            .match ($role->badgeColor()) {
                'blue' => 'border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-500/40 dark:bg-blue-950/40 dark:text-blue-300',
                'purple' => 'border-purple-300 bg-purple-50 text-purple-700 dark:border-purple-500/40 dark:bg-purple-950/40 dark:text-purple-300',
                default => 'border-ink-200 bg-ink-50 text-ink-600 dark:border-ink-700 dark:bg-ink-800 dark:text-ink-300',
            },
    ]) }}
    title="{{ $role->description() }}"
>
    {{ $role->label() }}

    @if ($size === 'md')
        <span class="text-ink-300 dark:text-ink-600" aria-hidden="true">·</span>
        <span>{{ $plan->label() }}</span>
    @endif
</span>
