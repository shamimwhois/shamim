<?php

use App\Enums\AccountRole;
use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * The workspace shell.
 *
 * Structural tests, not render tests. They assert the contract the five layouts
 * in `resources/views/layouts` and the components in
 * `resources/views/components/app` make to each other, so that reorganising
 * that tree fails here rather than showing up as a sidebar with no drawer in
 * the browser.
 *
 * The reorganisation this suite pins down:
 *
 *   layouts::guest.home   → components/app/home/{header,footer}
 *   layouts::auth         → components/app/layouts/auth/split
 *   layouts::app.dashboard    ┐
 *   layouts::seller.dashboard ├ components/app/ui/dashboard-shell
 *   layouts::admin.dashboard  ┘ + components/app/{user,seller,admin}/*
 */

/**
 * The markers each region of the shell leaves in the rendered page.
 *
 * One entry per region, because a region that renders nothing is a slot that
 * was never filled — and a slot that was never filled is silent: Blade emits
 * nothing and the page looks almost right.
 *
 * A function rather than a constant array because `route()` needs the
 * application booted, and this file is parsed before that.
 */
$regions = function (): array {
    return [
        'desktop rail' => 'sui-rail hidden shrink-0 border-r',
        'rail brand mark' => 'bg-white text-sui-rail',
        'mobile brand mark' => 'bg-sui-rail text-sui-rail-ink',
        'mobile top bar' => 'sticky top-0 z-30',
        'rail toggle' => 'data-test="rail-toggle"',
        'mobile drawer' => 'id="dashboard-drawer"',
        'drawer trigger' => 'data-test="drawer-open"',
        'drawer close' => 'data-test="drawer-close"',
        'page main' => 'id="dashboard-main"',
        'logout control' => 'action="'.route('logout').'"',
    ];
};

/**
 * The three workspace layouts, and the role chrome each one fills the shell
 * with. `label` is the role-scoped accessible name its sidebar passes to the
 * shared navigation — the one piece of role identity the chrome contributes.
 */
$workspaces = [
    'app (user)' => ['route' => 'dashboard.index',   'role' => 'user',   'label' => 'Author workspace',  'badge' => null],
    'seller' => ['route' => 'seller.dashboard',  'role' => 'seller', 'label' => 'Selling workspace', 'badge' => 'sui-role-seller'],
    'admin' => ['route' => 'admin.dashboard',   'role' => 'admin',  'label' => 'Administration',    'badge' => 'sui-role-admin'],
];

test('every workspace layout fills every region of the shared shell', function () use ($regions, $workspaces) {
    foreach ($workspaces as $key => ['route' => $route, 'role' => $role]) {
        $response = $this->actingAs(User::factory()->role(AccountRole::from($role))->create())
            ->get(route($route));

        $response->assertOk();

        foreach ($regions() as $region => $marker) {
            $response->assertSee($marker, false, sprintf('[%s] is missing the [%s] region.', $key, $region));
        }
    }
})->group('shell');

test('each workspace layout supplies its own role chrome', function () use ($workspaces) {
    foreach ($workspaces as $key => ['route' => $route, 'role' => $role, 'label' => $label, 'badge' => $badge]) {
        $user = User::factory()->role(AccountRole::from($role))->create();

        $response = $this->actingAs($user)->get(route($route));

        $response->assertOk()
            // The sidebar names its own landmark, not a generic one.
            ->assertSee('aria-label="'.$label.'"', false)
            // The rail's wordmark points at the role's own home.
            ->assertSee('href="'.route($user->dashboardRoute()).'"', false)
            // The account block renders the role, the plan and the way out.
            ->assertSee(__('View site'), false)
            ->assertSee(__('Settings'), false)
            ->assertSee(__('Log out'), false)
            ->assertSee($user->plan()->tagline(), false);

        if ($badge !== null) {
            $response->assertSee($badge, false);
        } else {
            $response->assertSee($user->accountRole()->label(), false);
        }
    }
})->group('shell');

test('the shared shell is one implementation, not three copies', function () {
    // The rail and the drawer are one implementation. A second copy of the
    // focus trap is the failure mode this guards against: three layouts that
    // each carry their own rail is three places to fix the same bug, and they
    // drift. So the distinctive markers of the hand-rolled trap — which exist
    // because Flux does not bundle `x-trap` — must appear in exactly one view.
    $shell = realpath(resource_path('views/components/app/ui/dashboard-shell.blade.php'));

    expect($shell)->not->toBeFalse()
        ->and(file_get_contents($shell))
        ->toContain('previousFocus')
        ->toContain('focusables()')
        ->toContain('x-teleport="body"')
        ->toContain('x-effect="drawer ? onOpen() : onClose()"');

    $copies = collect(File::allFiles(resource_path('views')))
        ->reject(fn (SplFileInfo $file) => $file->getPathname() === $shell)
        ->filter(fn (SplFileInfo $file) => str_contains($file->getContents(), 'previousFocus'));

    expect($copies->map->getFilename()->all())->toBe([]);
})->group('shell');

test('the wordmark is one component rendered in every rail position', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->getContent();

    // Rail brand, drawer brand, mobile top bar. Three positions, one glyph.
    expect(substr_count($html, '&gt;_</span>'))->toBe(3);

    // ...and the glyph is defined in exactly one file, which is the point of
    // the deduplication: four inline copies had drifted into three different
    // radii with no record of which was intended.
    $definitions = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file) => str_contains($file->getContents(), '&gt;_</span>'));

    expect($definitions->count())->toBe(1)
        ->and($definitions->first()->getFilename())->toBe('logo-mark.blade.php');
})->group('shell');

test('the guest layout renders the public chrome and carries no workspace scope', function () {
    $response = $this->get(route('blog.index'));

    $response->assertOk()
        ->assertSee('id="main"', false)
        // The public header and footer, under their new component names.
        ->assertSee('Toggle navigation', false)
        ->assertSee('All rights reserved', false)
        // `data-sui` belongs to the workspace only: a public page that picked
        // it up would silently recolour to the dashboard's palette.
        ->assertDontSee('data-sui', false)
        // ...and must not reach for a `sui-*` utility to do the public job.
        ->assertDontSee('sui-rail', false);
})->group('shell');

test('the auth layout is reachable and renders the split panel', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('id="auth-form"', false)
        ->assertSee('Sign in to your workspace.', false)
        ->assertSee('Secure area', false)
        ->assertDontSee('data-sui', false);
})->group('shell');

test('the settings pages render in the guest shell by design', function () {
    // Documented in layouts/guest/home.blade.php: settings is the one
    // authenticated surface that deliberately keeps the quiet public frame,
    // so it carries no workspace chrome and no `data-sui` scope.
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('id="main"', false)
        ->assertDontSee('data-sui', false);
})->group('shell');
