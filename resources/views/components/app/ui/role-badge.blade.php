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
        'class' => 'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-2xs font-semibold '
            .match ($role->badgeColor()) {
                'blue' => 'sui-role-seller',
                'purple' => 'sui-role-admin',
                default => 'border-sui-hairline bg-sui-well text-sui-ink-secondary',
            },
    ]) }}
    title="{{ $role->description() }}"
>
    {{ $role->label() }}

    @if ($size === 'md')
        <span class="text-sui-ink-disabled" aria-hidden="true">·</span>
        <span>{{ $plan->label() }}</span>
    @endif
</span>
