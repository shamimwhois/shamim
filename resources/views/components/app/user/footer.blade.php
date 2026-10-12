@php
    /**
     * The user role's rail footer.
     *
     * Composes `components/app/ui/rail-footer.blade.php`, which owns the rail
     * toggle, the account identity, the plan card and the shared utility links.
     * None of that is role-shaped — every account has a name, a plan, a
     * settings page and a way out — so it lives once in `ui/` rather than three
     * times over.
     *
     * The `links` slot is the seam. It renders between the shared utility links
     * and the logout control, and it is empty here on purpose: the shape exists
     * before the need does. When the author workspace grows a utility that is
     * not "view site" or "settings" — a help page, a feedback form, an export
     * link — it is one line in this file, not a fork of the shared footer into
     * a second near-identical implementation.
     */
@endphp

<x-app.ui.rail-footer :label="__('Author account')" />
