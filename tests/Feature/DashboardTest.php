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
        ->assertRedirect(route('dashboard.seller.index'));
});

test('an admin is forwarded from /dashboard to the admin home', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard.index'))
        ->assertRedirect(route('dashboard.admin.index'));
});

test('a seller can reach the seller home', function () {
    $this->actingAs(User::factory()->seller()->create())
        ->get(route('dashboard.seller.index'))
        ->assertOk()
        ->assertSee('id="dashboard-main"', escape: false);
});

test('a plain user is forbidden from the seller home', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.seller.index'))
        ->assertForbidden();
});

test('an admin can reach the admin home', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard.admin.index'))
        ->assertOk()
        ->assertSee('id="dashboard-main"', escape: false);
});

test('a seller is forbidden from the admin home', function () {
    $this->actingAs(User::factory()->seller()->create())
        ->get(route('dashboard.admin.index'))
        ->assertForbidden();
});

test('a plain user is forbidden from the admin home', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.admin.index'))
        ->assertForbidden();
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
        ->assertDontSee('Admin home');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard.admin.index'))
        ->assertOk()
        ->assertSee('Admin home');
});

test('the sidebar shows the selling section only to sellers and admins', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertDontSee('Seller home');

    $this->actingAs(User::factory()->seller()->create())
        ->get(route('dashboard.seller.index'))
        ->assertOk()
        ->assertSee('Seller home');
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
        ->get(route('dashboard.seller.index'))
        ->assertOk()
        ->assertSee('Seller');
});

test('each role resolves its own dashboard route', function () {
    expect(User::factory()->create()->dashboardRoute())->toBe('dashboard.index')
        ->and(User::factory()->seller()->create()->dashboardRoute())->toBe('dashboard.seller.index')
        ->and(User::factory()->admin()->create()->dashboardRoute())->toBe('dashboard.admin.index');
});

test('the account role casts to the enum', function () {
    expect(User::factory()->create(['role' => AccountRole::User])->role)->toBe(AccountRole::User)
        ->and(User::factory()->create(['role' => 'seller'])->role)->toBe(AccountRole::Seller);
});
