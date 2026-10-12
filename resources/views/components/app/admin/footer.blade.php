@php
    /**
     * The admin role's rail footer.
     *
     * Composes `components/app/ui/rail-footer.blade.php`, which owns the rail
     * toggle, the account identity, the plan card and the shared utility links.
     * None of that is role-shaped — every account has a name, a plan, a
     * settings page and a way out — so it lives once in `ui/` rather than three
     * times over.
     *
     * The `links` slot is the seam, and administration is the role with the
     * clearest claim on it: a system-health link, a failed-jobs count, a
     * deploy stamp are all things an admin wants pinned where logout is rather
     * than buried behind a navigation group. Adding one is a line in this file;
     * adding it to the shared footer would put it in front of every author.
     */
@endphp

<x-app.ui.rail-footer :label="__('Administrator account')" />
