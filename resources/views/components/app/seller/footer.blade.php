@php
    /**
     * The seller role's rail footer.
     *
     * Composes `components/app/ui/rail-footer.blade.php`, which owns the rail
     * toggle, the account identity, the plan card and the shared utility links.
     * None of that is role-shaped — every account has a name, a plan, a
     * settings page and a way out — so it lives once in `ui/` rather than three
     * times over.
     *
     * The `links` slot is the seam, and it is the slot the selling workspace is
     * most likely to fill first: a payout balance, a storefront preview, a
     * review queue. Those are seller-only utilities, so they belong in this file
     * rather than in the shared footer, where an author and an admin would both
     * inherit a link they cannot use.
     */
@endphp

<x-app.ui.rail-footer :label="__('Seller account')" />
