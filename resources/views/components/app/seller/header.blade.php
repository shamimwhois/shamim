@php
    /**
     * The seller role's navbar breadcrumb.
     *
     * Composes `components/app/ui/nav-crumb.blade.php`; the only thing the
     * role contributes is the name its workspace is announced under. See
     * `components/app/user/header.blade.php` for why the breadcrumb is a
     * role component at all.
     */
@endphp

<x-app.ui.nav-crumb :label="__('Selling workspace')" />
