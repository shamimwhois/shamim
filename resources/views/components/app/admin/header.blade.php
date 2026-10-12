@php
    /**
     * The admin role's top bar — the rail's replacement below `lg`.
     *
     * Composes `components/app/ui/topbar.blade.php`, which owns the drawer
     * trigger, the wordmark and the role badge. Nothing in it is role-shaped
     * beyond which workspace the wordmark points at, and the shared component
     * already resolves that from the signed-in account's own dashboard route,
     * so all this file contributes is the name that route should be announced
     * under.
     *
     * Administration is the role most likely to want a standing control here —
     * a search across accounts, a "switch to user view" affordance. It goes in
     * this file, not the shared component, because an author's 56px bar has no
     * room for an admin's control.
     */
@endphp

<x-app.ui.topbar :label="__('Admin dashboard')" />
