@props([
    'variant' => 'default',
])

@php
    /**
     * The admin role's sidebar.
     *
     * Composes the shared navigation in `components/app/ui/nav.blade.php`. The
     * *items* are not this component's business: `DashboardNavigation` already
     * filters them by permission, and an admin holds every one of them, so the
     * Administration group appears here and nowhere else — without this
     * component knowing any other role exists.
     *
     * What *is* this component's business is the seam. Today every role's
     * sidebar is the same navigation under a different accessible name, because
     * the navigation data source is permission-aware and there is nothing left
     * for role chrome to add. Platform administration is the role most likely
     * to need a thing that is not a navigation item: a health indicator, a
     * count of failed jobs, a deploy stamp. Those belong here, above or below
     * the shared list, and nowhere else.
     *
     * `variant="icons"` is the collapsed rail: the same destinations, one icon
     * per leaf, and no sections or group headers because neither survives being
     * reduced to an icon.
     */
    $label = __('Administration');
@endphp

@if ($variant === 'icons')
    <x-app.ui.nav-icons :label="$label" />
@else
    <x-app.ui.nav :label="$label" />
@endif
