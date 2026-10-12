<?php

namespace App\Support;

use App\Enums\AccountPermission;
use App\Models\Comment;
use App\Models\User;

/**
 * The dashboard navigation map.
 *
 * A single declarative definition, filtered by permission at render time. This
 * replaces the previous hardcoded array in `components/dashboard/nav.blade.php`
 * for one reason: the navigation and the access rules must not be able to
 * disagree. When each item declares the permission it requires, an item can
 * never be visible to an account that would be refused by the route.
 *
 * Items are grouped into sections. A section whose items are all filtered out
 * is dropped entirely, so a plain user never sees an empty "Administration"
 * heading with nothing under it.
 */
final class DashboardNavigation
{
    /**
     * Marker meaning "this item's badge is the pending-comment count".
     *
     * The definition declares *which* badge it wants; {@see self::for()} fills
     * in the number. A definition therefore carries no live data and can be
     * reasoned about statically.
     */
    private const BADGE_PENDING_COMMENTS = 'pending-comments';

    /**
     * Build the navigation for the given account.
     *
     * @return array<int, array{
     *     label: string,
     *     items: array<int, array{label: string, route: string, pattern: string, icon: string, badge: int}>
     * }>
     */
    public static function for(User $user): array
    {
        $pendingComments = self::pendingCommentCount($user);

        $sections = [];

        foreach (self::sections() as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                if (! $user->hasAnyPermission(...$item['permissions'])) {
                    continue;
                }

                /*
                 * Only the items that declare `badge` get a count, and the
                 * badge is always an int by the time the view sees it. That
                 * keeps the view free of a `is_string($badge)` branch and of
                 * the key that carried the marker.
                 */
                $items[] = [
                    'label' => $item['label'],
                    'route' => $item['route'],
                    'pattern' => $item['pattern'],
                    'icon' => $item['icon'],
                    'badge' => $item['badge'] === self::BADGE_PENDING_COMMENTS ? $pendingComments : 0,
                ];
            }

            /* A section with nothing visible is dropped, heading included. */
            if ($items !== []) {
                $sections[] = [
                    'label' => $section['label'],
                    'items' => $items,
                ];
            }
        }

        return $sections;
    }

    /**
     * The unfiltered navigation definition.
     *
     * Items without a badge carry `badge => null` explicitly rather than
     * omitting the key, so every item in the definition has the same shape and
     * the analyser does not have to reason about an optional offset.
     *
     * @return array<int, array{
     *     label: string,
     *     items: array<int, array{
     *         label: string,
     *         route: string,
     *         pattern: string,
     *         icon: string,
     *         permissions: array<int, AccountPermission>,
     *         badge: string|null
     *     }>
     * }>
     */
    private static function sections(): array
    {
        return [
            [
                'label' => __('Overview'),
                'items' => [
                    [
                        'label' => __('Dashboard'),
                        'route' => 'dashboard.index',
                        'pattern' => 'dashboard.index',
                        'icon' => 'squares-2x2',
                        'permissions' => [AccountPermission::CreatePost],
                        'badge' => null,
                    ],
                ],
            ],
            [
                'label' => __('Content'),
                'items' => [
                    [
                        'label' => __('Posts'),
                        'route' => 'dashboard.posts.index',
                        'pattern' => 'dashboard.posts.*',
                        'icon' => 'document-text',
                        'permissions' => [AccountPermission::CreatePost],
                        'badge' => null,
                    ],
                    [
                        'label' => __('Comments'),
                        'route' => 'dashboard.comments',
                        'pattern' => 'dashboard.comments',
                        'icon' => 'chat-bubble-left-right',
                        'permissions' => [AccountPermission::CreatePost],
                        'badge' => self::BADGE_PENDING_COMMENTS,
                    ],
                    [
                        'label' => __('Taxonomy'),
                        'route' => 'dashboard.taxonomy',
                        'pattern' => 'dashboard.taxonomy',
                        'icon' => 'tag',
                        'permissions' => [AccountPermission::ManageTaxonomy],
                        'badge' => null,
                    ],
                ],
            ],
            [
                'label' => __('Selling'),
                'items' => [
                    [
                        'label' => __('Seller home'),
                        'route' => 'dashboard.seller.index',
                        'pattern' => 'dashboard.seller.*',
                        'icon' => 'building-storefront',
                        'permissions' => [AccountPermission::CreateProduct],
                        'badge' => null,
                    ],
                ],
            ],
            [
                'label' => __('Administration'),
                'items' => [
                    [
                        'label' => __('Admin home'),
                        'route' => 'dashboard.admin.index',
                        'pattern' => 'dashboard.admin.index',
                        'icon' => 'shield-check',
                        'permissions' => [AccountPermission::AccessAdminPanel],
                        'badge' => null,
                    ],
                    [
                        'label' => __('Users'),
                        'route' => 'dashboard.admin.users',
                        'pattern' => 'dashboard.admin.users',
                        'icon' => 'users',
                        'permissions' => [AccountPermission::ManageUsers],
                        'badge' => null,
                    ],
                ],
            ],
        ];
    }

    /**
     * Count the comments awaiting moderation.
     *
     * Scoped to the account's own posts unless the account may moderate
     * everything, so the badge means "work waiting for *you*" and not "work
     * waiting for somebody".
     */
    private static function pendingCommentCount(User $user): int
    {
        $query = Comment::query()->pending();

        if (! $user->hasPermission(AccountPermission::ModerateComments)) {
            $query->whereHas(
                'post',
                fn ($postQuery) => $postQuery->where('user_id', $user->id),
            );
        }

        return $query->count();
    }
}
