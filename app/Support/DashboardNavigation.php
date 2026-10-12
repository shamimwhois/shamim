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
 * ## Structure
 *
 * Navigation is two levels deep, and no deeper:
 *
 *     section  ── a labelled band, always a non-interactive heading
 *       └─ group ── a collapsible cluster with an icon and its own items
 *            └─ item ── a link
 *
 * A section is the only thing that is guaranteed visible; a group may be
 * collapsed, and an item is a leaf. Sections exist to answer "which part of the
 * product am I in" — they are never clickable, and an empty one is dropped
 * entirely, heading included.
 *
 * Groups exist because a flat list of ten links with no hierarchy is hard to
 * scan. Where a section holds exactly one destination there is nothing to nest,
 * so the group is marked `static` and renders as a plain link with no
 * disclosure control — a chevron that expands to reveal a single child is a
 * worse experience than no chevron at all.
 *
 * ## Collapse state
 *
 * Which groups are open is a *presentation* concern, so this class does not
 * decide it. It emits a stable `key` per group and lets the view own the
 * behaviour. The one rule that does live here is `active`: a group reports
 * whether it contains the current route, so the view can force it open. A
 * collapsed group holding the page you are looking at would hide the very link
 * the sidebar exists to highlight.
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
     *     groups: array<int, array{
     *         key: string,
     *         label: string,
     *         icon: string,
     *         description: string|null,
     *         static: bool,
     *         active: bool,
     *         items: array<int, array{label: string, route: string, pattern: string, icon: string, description: string|null, badge: int}>
     *     }>
     * }>
     */
    public static function for(User $user): array
    {
        $pendingComments = self::pendingCommentCount($user);

        $sections = [];

        foreach (self::sections() as $section) {
            $groups = [];

            foreach ($section['groups'] as $group) {
                $items = [];

                foreach ($group['items'] as $item) {
                    if (! $user->hasAnyPermission(...$item['permissions'])) {
                        continue;
                    }

                    /*
                     * Only the items that declare `badge` get a count, and the
                     * badge is always an int by the time the view sees it. That
                     * keeps the view free of an `is_string($badge)` branch and of
                     * the key that carried the marker.
                     */
                    $items[] = [
                        'label' => $item['label'],
                        'route' => $item['route'],
                        'pattern' => $item['pattern'],
                        'icon' => $item['icon'],
                        'description' => $item['description'],
                        'badge' => $item['badge'] === self::BADGE_PENDING_COMMENTS ? $pendingComments : 0,
                    ];
                }

                /* A group with nothing visible is dropped, heading included. */
                if ($items === []) {
                    continue;
                }

                $groups[] = [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'icon' => $group['icon'],
                    'description' => $group['description'],
                    /*
                     * A group that filtered down to one item has nothing left to
                     * disclose, whatever the definition intended.
                     */
                    'static' => $group['static'] || count($items) === 1,
                    'active' => self::isActive($items),
                    'items' => $items,
                ];
            }

            /* A section with nothing visible is dropped, heading included. */
            if ($groups !== []) {
                $sections[] = [
                    'label' => $section['label'],
                    'groups' => $groups,
                ];
            }
        }

        return $sections;
    }

    /**
     * Whether any of the given items matches the current request.
     *
     * Resolved against the route-name *pattern* rather than a hand-maintained
     * flag, so the highlight cannot drift from the route table.
     *
     * @param  array<int, array{pattern: string}>  $items
     */
    private static function isActive(array $items): bool
    {
        foreach ($items as $item) {
            if (request()->routeIs($item['pattern'])) {
                return true;
            }
        }

        return false;
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
     *     groups: array<int, array{
     *         key: string,
     *         label: string,
     *         icon: string,
     *         description: string|null,
     *         static: bool,
     *         items: array<int, array{
     *             label: string,
     *             route: string,
     *             pattern: string,
     *             icon: string,
     *             description: string|null,
     *             permissions: array<int, AccountPermission>,
     *             badge: string|null
     *         }>
     *     }>
     * }>
     */
    private static function sections(): array
    {
        return [
            [
                'label' => __('Home'),
                'groups' => [
                    [
                        'key' => 'overview',
                        'label' => __('Overview'),
                        'icon' => 'squares-2x2',
                        'description' => __('Your writing at a glance'),
                        'static' => true,
                        'items' => [
                            [
                                'label' => __('Dashboard'),
                                'route' => 'dashboard.index',
                                'pattern' => 'dashboard.index',
                                'icon' => 'squares-2x2',
                                'description' => __('Key metrics and recent activity'),
                                'permissions' => [AccountPermission::CreatePost],
                                'badge' => null,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'label' => __('Writing'),
                'groups' => [
                    [
                        'key' => 'content',
                        'label' => __('Content'),
                        'icon' => 'document-text',
                        'description' => __('Posts, comments and taxonomy'),
                        'static' => false,
                        'items' => [
                            [
                                'label' => __('Posts'),
                                'route' => 'dashboard.posts.index',
                                'pattern' => 'dashboard.posts.*',
                                'icon' => 'document-text',
                                'description' => __('Drafts, published and archived'),
                                'permissions' => [AccountPermission::CreatePost],
                                'badge' => null,
                            ],
                            [
                                'label' => __('Comments'),
                                'route' => 'dashboard.comments',
                                'pattern' => 'dashboard.comments',
                                'icon' => 'chat-bubble-left-right',
                                'description' => __('Moderation queue'),
                                'permissions' => [AccountPermission::CreatePost],
                                'badge' => self::BADGE_PENDING_COMMENTS,
                            ],
                            [
                                'label' => __('Taxonomy'),
                                'route' => 'dashboard.taxonomy',
                                'pattern' => 'dashboard.taxonomy',
                                'icon' => 'tag',
                                'description' => __('Categories and tags'),
                                'permissions' => [AccountPermission::ManageTaxonomy],
                                'badge' => null,
                            ],
                        ],
                    ],
                ],
            ],
            /*
             * Platform modules. Each is a separate top-level URL space; none is
             * nested under `/dashboard`. Items appear only for accounts that
             * hold the module's permission, so a plain user sees the three they
             * can reach and never the Marketplace.
             */
            [
                'label' => __('Workspaces'),
                'groups' => [
                    [
                        'key' => 'modules',
                        'label' => __('Modules'),
                        'icon' => 'rectangle-group',
                        'description' => __('Product workspaces'),
                        'static' => false,
                        'items' => [
                            [
                                'label' => __('Ideas'),
                                'route' => 'ideas.index',
                                'pattern' => 'ideas.*',
                                'icon' => 'light-bulb',
                                'description' => __('Plans, tasks and projects'),
                                'permissions' => [AccountPermission::UseIdeas],
                                'badge' => null,
                            ],
                            [
                                'label' => __('Developer Lab'),
                                'route' => 'dev-lab.index',
                                'pattern' => 'dev-lab.*',
                                'icon' => 'beaker',
                                'description' => __('Experiments and prototypes'),
                                'permissions' => [AccountPermission::UseDeveloperLab],
                                'badge' => null,
                            ],
                            [
                                'label' => __('AI Hub'),
                                'route' => 'ai-hub.index',
                                'pattern' => 'ai-hub.*',
                                'icon' => 'sparkles',
                                'description' => __('Prompts, models and notes'),
                                'permissions' => [AccountPermission::UseAiHub],
                                'badge' => null,
                            ],
                            [
                                'label' => __('Marketplace'),
                                'route' => 'marketplace.index',
                                'pattern' => 'marketplace.*',
                                'icon' => 'shopping-bag',
                                'description' => __('Digital products'),
                                'permissions' => [AccountPermission::UseMarketplace],
                                'badge' => null,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'label' => __('Manage'),
                'groups' => [
                    [
                        'key' => 'selling',
                        'label' => __('Selling'),
                        'icon' => 'building-storefront',
                        'description' => __('Your storefront'),
                        'static' => true,
                        'items' => [
                            [
                                'label' => __('Seller dashboard'),
                                'route' => 'seller.dashboard',
                                'pattern' => 'seller.*',
                                'icon' => 'building-storefront',
                                'description' => __('Products and sales'),
                                'permissions' => [AccountPermission::CreateProduct],
                                'badge' => null,
                            ],
                        ],
                    ],
                    [
                        'key' => 'administration',
                        'label' => __('Administration'),
                        'icon' => 'shield-check',
                        'description' => __('Platform management'),
                        'static' => false,
                        'items' => [
                            [
                                'label' => __('Admin dashboard'),
                                'route' => 'admin.dashboard',
                                'pattern' => 'admin.dashboard',
                                'icon' => 'shield-check',
                                'description' => __('Platform health'),
                                'permissions' => [AccountPermission::AccessAdminPanel],
                                'badge' => null,
                            ],
                            [
                                'label' => __('Users'),
                                'route' => 'admin.users',
                                'pattern' => 'admin.users',
                                'icon' => 'user-group',
                                'description' => __('Accounts and roles'),
                                'permissions' => [AccountPermission::ManageUsers],
                                'badge' => null,
                            ],
                        ],
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
