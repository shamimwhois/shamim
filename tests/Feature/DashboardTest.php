<?php

use App\Enums\AccountRole;
use App\Models\Post;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard.index'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('id="dashboard-main"', escape: false);
});

test('the dashboard is excluded from search engines', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertSee('<meta name="robots" content="noindex', escape: false);
});

test('the dashboard counts only the signed-in author own posts', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();

    Post::factory()->for($author)->published()->count(3)->create();
    Post::factory()->for($other)->published()->count(5)->create();

    $response = $this->actingAs($author)->get(route('dashboard.index'));

    $response->assertOk();

    // Scrape the value rendered inside the "Published" metric tile. Asserting on
    // the surrounding whitespace would couple the test to Blade's indentation.
    preg_match('/Published\s*<\/p>.*?<p[^>]*>\s*([\d,]+)\s*<\/p>/s', $response->getContent(), $matches);

    expect($matches[1] ?? null)->toBe('3');
});

/*
|--------------------------------------------------------------------------
| Role-scoped dashboards
|--------------------------------------------------------------------------
*/

test('a plain user sees the author workspace at /dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('id="dashboard-main"', escape: false);
});

test('a seller is forwarded from /dashboard to the seller home', function () {
    $this->actingAs(User::factory()->seller()->create())
        ->get(route('dashboard.index'))
        ->assertRedirect(route('seller.dashboard'));
});

test('an admin is forwarded from /dashboard to the admin home', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard.index'))
        ->assertRedirect(route('admin.dashboard'));
});

test('a seller can reach the seller home', function () {
    $this->actingAs(User::factory()->seller()->create())
        ->get(route('seller.dashboard'))
        ->assertOk()
        ->assertSee('id="dashboard-main"', escape: false);
});

test('a plain user is forbidden from the seller home', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('seller.dashboard'))
        ->assertForbidden();
});

test('an admin can reach the admin home', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('id="dashboard-main"', escape: false);
});

test('a seller is forbidden from the admin home', function () {
    $this->actingAs(User::factory()->seller()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('a plain user is forbidden from the admin home', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('only admins can reach user management', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.users'))
        ->assertForbidden();

    $this->actingAs(User::factory()->seller()->create())
        ->get(route('admin.users'))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.users'))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Role-aware navigation
|--------------------------------------------------------------------------
*/

test('the sidebar shows the administration section only to admins', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertDontSee('Admin dashboard');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Admin dashboard');
});

test('the admin sidebar lists only admin routes', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Admin dashboard')
        ->assertSee('Users')
        // Sidebar-only entries that belonged to other workspaces are gone.
        ->assertDontSee(route('seller.dashboard'))
        ->assertDontSee(route('ideas.index'))
        ->assertDontSee(route('marketplace.index'));
});

test('the sidebar shows the selling section only to sellers and admins', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertDontSee('Seller dashboard');

    $this->actingAs(User::factory()->seller()->create())
        ->get(route('seller.dashboard'))
        ->assertOk()
        ->assertSee('Seller dashboard');
});

test('a plain user never sees the taxonomy link in the sidebar', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertDontSee(route('dashboard.taxonomy'), escape: false);
});

/*
|--------------------------------------------------------------------------
| Role badge
|--------------------------------------------------------------------------
*/

test('the sidebar shows the account role and plan', function () {
    $this->actingAs(User::factory()->seller()->create())
        ->get(route('seller.dashboard'))
        ->assertOk()
        ->assertSee('Seller');
});

test('each role resolves its own dashboard route', function () {
    expect(User::factory()->create()->dashboardRoute())->toBe('dashboard.index')
        ->and(User::factory()->seller()->create()->dashboardRoute())->toBe('seller.dashboard')
        ->and(User::factory()->admin()->create()->dashboardRoute())->toBe('admin.dashboard');
});

test('the account role casts to the enum', function () {
    expect(User::factory()->create(['role' => AccountRole::User])->role)->toBe(AccountRole::User)
        ->and(User::factory()->create(['role' => 'seller'])->role)->toBe(AccountRole::Seller);
});
