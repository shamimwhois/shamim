@props([
    'variant' => 'default',
])

@php
    /**
     * The user role's sidebar.
     *
     * Composes the shared navigation in `components/app/ui/nav.blade.php`. The
     * *items* are not this component's business: `DashboardNavigation` already
     * filters them by permission, so a plain user is offered the Overview,
     * Content and Modules sections and never the Selling or Administration
     * ones — without either sidebar knowing the other exists. There is one
     * navigation declaration, read by both the menu and the route guards, and
     * that is what keeps the two from disagreeing.
     *
     * What *is* this component's business is the seam. Today every role's
     * sidebar is the same navigation under a different accessible name, because
     * the navigation data source is permission-aware and there is nothing left
     * for role chrome to add. When that stops being true — a pinned link that
     * belongs to the author workspace alone, a role-scoped announcement, a
     * workspace quick-action — it goes here, above or below the shared list,
     * and nowhere else. Adding it to the shared component would push a user-only
     * affordance into the admin's rail.
     *
     * `variant="icons"` is the collapsed rail: the same destinations, one icon
     * per leaf, and no sections or group headers because neither survives being
     * reduced to an icon.
     */
    $label = __('Author workspace');
@endphp

@if ($variant === 'icons')
    <x-app.ui.nav-icons :label="$label" />
@else
    <x-app.ui.nav :label="$label" />
@endif
