@props([
    'variant' => 'default',
])

@php
    /**
     * The seller role's sidebar.
     *
     * Composes the shared navigation in `components/app/ui/nav.blade.php`. The
     * *items* are not this component's business: `DashboardNavigation` already
     * filters them by permission, so a seller sees everything an author does
     * plus the Selling group and the Marketplace module, and an author sees
     * none of that — without either sidebar knowing the other exists.
     *
     * What *is* this component's business is the seam. Today every role's
     * sidebar is the same navigation under a different accessible name, because
     * the navigation data source is permission-aware and there is nothing left
     * for role chrome to add. The selling workspace is the role most likely to
     * outgrow that: a payout balance, a storefront status, a review queue and a
     * support inbox all want a permanent position, and none of them is a route
     * in the shared map. They belong here, not in the admin's rail.
     *
     * `variant="icons"` is the collapsed rail: the same destinations, one icon
     * per leaf, and no sections or group headers because neither survives being
     * reduced to an icon.
     */
    $label = __('Selling workspace');
@endphp

@if ($variant === 'icons')
    <x-app.ui.nav-icons :label="$label" />
@else
    <x-app.ui.nav :label="$label" />
@endif
