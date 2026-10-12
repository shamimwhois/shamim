@php
    /**
     * The user role's top bar — the rail's replacement below `lg`.
     *
     * Composes `components/app/ui/topbar.blade.php`, which owns the drawer
     * trigger, the wordmark and the role badge. Nothing in it is role-shaped
     * beyond which workspace the wordmark points at, and the shared component
     * already resolves that from the signed-in account's own dashboard route,
     * so all this file contributes is the name that route should be announced
     * under.
     *
     * The role's own bar would diverge here first by adding a control — a
     * workspace-specific search, a quick-create action. Below `lg` there is
     * room for exactly three things in a 56px bar, so any addition is a
     * decision to remove something else, which is why this file exists at all
     * rather than the shared component taking a `role` prop and branching.
     */
@endphp

<x-app.ui.topbar :label="__('Author dashboard')" />
