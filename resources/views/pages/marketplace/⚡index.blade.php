<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Marketplace — placeholder.
 *
 * The route, the `marketplace:use` permission and the sidebar entry all exist
 * and resolve. Only the module itself is unbuilt.
 *
 * This is the one module with real planning already encoded: the `seller` role
 * and its selling permissions ship, and {@see \App\Enums\RolePlan} carries the
 * first genuine entitlements — `productListingLimit()` (Free 0, Seller 25,
 * Platform unlimited) and `commissionPercent()`. Payments are not integrated,
 * which is the main blocker.
 */
new #[Layout('layouts::dashboard')] #[Title('Marketplace')] class extends Component
{
    //
};
?>

<div>
    <x-module-placeholder
        :title="__('Developer Marketplace')"
        :description="__('Publish, sell and distribute digital products — free and premium — with licensing and protected downloads.')"
        icon="shopping-bag"
        :spec="'docs/Developer Marketplace & Dig.md'"
        :features="[
            __('List free and premium digital products'),
            __('Purchasing and protected downloads'),
            __('Licensing and version management'),
            __('Sales and support tracking for sellers'),
        ]"
    />
</div>
