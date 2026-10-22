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
        'desktop sidenav' => 'sui-sidenav sticky top-3',
        'sidenav brand mark' => 'bg-sui-accent text-sui-ink-on-accent',
        'floating navbar' => 'sui-nav mx-4',
        'sidenav collapse toggle' => 'data-test="sidenav-toggle"',
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

        // The floating navbar carries the Creative Tim set. The profile menu
        // is the machinery every workspace must have; a navbar that renders the
        // pill without it is the failure this guards.
        $response
            ->assertSee('data-flux-dropdown', false)
            ->assertSee('data-flux-menu', false);

        // The post search and the pending-comment bell both link into
        // `/admin`, so they render only where that workspace is reachable.
        if ($role === AccountRole::Admin->value) {
            $response
                ->assertSee('name="q"', false)
                ->assertSee(route('admin.posts.index'), false)
                ->assertSee(__('Notifications'), false)
                ->assertSee(__('Review the queue'), false);
        } else {
            $response
                ->assertDontSee('name="q"', false)
                ->assertDontSee(__('Review the queue'), false);
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
            // The sidenav's wordmark points at the role's own home.
            ->assertSee('href="'.route($user->dashboardRoute()).'"', false)
            // The account block renders the identity and the way out.
            ->assertSee(__('View site'), false)
            ->assertSee(__('Settings'), false)
            ->assertSee(__('Log out'), false);

        // The pinned footer is identity and way out — not the plan card it
        // used to carry. The plan still exists (the seller dashboard shows
        // it, the profile menu shows the role), it is simply no longer
        // repeated in the rail, so the sidenav stays a single-purpose
        // navigation column.
        preg_match('/<aside\b.*?<\/aside>/s', $response->getContent(), $aside);

        expect($aside)->not->toBeFalse()
            ->and($aside[0])->not->toContain($user->plan()->tagline());

        if ($badge !== null) {
            $response->assertSee($badge, false);
        } else {
            $response->assertSee($user->accountRole()->label(), false);
        }
    }
})->group('shell');

test('the sidebar nav renders every destination with no disclosure control', function () use ($workspaces) {
    foreach ($workspaces as $key => ['route' => $route, 'role' => $role]) {
        $response = $this->actingAs(User::factory()->role(AccountRole::from($role))->create())
            ->get(route($route));

        $response->assertOk();

        // Groups render flat — no disclosure button, no `aria-controls` pair,
        // nothing behind a click. A group header that looks like a link but
        // is not one was the failure this replaced.
        $response
            ->assertDontSee('data-nav-group', false)
            ->assertDontSee('aria-controls="nav-group-', false);

        // The leaves themselves are still there.
        expect(substr_count($response->getContent(), 'data-nav-item='))
            ->toBeGreaterThan(0, sprintf('[%s] renders no navigation items at all.', $key));
    }
})->group('shell');

test('the collapse toggle is one icon in the brand row, not a labelled footer row', function () use ($workspaces) {
    foreach ($workspaces as $key => ['route' => $route, 'role' => $role]) {
        $html = $this->actingAs(User::factory()->role(AccountRole::from($role))->create())
            ->get(route($route))
            ->assertOk()
            ->getContent();

        // One control, not one per shell surface: the desktop sidenav carries
        // it, and the drawer has no rail to collapse.
        expect(substr_count($html, 'data-test="sidenav-toggle"'))
            ->toBe(1, sprintf('[%s] does not render exactly one collapse toggle.', $key))
            // Icon-only — named for assistive technology, labelled for nobody else.
            ->and($html)->toContain('aria-label="'.__('Collapse or expand the sidebar').'"')
            ->and($html)->not->toContain('>Collapse</span>');
    }
})->group('shell');

test('the shared shell is one implementation, not three copies', function () {
    // The sidenav and the drawer are one implementation. A second copy of the
    // focus trap is the failure mode this guards against: three layouts that
    // each carry their own sidenav is three places to fix the same bug, and they
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

test('the wordmark is one component rendered in every sidenav position', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->getContent();

    // Sidenav brand, drawer brand, navbar breadcrumb. Three positions, one glyph.
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
        ->assertDontSee('sui-sidenav', false);
})->group('shell');

test('the auth layout is reachable and renders the split panel', function () {
    // Login itself is modal-first now: its URL redirects to the flagged home.
    $this->get(route('login'))->assertRedirect(route('home', ['signin' => 'required']));

    // The reset-password screen stands in for the auth layout — the only
    // remaining auth screen a guest can reach directly.
    $this->get(route('password.reset', ['token' => 'shell-check']))
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
