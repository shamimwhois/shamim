@php
    /**
     * The seller role's sidenav footer.
     *
     * Composes `components/app/ui/sidenav-footer.blade.php`. See
     * `components/app/user/footer.blade.php` for why the footer is a role
     * component at all.
     */
@endphp

<x-app.ui.sidenav-footer :label="__('Seller account')" />
