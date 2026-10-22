@props([
    'label' => null,
])

@php
    /**
     * The sidenav's pinned footer: who you are, and how to leave.
     *
     * Two blocks, top to bottom, each one the smallest thing that answers its
     * question:
     *
     *   1. the account identity — name and email
     *   2. the utility links — view site, settings, log out
     *
     * The collapse toggle used to be a third block here; it moved up to the
     * shell's brand row (`components/app/ui/dashboard-shell.blade.php`) because
     * it is shell machinery, not account chrome, and it needs to stay visible
     * at every rail width — including the icon-only one this footer sits
     * under.
     *
     * There used to be a fourth block between 2 and 3: the plan card, the
     * account's commercial shape as a link home. It was removed because the
     * sidebar already links home through the wordmark, and the role/plan the
     * card repeated is one profile-menu away in the top bar. The plan itself
     * is untouched — it still drives the limits and commission rates read
     * through the account, and the seller's own dashboard still shows it.
     *
     * It is pinned rather than scrolling with the nav so the log-out control
     * can never be scrolled out of reach, which matters most on exactly the
     * small screens where the sidenav is at its narrowest.
     *
     * ## Light, where the sidenav was dark
     *
     * The floating white sidenav inverts the old trade-off: there is no dark
     * surface left in the light theme, so this footer now speaks the ordinary
     * ink scale and the accent.
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
@endphp

{{-- Account identity. --}}
<div class="flex items-center gap-3 rounded-md px-2.5 py-2" aria-label="{{ $label }}">
    <div class="grid size-8 shrink-0 place-items-center rounded-md bg-sui-accent-soft text-xs font-semibold text-sui-accent-ink">
        {{ Str::of($user->name)->substr(0, 1)->upper() }}
    </div>
    <div class="min-w-0 flex-1" x-show="! collapsed">
        <p class="truncate text-sm font-medium text-ink-900">
            {{ $user->name }}
        </p>
        <p class="truncate text-xs text-ink-500">
            {{ $user->email }}
        </p>
    </div>
</div>

<div class="mt-1 space-y-0.5">
    <x-app.ui.utility-link :href="route('home')" icon="arrow-top-right-on-square" :label="__('View site')" />
    <x-app.ui.utility-link :href="route('purchases.index')" icon="shopping-bag" :label="__('Purchases')" />
    <x-app.ui.utility-link :href="route('profile.edit')" icon="cog-6-tooth" :label="__('Settings')" />

    {{ $links ?? null }}

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button
            type="submit"
            class="sui-focus flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm font-medium text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-ink"
        >
            <flux:icon name="arrow-right-start-on-rectangle" class="size-4 shrink-0" />
            <span x-show="! collapsed">{{ __('Log out') }}</span>
            <span x-show="collapsed" class="sr-only">{{ __('Log out') }}</span>
        </button>
    </form>
</div>
