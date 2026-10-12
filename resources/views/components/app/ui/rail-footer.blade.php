@props([
    'label' => null,
])

@php
    /**
     * The rail's pinned footer: who you are, what you are on, and how to leave.
     *
     * Four blocks, top to bottom, each one the smallest thing that answers its
     * question:
     *
     *   1. the rail toggle — the control that changes the rail's own width
     *   2. the account identity — name and email, with the role alongside
     *   3. the plan card — the account's commercial shape, as a link home
     *   4. the utility links — view site, settings, log out
     *
     * It is pinned rather than scrolling with the nav so the log-out control
     * can never be scrolled out of reach, which matters most on exactly the
     * small screens where the rail is at its narrowest.
     *
     * ## `$label`
     *
     * Names the account block for assistive technology. Each role's footer
     * passes its own so the region is not announced as a bare "Account" three
     * different ways in three different parts of the product.
     *
     * ## The `links` slot
     *
     * A role's own utility links, rendered between the shared ones and the
     * logout control. Empty today for every role, which is the point: the
     * shape exists before the need does, and adding the seller's payouts link
     * later is one line in `components/app/seller/footer.blade.php` rather than
     * a fork of this file.
     */
    $label ??= __('Account');

    $user = auth()->user();
    $home = route($user->dashboardRoute());
@endphp

{{-- Rail toggle --}}
<button
    type="button"
    @click="toggleRail()"
    :aria-expanded="rail ? 'false' : 'true'"
    class="sui-focus mb-2 hidden w-full items-center gap-2.5 rounded-md px-3 py-2 text-left text-sm font-medium text-sui-rail-ink-secondary transition duration-150 ease-soft hover:bg-white/10 hover:text-sui-rail-ink lg:flex"
    aria-label="{{ __('Collapse or expand the sidebar') }}"
    data-test="rail-toggle"
>
    <flux:icon
        name="chevron-double-left"
        class="size-4 shrink-0 transition-transform duration-200"
        ::class="rail ? 'rotate-180' : ''"
    />
    <span x-show="! rail">{{ __('Collapse') }}</span>
</button>

{{-- Account identity, with the role that governs the menu above. --}}
<div class="flex items-center gap-3 rounded-md px-2 py-2" aria-label="{{ $label }}">
    <div class="grid size-8 shrink-0 place-items-center rounded-md bg-white/10 text-xs font-semibold text-sui-rail-ink">
        {{ Str::of($user->name)->substr(0, 1)->upper() }}
    </div>
    <div class="min-w-0 flex-1" x-show="! rail">
        <p class="truncate text-sm font-medium text-sui-rail-ink">
            {{ $user->name }}
        </p>
        <p class="truncate text-xs text-sui-rail-ink-secondary">
            {{ $user->email }}
        </p>
    </div>
</div>

{{-- Plan card: the account's commercial shape. --}}
<a
    href="{{ $home }}"
    wire:navigate
    x-show="! rail"
    class="mt-1 block rounded-md border border-sui-rail-hairline bg-white/[0.04] p-3 transition duration-150 ease-soft hover:bg-white/[0.08]"
>
    <div class="flex items-center justify-between gap-2">
        <x-app.ui.role-badge size="md" />
    </div>

    <p class="mt-2 text-xs leading-normal text-sui-rail-ink-secondary">
        {{ $user->plan()->tagline() }}
    </p>
</a>

<div class="mt-1 space-y-0.5">
    <x-app.ui.utility-link :href="route('home')" icon="arrow-top-right-on-square" :label="__('View site')" />
    <x-app.ui.utility-link :href="route('profile.edit')" icon="cog-6-tooth" :label="__('Settings')" />

    {{ $links ?? null }}

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button
            type="submit"
            class="sui-focus flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-left text-sm font-medium text-sui-rail-ink-secondary transition duration-150 ease-soft hover:bg-white/10 hover:text-sui-rail-ink"
        >
            <flux:icon name="arrow-right-start-on-rectangle" class="size-4 shrink-0" />
            <span x-show="! rail">{{ __('Log out') }}</span>
        </button>
    </form>
</div>
