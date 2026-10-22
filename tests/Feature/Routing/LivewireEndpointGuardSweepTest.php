<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\ExpectationFailedException;

/*
|--------------------------------------------------------------------------
| The guards that protect `/livewire/update`
|--------------------------------------------------------------------------
|
| `RouteGuardSweepTest` proves the route stack is complete. It does not prove
| the page is safe, and the difference is the whole reason this file exists: a
| Livewire request is a POST to `/livewire/update` carrying a component name,
| not a GET to the page's own URI, so it never passes through `auth`, `verified`
| or `permission:` at all. The middleware decides whether somebody may *look* at
| the screen. Only the component decides whether they may *mount* it.
|
| So each guard here is asserted against the component, and each refusal is
| paired with a fact that a route-only test cannot express: the account is
| signed in, verified, and holding no permission the screen requires. That is
| precisely the account that would have got through on the endpoint.
|
| The structural test at the bottom is the forward-looking half. It reads the
| admin component files themselves, so a thirteenth console added tomorrow is
| refused by a test before it is ever written by a human.
|
*/

/**
 * The permission strings a route's middleware names.
 *
 * @return array<int, string>
 */
function endpointPermissionValues(RouteDefinition $route): array
{
    return collect($route->gatherMiddleware())
        ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'permission:'))
        ->flatMap(fn (string $middleware): array => explode(',', substr($middleware, strlen('permission:'))))
        ->all();
}

/**
 * Every Livewire page route behind a permission, that needs no bound model to
 * reach. The bound ones are covered by their module's own console test, which
 * can fabricate the row.
 *
 * @return array<int, array{component: string, uri: string, permissions: array<int, string>}>
 */
function guardedLivewirePages(): array
{
    return collect(RouteFacade::getRoutes())
        ->filter(fn (RouteDefinition $route): bool => ($route->getAction()['livewire_component'] ?? null) !== null)
        ->filter(fn (RouteDefinition $route): bool => endpointPermissionValues($route) !== [])
        ->reject(fn (RouteDefinition $route): bool => str_contains($route->uri(), '{'))
        ->map(fn (RouteDefinition $route): array => [
            'component' => $route->getAction()['livewire_component'],
            'uri' => '/'.ltrim($route->uri(), '/'),
            'permissions' => endpointPermissionValues($route),
        ])
        ->values()
        ->all();
}

/**
 * A role that holds none of the listed permissions, or null when every role
 * holds at least one of them.
 */
function roleHoldingNoneOf(array $permissionValues): ?AccountRole
{
    $required = array_values(array_filter(array_map(
        fn (string $value): ?AccountPermission => AccountPermission::tryFrom($value),
        $permissionValues,
    )));

    foreach (AccountRole::cases() as $role) {
        if ($required !== [] && ! $role->hasAnyPermission(...$required)) {
            return $role;
        }
    }

    return null;
}

test('every permission-gated livewire page refuses a verified account that lacks the permission', function (): void {
    $mounted = [];

    foreach (guardedLivewirePages() as $page) {
        $role = roleHoldingNoneOf($page['permissions']);

        if ($role === null) {
            continue;
        }

        $account = User::factory()->create(['role' => $role->value]);

        // Mounted through the component, not the route: this is the request the
        // middleware stack never sees. The failure is collected by name rather
        // than asserted inline, because `assertForbidden()` on the first
        // offender would hide the rest.
        try {
            Livewire::actingAs($account)
                ->test($page['component'])
                ->assertForbidden();

            continue;
        } catch (ExpectationFailedException) {
            $mounted[] = "{$page['component']} ({$page['uri']}) mounted for role [{$role->value}]";
        }
    }

    expect($mounted)->toBe([], "These permission-gated components have no guard of their own:\n- ".implode("\n- ", $mounted));

    // The sweep is only meaningful if it covers the consoles it was written for.
    $components = collect(guardedLivewirePages())->pluck('component')->all();

    foreach (['pages::admin.feed', 'pages::admin.dashboard', 'pages::admin.users'] as $expected) {
        expect($components)->toContain($expected);
    }
});

test('the same components still mount for an account that holds the permission', function (): void {
    $admin = User::factory()->admin()->create();
    $mounted = 0;

    foreach (guardedLivewirePages() as $page) {
        // `pages::admin.users` needs the ManageUsers permission an admin holds,
        // and every gated page here is admin-, seller- or module-scoped, so one
        // account satisfies all of them.
        Livewire::actingAs($admin)
            ->test($page['component'])
            ->assertOk();

        $mounted++;
    }

    expect($mounted)->toBeGreaterThan(0);
});

test('every permission-gated component declares its guard inside its own file', function (): void {
    /*
     * Route middleware cannot be the only defence, so the convention is that a
     * permission-gated component repeats the requirement in `boot()` (or
     * authorises in `mount()`). This test makes the convention enforceable
     * rather than a habit: a new gated screen that trusts the route alone is
     * named here by file, before anybody finds the gap by mounting it.
     */
    $bare = collect(guardedLivewirePages())
        ->map(function (array $page): ?string {
            // `pages::admin.feed` is `resources/views/pages/admin/⚡feed.blade.php`:
            // the namespace prefix is dropped, the dots become directories, and
            // the bolt is inserted before the last segment.
            $relative = str_replace('.', '/', Str::after($page['component'], '::')).'.blade.php';
            $directory = str_contains($relative, '/') ? dirname($relative).'/' : '';
            $path = base_path('resources/views/pages/'.$directory.'⚡'.basename($relative));

            if (! is_file($path)) {
                return "{$page['component']} — no component file at pages/{$directory}⚡".basename($relative);
            }

            $source = file_get_contents($path);

            $guarded = str_contains($source, 'hasPermission(AccountPermission::')
                || str_contains($source, 'Gate::authorize(')
                || str_contains($source, '$this->authorize(');

            return $guarded ? null : "{$page['component']} ({$page['uri']}) trusts the route middleware alone";
        })
        ->filter()
        ->values()
        ->all();

    expect($bare)->toBe([], "These gated components have no guard of their own:\n- ".implode("\n- ", $bare));
});

test('every admin console declares its guard inside its own file', function (): void {
    // The directory sweep is the companion of the route sweep above: it covers
    // consoles reached through a param-bound route as well, which the endpoint
    // loop cannot mount without fabricating a model.
    $files = array_merge(
        glob(base_path('resources/views/pages/admin/**/⚡*.blade.php')) ?: [],
        glob(base_path('resources/views/pages/admin/⚡*.blade.php')) ?: [],
    );

    expect($files)->not->toBeEmpty();

    $unguarded = collect($files)
        ->reject(function (string $path): bool {
            $source = file_get_contents($path);

            return str_contains($source, 'hasPermission(AccountPermission::')
                || str_contains($source, 'Gate::authorize(')
                || str_contains($source, '$this->authorize(');
        })
        ->map(fn (string $path): string => basename($path))
        ->all();

    expect($unguarded)->toBe([], 'These admin consoles trust the route middleware alone.');
});
