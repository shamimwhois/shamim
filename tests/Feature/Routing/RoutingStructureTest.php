<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Role dashboard routes
|--------------------------------------------------------------------------
|
| The three dashboards live in separate, role-owned top-level URL spaces. These
| tests pin the *paths*, not just the route names — a route name is only as good
| as the URL it resolves to, and the whole point of this structure is that the
| first path segment names the surface.
|
*/

test('the three role dashboards resolve to their own top-level paths', function () {
    expect(route('dashboard.index', absolute: false))->toBe('/dashboard')
        ->and(route('seller.dashboard', absolute: false))->toBe('/seller/dashboard')
        ->and(route('admin.dashboard', absolute: false))->toBe('/admin/dashboard');
});

test('no role dashboard is nested inside another', function () {
    // A regression guard for the structure itself: if a future change nests one
    // home under another, this fails before anyone has to notice a broken link.
    expect(route('seller.dashboard', absolute: false))->not->toStartWith('/dashboard/')
        ->and(route('admin.dashboard', absolute: false))->not->toStartWith('/dashboard/')
        ->and(route('admin.dashboard', absolute: false))->not->toStartWith('/seller/');
});

test('a plain user reaches the author workspace', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk();
});

test('a seller reaches the seller dashboard and no other role dashboard', function () {
    $seller = User::factory()->seller()->create();

    $this->actingAs($seller)->get('/seller/dashboard')->assertOk();

    // The seller dashboard is permission-guarded, so a plain user is refused
    // before the component boots.
    $this->actingAs(User::factory()->create())->get('/seller/dashboard')->assertForbidden();
});

test('an admin reaches the admin dashboard and nobody else does', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/admin/dashboard')->assertOk();

    $this->actingAs(User::factory()->create())->get('/admin/dashboard')->assertForbidden();
    $this->actingAs(User::factory()->seller()->create())->get('/admin/dashboard')->assertForbidden();
});

test('guests are redirected to login rather than refused from every role dashboard', function () {
    // A 403 would be a dead end for a guest who may hold the permission after
    // authenticating. `EnsureAccountPermission` redirects instead.
    foreach (['/dashboard', '/seller/dashboard', '/admin/dashboard'] as $path) {
        $this->get($path)->assertRedirect(route('login'));
    }
});

/*
|--------------------------------------------------------------------------
| Platform module routes
|--------------------------------------------------------------------------
*/

test('the four module routes resolve to their own top-level paths', function () {
    expect(route('dev-lab.index', absolute: false))->toBe('/dev-lab')
        ->and(route('ai-hub.index', absolute: false))->toBe('/ai-hub')
        ->and(route('marketplace.index', absolute: false))->toBe('/marketplace')
        ->and(route('ideas.index', absolute: false))->toBe('/ideas');
});

test('every role can reach the three shared modules', function () {
    foreach (['/dev-lab', '/ai-hub', '/ideas'] as $path) {
        $this->actingAs(User::factory()->create())->get($path)->assertOk();
        $this->actingAs(User::factory()->seller()->create())->get($path)->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get($path)->assertOk();
    }
});

test('the marketplace catalogue and package detail are browsable by everyone', function () {
    // The catalogue is a storefront, not a workspace: anyone may read it, the
    // way a public package index is readable. Selling remains permission-gated,
    // which is what the seller dashboard guards.
    $packageRoute = route('marketplace.show', ['package' => 'media-library-for-laravel']);

    $this->get('/marketplace')->assertOk();
    $this->get($packageRoute)->assertOk();

    $this->actingAs(User::factory()->create())->get('/marketplace')->assertOk();
    $this->actingAs(User::factory()->seller()->create())->get('/marketplace')->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get('/marketplace')->assertOk();
});

test('the marketplace catalogue links an attributed example to package documentation', function () {
    $seller = User::factory()->seller()->create();

    $this->get(route('marketplace.index'))
        ->assertOk()
        ->assertSee('Developer Marketplace')
        ->assertSee('Reference package')
        ->assertSee('Roundly Consulting')
        ->assertSee(route('marketplace.show', ['package' => 'media-library-for-laravel']));

    Livewire::actingAs($seller)
        ->test('pages::marketplace.show', ['package' => 'media-library-for-laravel'])
        ->assertSee('Media Library for Laravel')
        ->call('showDocument', 'installation')
        ->assertSet('activeDocument', 'installation')
        ->assertSee('media-migrations')
        ->call('showDocument', 'adding-media')
        ->assertSee('addFromUrl')
        ->call('showDocument', 'requirements')
        ->assertSee('ext-fileinfo')
        ->assertSee('ext-imagick or ext-gd');
});

test('the package documentation supports direct links to a section', function () {
    $this->get(route('marketplace.show', ['package' => 'media-library-for-laravel', 'doc' => 'requirements']))
        ->assertOk()
        ->assertSee('ext-fileinfo')
        ->assertSee('ext-imagick or ext-gd');
});

test('unknown marketplace package slugs return not found', function () {
    $this->get(route('marketplace.show', ['package' => 'unknown-package']))
        ->assertNotFound();
});

test('guests cannot reach any gated module route', function () {
    // The marketplace is deliberately absent: it is a public catalogue, so a
    // guest browsing it is reading, not entering a workspace.
    foreach (['/dev-lab', '/ai-hub', '/ideas'] as $path) {
        $this->get($path)->assertRedirect(route('login'));
    }

    $this->get('/marketplace')->assertOk();
});

test('a module page states that it is not yet available', function () {
    // The placeholder must not read as a working feature.
    $this->actingAs(User::factory()->admin()->create())
        ->get('/dev-lab')
        ->assertOk()
        ->assertSee('Not yet available');
});

/*
|--------------------------------------------------------------------------
| Backward-compatible redirects
|--------------------------------------------------------------------------
*/

test('the old nested role dashboard paths redirect permanently to the new ones', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard/admin')
        ->assertStatus(301)
        ->assertRedirect('/admin/dashboard');

    $this->actingAs(User::factory()->seller()->create())
        ->get('/dashboard/seller')
        ->assertStatus(301)
        ->assertRedirect('/seller/dashboard');
});

test('the removed team routes redirect rather than 404', function () {
    // `/teams/dashboard` was the starter kit's team-scoped dashboard. The team
    // feature is gone, but a bookmark must still land somewhere sensible.
    $this->actingAs(User::factory()->create())
        ->get('/teams/dashboard')
        ->assertStatus(301)
        ->assertRedirect('/dashboard');
});

test('the removed team settings page redirects rather than 404', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/teams')
        ->assertStatus(301)
        ->assertRedirect('/settings/security');
});

/*
|--------------------------------------------------------------------------
| Team routes are gone
|--------------------------------------------------------------------------
*/

test('no route anywhere still carries a team URL parameter', function () {
    // The strongest statement of the refactor: no registered route has a
    // `current_team` or `team` parameter, so no URL can reintroduce team
    // scoping by accident.
    $offenders = [];

    foreach (app('router')->getRoutes() as $route) {
        if (array_intersect(['current_team', 'team'], $route->parameterNames()) !== []) {
            $offenders[] = $route->uri();
        }
    }

    expect($offenders)->toBe([]);
});

test('the team models and enums no longer exist', function () {
    // Checked on disk rather than via class_exists(): once the files are gone
    // the Composer autoloader throws on `include`, it does not return false.
    expect(app_path('Models/Team.php'))->not->toBeFile()
        ->and(app_path('Models/TeamInvitation.php'))->not->toBeFile()
        ->and(app_path('Models/Membership.php'))->not->toBeFile()
        ->and(app_path('Enums/TeamRole.php'))->not->toBeFile()
        ->and(app_path('Enums/TeamPermission.php'))->not->toBeFile();
});

test('the users table no longer carries a current_team_id column', function () {
    expect(Schema::hasColumn('users', 'current_team_id'))->toBeFalse();
});
