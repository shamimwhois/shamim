<?php

namespace App\Support;

/**
 * The settings navigation map.
 *
 * Settings is a second, smaller navigation surface inside the dashboard shell,
 * and it has the same failure mode as the main sidebar: the menu and the routes
 * can drift apart. Declaring the destinations in one place and having the route
 * file and the view both read from it makes that drift impossible rather than
 * merely unlikely.
 *
 * Deliberately simpler than {@see DashboardNavigation}: settings has three flat
 * destinations and no permission filtering — every account manages its own
 * profile, security and appearance, so there is nothing to gate. If a settings
 * screen ever becomes role-specific, it belongs here with a permission, not in
 * a second hand-maintained list in the view.
 */
final class SettingsNavigation
{
    /**
     * Every settings destination, in display order.
     *
     * @return array<int, array{label: string, description: string, route: string, pattern: string, icon: string}>
     */
    public static function items(): array
    {
        return [
            [
                'label' => __('Profile'),
                'description' => __('Your name and email address'),
                'route' => 'profile.edit',
                'pattern' => 'profile.*',
                'icon' => 'user-circle',
            ],
            [
                'label' => __('Security'),
                'description' => __('Password, two-factor and passkeys'),
                'route' => 'security.edit',
                'pattern' => 'security.*',
                'icon' => 'shield-check',
            ],
            [
                'label' => __('Appearance'),
                'description' => __('Theme and display preferences'),
                'route' => 'appearance.edit',
                'pattern' => 'appearance.*',
                'icon' => 'paint-brush',
            ],
        ];
    }
}
