<?php

use App\Enums\AccountPermission;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Support\DashboardNavigation;
use App\Support\SettingsNavigation;

/**
 * The dashboard and settings navigation maps.
 *
 * These are structural tests, not render tests: they assert the shape the
 * views rely on, so a change to the definition fails here rather than showing
 * up as a malformed sidebar in the browser.
 */
/**
 * Flatten the section → group → item tree to a list of item arrays.
 *
 * @param  array<int, array{groups: array<int, array{items: array<int, array<string, mixed>>}>}>  $sections
 * @return array<int, array<string, mixed>>
 */
$flatten = function (array $sections): array {
    $items = [];

    foreach ($sections as $section) {
        foreach ($section['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $items[] = $item;
            }
        }
    }

    return $items;
};

/**
 * Find a group by its stable key.
 *
 * @param  array<int, array{groups: array<int, array<string, mixed>>}>  $sections
 */
$group = function (array $sections, string $key): ?array {
    foreach ($sections as $section) {
        foreach ($section['groups'] as $candidate) {
            if ($candidate['key'] === $key) {
                return $candidate;
            }
        }
    }

    return null;
};

test('the navigation is three levels: sections, groups and items', function () {
    $sections = DashboardNavigation::for(User::factory()->create());

    expect($sections)->not->toBeEmpty();

    foreach ($sections as $section) {
        expect($section)->toHaveKeys(['label', 'groups'])
            ->and($section['groups'])->not->toBeEmpty();

        foreach ($section['groups'] as $group) {
            expect($group)->toHaveKeys(['key', 'label', 'icon', 'description', 'static', 'active', 'items'])
                ->and($group['items'])->not->toBeEmpty();

            foreach ($group['items'] as $item) {
                expect($item)->toHaveKeys(['label', 'route', 'pattern', 'icon', 'description', 'badge']);
            }
        }
    }
});

test('every item points at a route that actually exists', function () {
    $sections = DashboardNavigation::for(User::factory()->admin()->create());

    $routes = [];

    foreach ($sections as $section) {
        foreach ($section['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $routes[] = $item['route'];
            }
        }
    }

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        expect(Route::has($route))->toBeTrue("Route [{$route}] is declared in the navigation but not registered.");
    }
});

test('group keys are unique and stable', function () {
    $sections = DashboardNavigation::for(User::factory()->admin()->create());

    $keys = [];

    foreach ($sections as $section) {
        foreach ($section['groups'] as $group) {
            $keys[] = $group['key'];
        }
    }

    expect($keys)->toBe(array_unique($keys))
        ->and($keys)->toContain('overview', 'content', 'modules', 'selling', 'administration');
});

test('a plain user sees only the groups they hold permissions for', function () use ($group) {
    $sections = DashboardNavigation::for(User::factory()->create());

    // Baselines every account has.
    expect($group($sections, 'overview'))->not->toBeNull()
        ->and($group($sections, 'modules'))->not->toBeNull();

    // The writing screens live under `/admin`, so a plain account loses the
    // whole group, not just some of its items.
    expect($group($sections, 'content'))->toBeNull();

    // Role-gated.
    expect($group($sections, 'selling'))->toBeNull()
        ->and($group($sections, 'administration'))->toBeNull();
});

test('a seller additionally sees the selling group but not administration', function () use ($group) {
    $sections = DashboardNavigation::for(User::factory()->seller()->create());

    expect($group($sections, 'selling'))->not->toBeNull()
        ->and($group($sections, 'content'))->toBeNull()
        ->and($group($sections, 'administration'))->toBeNull();
});

test('an admin sees every group', function () use ($group) {
    $sections = DashboardNavigation::for(User::factory()->admin()->create());

    foreach (['overview', 'content', 'modules', 'selling', 'administration'] as $key) {
        expect($group($sections, $key))->not->toBeNull("Admin is missing the [{$key}] group.");
    }
});

test('the admin rail scoped to admin routes shows only administration destinations', function () use ($group, $flatten) {
    $sections = DashboardNavigation::for(User::factory()->admin()->create(), 'admin');

    // The Writing band survives the scope now that its screens are `/admin`
    // routes, and sits ahead of the "Manage" band.
    expect(array_column($sections, 'label'))->toContain('Writing', 'Manage');

    $admin = $group($sections, 'administration');
    expect($admin)->not->toBeNull();

    $routes = array_column($flatten($sections), 'route');

    expect($routes)->toBe([
        'admin.posts.index', 'admin.comments', 'admin.taxonomy',
        'admin.dashboard', 'admin.users', 'admin.marketplace', 'admin.payment', 'admin.orders', 'admin.payments', 'admin.about', 'admin.ai-hub',
        'admin.ideas', 'admin.dev-lab',
    ])
        ->and($routes)->not->toContain('dashboard.index')
        ->and($routes)->not->toContain('seller.dashboard')
        ->and($routes)->not->toContain('ideas.index')
        ->and($routes)->not->toContain('marketplace.index');
});

test('a scoped admin rail is empty for an account without admin permissions', function () {
    $sections = DashboardNavigation::for(User::factory()->create(), 'admin');

    expect($sections)->toBe([]);
});

test('an empty section is dropped along with its heading', function () use ($group) {
    $sections = DashboardNavigation::for(User::factory()->create());

    $labels = array_column($sections, 'label');

    // A plain user can reach nothing under "Manage" or under "Writing" — the
    // authoring screens are `/admin` pages — so both bands disappear entirely
    // rather than rendering a heading over empty space.
    expect($labels)->not->toContain('Manage')
        ->not->toContain('Writing')
        ->and($group($sections, 'selling'))->toBeNull();
});

test('the writing screens are hidden from every account without admin access', function () use ($flatten) {
    $plainRoutes = array_column($flatten(DashboardNavigation::for(User::factory()->create())), 'route');
    $sellerRoutes = array_column($flatten(DashboardNavigation::for(User::factory()->seller()->create())), 'route');
    $adminRoutes = array_column($flatten(DashboardNavigation::for(User::factory()->admin()->create())), 'route');

    foreach (['admin.posts.index', 'admin.comments', 'admin.taxonomy'] as $route) {
        expect($plainRoutes)->not->toContain($route)
            ->and($sellerRoutes)->not->toContain($route)
            ->and($adminRoutes)->toContain($route);
    }
});

test('the module consoles stay out of a rail that cannot open them', function () use ($flatten) {
    $plain = array_column($flatten(DashboardNavigation::for(User::factory()->create())), 'route');
    $seller = array_column($flatten(DashboardNavigation::for(User::factory()->seller()->create())), 'route');
    $admin = array_column($flatten(DashboardNavigation::for(User::factory()->admin()->create())), 'route');

    // Both consoles read across accounts, so they belong to the admin rail and
    // nowhere else. Filtering by permission is what keeps them out of a user
    // sidebar, which is rendered without a scope.
    foreach (['admin.ideas', 'admin.dev-lab'] as $console) {
        expect($plain)->not->toContain($console)
            ->and($seller)->not->toContain($console)
            ->and($admin)->toContain($console);
    }
});

test('marketplace is restricted to accounts that may sell', function () use ($flatten) {
    $plain = array_column($flatten(DashboardNavigation::for(User::factory()->create())), 'route');
    $seller = array_column($flatten(DashboardNavigation::for(User::factory()->seller()->create())), 'route');

    expect($plain)->not->toContain('marketplace.index')
        ->and($seller)->toContain('marketplace.index');
});

test('the modules group matches the account permissions exactly', function () use ($group) {
    $user = User::factory()->create();
    $modules = $group(DashboardNavigation::for($user), 'modules');

    $expected = [
        'ideas.dashboard' => AccountPermission::UseIdeas,
        'dev-lab.dashboard' => AccountPermission::UseDeveloperLab,
        'ai-hub.index' => AccountPermission::UseAiHub,
        'marketplace.index' => AccountPermission::UseMarketplace,
    ];

    $visible = array_column($modules['items'], 'route');

    foreach ($expected as $route => $permission) {
        if ($user->hasPermission($permission)) {
            expect($visible)->toContain($route);
        } else {
            expect($visible)->not->toContain($route);
        }
    }
});

test('a group holding exactly one visible item becomes static', function () use ($group) {
    // Administration is the clearest case: a seller cannot see it at all, so
    // for the roles that can, it still holds two items. Force the one-item
    // case by checking a group the definition marks static outright.
    $sections = DashboardNavigation::for(User::factory()->create());

    $overview = $group($sections, 'overview');

    expect($overview['static'])->toBeTrue()
        ->and($overview['items'])->toHaveCount(1);

    // Content is non-static for the account that can see all three of its
    // items; the whole group is gone for anybody without `admin:access`.
    $content = $group(DashboardNavigation::for(User::factory()->admin()->create()), 'content');

    expect($content['static'])->toBeFalse()
        ->and(count($content['items']))->toBeGreaterThan(1);
});

test('a group containing the current route reports itself active', function () use ($group) {
    $user = User::factory()->admin()->create();

    // No request route bound yet — nothing should claim to be active.
    $before = DashboardNavigation::for($user);
    expect($group($before, 'content')['active'])->toBeFalse();

    $this->actingAs($user)->get(route('admin.posts.index'))->assertOk();

    $after = DashboardNavigation::for($user);

    expect($group($after, 'content')['active'])->toBeTrue()
        ->and($group($after, 'modules')['active'])->toBeFalse()
        ->and($group($after, 'overview')['active'])->toBeFalse();
});

test('the active flag follows route patterns rather than exact names', function () use ($group) {
    $user = User::factory()->admin()->create();

    // `ideas.*` should light the modules group for a nested route too.
    $this->actingAs($user)->get(route('ideas.index'))->assertOk();

    expect($group(DashboardNavigation::for($user), 'modules')['active'])->toBeTrue();
});

test('only the comments item carries a badge, and it counts pending comments', function () use ($flatten) {
    $user = User::factory()->admin()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    Comment::factory()->count(3)->create(['post_id' => $post->id, 'status' => 'pending']);
    Comment::factory()->create(['post_id' => $post->id, 'status' => 'approved']);

    $items = collect($flatten(DashboardNavigation::for($user)))->keyBy('route');

    expect($items['admin.comments']['badge'])->toBe(3)
        ->and($items['admin.posts.index']['badge'])->toBe(0)
        ->and($items['dashboard.index']['badge'])->toBe(0);
});

test('an account without admin access gets no content group and therefore no badge', function () use ($flatten) {
    /*
     * The queue itself is still author-scoped — `pages::admin.comments` lists
     * only the signed-in account's posts — but the badge is a sidebar
     * affordance, and the band it belongs to no longer renders for accounts
     * that cannot open the screen it links to.
     */
    $author = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $author->id]);

    Comment::factory()->count(2)->create(['post_id' => $post->id, 'status' => 'pending']);

    $routes = array_column($flatten(DashboardNavigation::for($author)), 'route');

    expect($routes)->not->toContain('admin.comments');
});

test('a moderator sees the platform-wide pending count', function () use ($flatten) {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    $mine = Post::factory()->create(['user_id' => $admin->id]);
    $theirs = Post::factory()->create(['user_id' => $other->id]);

    Comment::factory()->count(2)->create(['post_id' => $mine->id, 'status' => 'pending']);
    Comment::factory()->count(5)->create(['post_id' => $theirs->id, 'status' => 'pending']);

    $badge = collect($flatten(DashboardNavigation::for($admin)))
        ->keyBy('route')['admin.comments']['badge'];

    expect($badge)->toBe(7);
});

/*
|--------------------------------------------------------------------------
| Settings navigation
|--------------------------------------------------------------------------
*/

test('the settings map lists profile, security and appearance', function () {
    $items = SettingsNavigation::items();

    expect(array_column($items, 'route'))->toBe(['profile.edit', 'security.edit', 'appearance.edit']);
});

test('every settings destination resolves to a registered route', function () {
    foreach (SettingsNavigation::items() as $item) {
        expect($item)->toHaveKeys(['label', 'description', 'route', 'pattern', 'icon'])
            ->and(Route::has($item['route']))->toBeTrue("Settings route [{$item['route']}] is not registered.");
    }
});

test('the settings map is not permission filtered', function () {
    // Every account manages its own profile and security, so the map is a
    // constant — it takes no user and cannot vary by role.
    $method = new ReflectionMethod(SettingsNavigation::class, 'items');

    expect($method->getNumberOfParameters())->toBe(0);

    $items = SettingsNavigation::items();

    foreach ($items as $item) {
        expect($item)->not->toHaveKey('permissions');
    }
});
