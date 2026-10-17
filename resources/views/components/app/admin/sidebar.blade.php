@props([
    'variant' => 'default',
])

@php
    /**
     * The admin role's sidebar.
     *
     * Composes the shared navigation in `components/app/ui/nav.blade.php`, scoped
     * to the `admin` route prefix so the rail lists only platform-administration
     * routes — `admin.dashboard` and `admin.users` — and not the authoring,
     * selling and workspace destinations an admin also holds permissions for.
     * Those belong to the surfaces they live on; from the admin rail they are
     * clutter, and the `Dashboard` overview link would only bounce straight
     * back to the admin home via the `/dashboard` → `/admin/dashboard` redirect.
     *
     * The *items* are still not this component's business: `DashboardNavigation`
     * filters them by permission and — when scoped — by route prefix, so the
     * Administration group appears here and nowhere else, and an account that
     * lacks `AccessAdminPanel` or `ManageUsers` sees nothing at all. The menu
     * and the route guards keep reading the same declaration.
     *
     * What *is* this component's business is the seam. Platform administration
     * is the role most likely to need a thing that is not a navigation item: a
     * health indicator, a count of failed jobs, a deploy stamp. Those belong
     * here, above or below the shared list, and nowhere else.
     *
     * `variant="icons"` is the collapsed rail: the same destinations, one icon
     * per leaf, and no sections or group headers because neither survives being
     * reduced to an icon.
     */
    $label = __('Administration');
@endphp

@if ($variant === 'icons')
    <x-app.ui.nav-icons :label="$label" scope="admin" />
@else
    <x-app.ui.nav :label="$label" scope="admin" />
@endif
