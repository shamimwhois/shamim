<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;

/*
|--------------------------------------------------------------------------
| Every permission-gated route, swept in one pass
|--------------------------------------------------------------------------
|
| This file exists because a guarded page is a decision somebody has to make
| again on every new route, and the forgetting is silent: a route added without
| `auth` in front of `permission:` does not fail, it just redirects every guest
| and lets every signed-in account through the `verified` gate unchallenged.
|
| So the list is derived from the router instead of written out. A new
| permission-gated route appears in every test here the day it lands, and the
| only way to keep this suite green is to give it the middleware it needs.
|
| Three states are asserted per route, because they are three different bugs:
|
|   1. declared — the route names `auth` and `verified` next to `permission:`,
|      which is a structural fact and needs no request;
|   2. refused — an account whose role does not hold the permission gets a 403,
|      which is the middleware actually running rather than being present;
|   3. reachable — an admin gets anything but a 403, which proves the guard is
|      not simply refusing everybody.
|
| A guest is asserted once for the whole class rather than per route: the
| bounce is the same `redirect()->guest()` in `EnsureAccountPermission` for
| every one of them, and forty identical redirects would be forty ways to read
| the same line of code.
|
*/

/**
 * The permission each route's middleware names, in declaration order.
 *
 * @return array<int, AccountPermission>
 */
function routePermissions(RouteDefinition $route): array
{
    return collect($route->gatherMiddleware())
        ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'permission:'))
        ->flatMap(fn (string $middleware): array => array_values(array_filter(array_map(
            fn (string $value): ?AccountPermission => AccountPermission::tryFrom($value),
            explode(',', substr($middleware, strlen('permission:'))),
        ))))
        ->all();
}

/**
 * @return Collection<string, RouteDefinition>
 */
function permissionGatedRoutes(): Collection
{
    return collect(RouteFacade::getRoutes())
        ->filter(fn (RouteDefinition $route): bool => routePermissions($route) !== [])
        ->keyBy(fn (RouteDefinition $route): string => $route->methods()[0].' '.$route->uri());
}

/**
 * Param-free gated routes, which are the ones a test can request without
 * first fabricating the model a binding would resolve.
 *
 * @return Collection<string, RouteDefinition>
 */
function requestableGatedRoutes(): Collection
{
    return permissionGatedRoutes()->filter(
        fn (RouteDefinition $route): bool => ! str_contains($route->uri(), '{'),
    );
}

/**
 * The first role that does not hold every permission the route asks for.
 *
 * `null` means no role in the application lacks it, so there is no account to
 * refuse the route with and the refusal case is vacuously true.
 */
function roleLackingAllOf(array $permissions): ?AccountRole
{
    foreach (AccountRole::cases() as $role) {
        if (! $role->hasAnyPermission(...$permissions)) {
            return $role;
        }
    }

    return null;
}

test('every permission-gated route also requires authentication', function (): void {
    $missing = permissionGatedRoutes()->filter(
        fn (RouteDefinition $route): bool => ! collect($route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => $middleware === 'auth' || str_starts_with($middleware, 'auth:')),
    )->keys()->all();

    expect($missing)->toBe([], 'These routes ask for a permission without first asking who is calling.');
});

test('every permission-gated route also requires a verified address', function (): void {
    $missing = permissionGatedRoutes()->filter(
        fn (RouteDefinition $route): bool => ! in_array('verified', $route->gatherMiddleware(), true),
    )->keys()->all();

    expect($missing)->toBe([], 'These routes let an account through a module gate before it has proven its email.');
});

test('every permission string on a gated route resolves to a real permission', function (): void {
    // `permission:admim:access` would be a typo nobody notices: the guard
    // aborts 500 at request time, but only for whoever finds it by visiting.
    $unresolved = collect(RouteFacade::getRoutes())
        ->flatMap(fn (RouteDefinition $route): array => collect($route->gatherMiddleware())
            ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'permission:'))
            ->map(fn (string $middleware): string => $middleware.' @ '.$route->uri())
            ->all())
        ->filter(function (string $entry): bool {
            [$middleware] = explode(' @ ', $entry);
            $values = explode(',', substr($middleware, strlen('permission:')));

            return collect($values)->reject(
                fn (string $value): bool => in_array($value, ['all', 'any'], true)
                    || AccountPermission::tryFrom($value) !== null,
            )->isNotEmpty();
        })
        ->all();

    expect($unresolved)->toBe([], 'These middleware name a permission that does not exist.');
});

test('a guest is bounced to the sign-in dialog rather than shown a 403', function (): void {
    // The bounce is `EnsureAccountPermission`'s own defensive branch, and it is
    // asserted once here for the class of routes instead of per route.
    foreach (requestableGatedRoutes() as $key => $route) {
        $this->call(strtolower($route->methods()[0]), '/'.ltrim($route->uri(), '/'))
            ->assertRedirect(route('home', ['signin' => 'required']));
    }
});

test('an unverified account is sent to the verification notice', function (): void {
    $account = User::factory()->admin()->unverified()->create();

    foreach (requestableGatedRoutes() as $route) {
        $this->actingAs($account)
            ->call(strtolower($route->methods()[0]), '/'.ltrim($route->uri(), '/'))
            ->assertRedirect(route('verification.notice'));
    }
});

test('an account whose role lacks the permission is refused', function (): void {
    // Looped inside the test rather than expanded into a dataset: Pest reads a
    // dataset at collection time, before the application is booted, and the
    // router is not available then.
    $refused = 0;

    foreach (requestableGatedRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');
        $method = strtolower($route->methods()[0]);
        $role = roleLackingAllOf(routePermissions($route));

        if ($role === null) {
            // Every role holds it, so the route has no account to refuse today.
            // Worth counting, not worth failing: a gate gains somebody to refuse
            // as roles are added.
            continue;
        }

        $this->actingAs(User::factory()->create(['role' => $role->value]))
            ->call($method, $uri)
            ->assertForbidden();

        $refused++;
    }

    expect($refused)->toBeGreaterThan(0, 'No gated route had a role that lacks its permission — the sweep is asserting nothing.');
});

test('an account that holds every permission is not refused', function (): void {
    $admin = User::factory()->admin()->create();
    $checked = 0;

    foreach (requestableGatedRoutes() as $route) {
        if (strtolower($route->methods()[0]) !== 'get') {
            continue;
        }

        $status = $this->actingAs($admin)->get('/'.ltrim($route->uri(), '/'))->getStatusCode();

        expect($status)->not->toBe(403, "{$route->uri()} refused an admin.");

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});

test('the profile screen stays reachable without a verified address on purpose', function (): void {
    /*
     * The one exception the sweep above would flag if it were widened past
     * permission-gated routes. `settings/profile` is `auth` only — see the
     * grouping in `routes/settings.php` — because the address a locked account
     * needs to correct is edited on that screen. Requiring verification to
     * reach the place where you fix your verification is a circular door, so
     * the exception is pinned rather than left to be "discovered" by whoever
     * next adds `verified` for tidiness.
     */
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/settings/profile')
        ->assertOk();

    expect(in_array('verified', RouteFacade::getRoutes()->getByName('profile.edit')->gatherMiddleware(), true))->toBeFalse();
});
