@php
    /**
     * The seller role's top bar — the rail's replacement below `lg`.
     *
     * Composes `components/app/ui/topbar.blade.php`, which owns the drawer
     * trigger, the wordmark and the role badge. Nothing in it is role-shaped
     * beyond which workspace the wordmark points at, and the shared component
     * already resolves that from the signed-in account's own dashboard route,
     * so all this file contributes is the name that route should be announced
     * under.
     *
     * A seller's bar is the one most likely to want a control of its own — a
     * storefront preview, a pending-orders count. It goes here, below `lg`,
     * where there is room for three things in a 56px bar and adding a fourth
     * means taking one away.
     */
@endphp

<x-app.ui.topbar :label="__('Seller dashboard')" />
