@php
    /**
     * The user role's navbar breadcrumb.
     *
     * Composes `components/app/ui/nav-crumb.blade.php`, which owns the
     * workspace root link and the active section. Nothing in it is
     * role-shaped beyond which workspace the root points at, and the
     * shared component already resolves that from the signed-in account's
     * own dashboard route, so all this file contributes is the name that
     * route should be announced under.
     *
     * The role's own crumb would diverge here first by adding a control —
     * a workspace-specific quick-create action. The navbar's row is already
     * the most contested strip in the product (drawer trigger, breadcrumb,
     * search, site link, notifications, profile), which is why this file
     * exists at all rather than the shared component taking a `role` prop
     * and branching.
     */
@endphp

<x-app.ui.nav-crumb :label="__('Author workspace')" />
