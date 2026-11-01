<?php

use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Models\LabSnippet;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
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

test('guests are bounced to the sign-in dialog rather than refused from every role dashboard', function () {
    // A 403 would be a dead end for a guest who may hold the permission after
    // authenticating. `EnsureAccountPermission` redirects instead — to the
    // site carrying `?signin=required`, which opens the login dialog; the
    // attempted URL is kept as `url.intended` for after the sign-in.
    foreach (['/dashboard', '/seller/dashboard', '/admin/dashboard', '/admin/posts', '/admin/comments', '/admin/taxonomy', '/ideas/dashboard', '/ideas/plans', '/ideas/categories', '/dev-lab/dashboard', '/dev-lab/sandbox', '/dev-lab/snippets', '/dev-lab/history'] as $path) {
        $this->get($path)->assertRedirect(route('home', ['signin' => 'required']));
    }
});

/*
|--------------------------------------------------------------------------
| Platform module routes
|--------------------------------------------------------------------------
*/

test('the module landing pages and their admin consoles resolve to their own paths', function () {
    expect(route('dev-lab.index', absolute: false))->toBe('/dev-lab')
        ->and(route('ai-hub.index', absolute: false))->toBe('/ai-hub')
        ->and(route('marketplace.index', absolute: false))->toBe('/marketplace')
        ->and(route('ideas.index', absolute: false))->toBe('/ideas')
        ->and(route('admin.dev-lab', absolute: false))->toBe('/admin/dev-lab')
        ->and(route('admin.ideas', absolute: false))->toBe('/admin/ideas');
});

test('the shared subject taxonomy claims a top-level space of its own', function () {
    // `/topics` and `/topics/{topic:slug}` sit beside the two module spaces rather
    // than inside either. The first segment is what keeps them apart: an idea may be
    // slugged "topics" and live at `/ideas/topics`, one segment deeper, so neither
    // route can swallow the other and no word needs reserving for it.
    expect(route('topics.index', absolute: false))->toBe('/topics')
        ->and(route('topics.show', ['topic' => 'forms'], absolute: false))->toBe('/topics/forms')
        ->and(route('ai-hub.topics', absolute: false))->toBe('/ai-hub/topics')
        ->and(route('ai-hub.topic', ['topic' => 'forms'], absolute: false))->toBe('/ai-hub/topics/forms');
});

test('the ideas workspace and its plan screens resolve to their own paths', function () {
    // Plans live one level inside the ideas URL space, so the whole second segment
    // is a literal the `{idea:slug}` catch-all must never be allowed to swallow.
    expect(route('ideas.dashboard', absolute: false))->toBe('/ideas/dashboard')
        ->and(route('ideas.create', absolute: false))->toBe('/ideas/create')
        ->and(route('ideas.categories', absolute: false))->toBe('/ideas/categories')
        ->and(route('ideas.plans', absolute: false))->toBe('/ideas/plans')
        ->and(route('ideas.plan.create', absolute: false))->toBe('/ideas/plans/create')
        ->and(route('ideas.plan', ['plan' => 'a-plan'], absolute: false))->toBe('/ideas/plans/a-plan')
        ->and(route('ideas.plan.edit', ['plan' => 'a-plan'], absolute: false))->toBe('/ideas/plans/a-plan/edit')
        ->and(route('ideas.edit', ['idea' => 'an-idea'], absolute: false))->toBe('/ideas/an-idea/edit')
        ->and(route('ideas.show', ['idea' => 'an-idea'], absolute: false))->toBe('/ideas/an-idea');
});

test('a plan screen wins over an idea whose slug is the same word', function () {
    // The ordering rule made visible: an account may capture an idea called "plans",
    // and `/ideas/plans` must still be the listing rather than that idea.
    $idea = Idea::factory()->create(['slug' => 'plans', 'status' => IdeaStatus::Approved]);

    $this->actingAs(User::factory()->create())
        ->get('/ideas/plans')
        ->assertOk()
        ->assertDontSee($idea->title);
});

test('the developer lab workspace and its public pages resolve to their own paths', function () {
    // The same discipline as the ideas space: the workspace segments are literals
    // that the `{snippet:slug}` route must never swallow, and the two snippet paths
    // differ by depth (`/snippets/{slug}` against `/snippets/{slug}/edit`) with
    // `/snippets/create` registered ahead of both.
    expect(route('dev-lab.dashboard', absolute: false))->toBe('/dev-lab/dashboard')
        ->and(route('dev-lab.sandbox', absolute: false))->toBe('/dev-lab/sandbox')
        ->and(route('dev-lab.snippets', absolute: false))->toBe('/dev-lab/snippets')
        ->and(route('dev-lab.snippets.create', absolute: false))->toBe('/dev-lab/snippets/create')
        ->and(route('dev-lab.snippets.edit', ['snippet' => 'a-snippet'], absolute: false))->toBe('/dev-lab/snippets/a-snippet/edit')
        ->and(route('dev-lab.history', absolute: false))->toBe('/dev-lab/history')
        ->and(route('dev-lab.gallery', absolute: false))->toBe('/dev-lab/gallery')
        ->and(route('dev-lab.snippets.show', ['snippet' => 'a-snippet'], absolute: false))->toBe('/dev-lab/snippets/a-snippet');
});

test('a snippet cannot be named after a screen in its own URL space', function () {
    // `create` is registered ahead of `{snippet:slug}`, so a row with that slug
    // would still open the form rather than itself — the canonical link of the
    // snippet would quietly point somewhere else. The rule refuses the name.
    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.snippet')
        ->set('title', 'A snippet called create')
        ->set('slug', 'create')
        ->set('code', '<p>anything</p>')
        ->call('save')
        ->assertHasErrors(['slug']);

    expect(LabSnippet::query()->count())->toBe(0);
});

test('the hub shelves keep their own namespaced paths rather than the module ones', function () {
    // `/ai-hub/dev-lab` is the hub's curated shelf, a different page from the
    // public `/dev-lab` landing. Pinning both keeps a future edit from folding
    // one into the other and silently shadowing a route name.
    expect(route('ai-hub.dev-lab.index', absolute: false))->toBe('/ai-hub/dev-lab')
        ->and(route('ai-hub.ideas.index', absolute: false))->toBe('/ai-hub/ideas');
});

test('every role can reach the three shared modules', function () {
    foreach (['/dev-lab', '/ai-hub', '/ideas'] as $path) {
        $this->actingAs(User::factory()->create())->get($path)->assertOk();
        $this->actingAs(User::factory()->seller()->create())->get($path)->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get($path)->assertOk();
    }
});

test('the marketplace catalogue and listing detail are browsable by everyone', function () {
    // The catalogue is a storefront, not a workspace: anyone may read it, the
    // way a public package index is readable. Selling remains permission-gated,
    // which is what the seller dashboard guards.
    $product = MarketplaceProduct::factory()->published()->create();
    $listingRoute = route('marketplace.show', ['product' => $product->slug]);

    $this->get('/marketplace')->assertOk();
    $this->get($listingRoute)->assertOk();

    $this->actingAs(User::factory()->create())->get('/marketplace')->assertOk();
    $this->actingAs(User::factory()->seller()->create())->get('/marketplace')->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get('/marketplace')->assertOk();
});

test('the marketplace catalogue links a published listing to its detail page', function () {
    $category = MarketplaceCategory::factory()->create(['name' => 'Laravel Packages']);
    $product = MarketplaceProduct::factory()->published()->create([
        'marketplace_category_id' => $category->id,
        'name' => 'Activity Ledger',
    ]);

    $this->get(route('marketplace.index'))
        ->assertOk()
        ->assertSee('Developer Marketplace')
        ->assertSee('Activity Ledger')
        ->assertSee(route('marketplace.show', ['product' => $product->slug]));

    Livewire::test('pages::marketplace.show', ['product' => $product])
        ->assertSee('Activity Ledger')
        ->call('showTab', 'installation')
        ->assertSet('tab', 'installation')
        ->assertSee($product->install_command)
        ->call('showTab', 'versions')
        ->assertSee('v'.$product->version);
});

test('a draft listing is not readable by the public', function () {
    $product = MarketplaceProduct::factory()->create(['name' => 'Unreleased Toolkit']);

    $this->get(route('marketplace.show', ['product' => $product->slug]))
        ->assertNotFound();
});

test('unknown marketplace slugs return not found', function () {
    $this->get(route('marketplace.show', ['product' => 'unknown-package']))
        ->assertNotFound();
});

test('the purchase screens resolve outside the catalogue they hang off', function () {
    // Buying lives at `/purchases` and `/marketplace/{slug}/checkout` rather
    // than inside the catalogue's two-segment shape: a product slugged
    // "purchases" still resolves to `/marketplace/purchases`, one segment
    // apart from the buyer's history, so neither route can swallow the other.
    expect(route('marketplace.checkout', ['product' => 'a-tool'], absolute: false))->toBe('/marketplace/a-tool/checkout')
        ->and(route('purchases.index', absolute: false))->toBe('/purchases')
        ->and(route('purchases.show', ['order' => 42], absolute: false))->toBe('/purchases/42')
        ->and(route('seller.sales', absolute: false))->toBe('/seller/sales')
        ->and(route('admin.orders', absolute: false))->toBe('/admin/orders');
});

test('the module landing pages are readable by a guest', function () {
    // Ideas and Developer Lab are landing pages, not workspaces: a reader sees
    // what the module is for without signing in, the same contract as the
    // marketplace catalogue and the hub's reading surfaces.
    foreach (['/dev-lab', '/ideas'] as $path) {
        $this->get($path)->assertOk();
    }

    $this->get('/marketplace')->assertOk();
    $this->get('/ai-hub')->assertOk();
});

test('the public header offers every module landing it can reach', function () {
    // Ideas joined the bar last of the modules, and a destination the header
    // promises but does not link is the one failure mode this pin exists for.
    // `aria-current` on `/ideas` proves the entry names the route rather than
    // merely printing a word.
    $this->get('/')
        ->assertOk()
        ->assertSee('Ideas', escape: false)
        ->assertSeeHtml('href="'.route('ideas.index').'"');

    $this->get('/ideas')
        ->assertOk()
        ->assertSeeHtml('aria-current="page"');
});

test('a guest reads a module landing page in the public shell', function () {
    // These pages used to render inside the workspace layout, whose sidebar an
    // account-less visitor can only ever see empty. `data-sui` is the marker:
    // it belongs to the workspace and would recolour a public page.
    //
    // Both /ideas and /dev-lab are real surfaces now — a catalogue and an
    // assembled landing page — so the shell is the only thing left to pin, and
    // the placeholder copy is asserted absent rather than present.
    foreach (['/dev-lab', '/ideas'] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertSee('id="main"', false)
            ->assertDontSee('Not yet available')
            ->assertDontSee('data-sui', false);
    }
});

test('a module page that is still a placeholder says so', function () {
    // The AI Hub shelves are the two module pages still standing on the
    // placeholder component: they need a hub-side curation model, which is a
    // different object from the Ideas bank and the lab's snippet library, so the
    // copy that promises rather than ships stays behind. A placeholder must never
    // read as a working feature, and this is what notices when one starts to.
    //
    // These two sit inside the hub and gate on `ai-hub:manage`, unlike the public
    // module landings, so the reader in front of them is an operator.
    foreach (['/ai-hub/ideas', '/ai-hub/dev-lab'] as $path) {
        $this->actingAs(User::factory()->admin()->create())
            ->get($path)
            ->assertOk()
            ->assertSee('Not yet available');
    }
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

test('the author screens that moved to the admin space resolve to their new paths', function () {
    // Pinned by path, not only by name: the whole point of the relocation is
    // that the first segment now says who may open the page.
    expect(route('admin.posts.index', absolute: false))->toBe('/admin/posts')
        ->and(route('admin.posts.create', absolute: false))->toBe('/admin/posts/create')
        ->and(route('admin.comments', absolute: false))->toBe('/admin/comments')
        ->and(route('admin.taxonomy', absolute: false))->toBe('/admin/taxonomy');

    // The edit route binds by primary key, so a bookmarked ID resolves without
    // a database lookup on a public redirect route.
    expect(route('admin.posts.edit', ['post' => 42], absolute: false))->toBe('/admin/posts/42/edit');
});

test('the old author paths redirect permanently, keeping their query strings', function () {
    $admin = User::factory()->admin()->create();

    // The two filter-driven pages carry their state in the query string; the
    // redirect is a closure precisely so `?status=draft` is not dropped.
    $this->actingAs($admin)->get('/dashboard/posts?status=draft')
        ->assertStatus(301)
        ->assertRedirect('/admin/posts?status=draft');

    $this->actingAs($admin)->get('/dashboard/comments?post=7&q=caching')
        ->assertStatus(301)
        ->assertRedirect('/admin/comments?post=7&q=caching');

    // The two stateless screens are plain redirects.
    $this->actingAs($admin)->get('/dashboard/posts/create')
        ->assertStatus(301)
        ->assertRedirect('/admin/posts/create');

    $this->actingAs($admin)->get('/dashboard/posts/42/edit')
        ->assertStatus(301)
        ->assertRedirect('/admin/posts/42/edit');
});

test('a non-numeric id is not redirected, so the old edit path cannot enumerate posts', function () {
    $this->get('/dashboard/posts/not-a-number/edit')->assertNotFound();
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
