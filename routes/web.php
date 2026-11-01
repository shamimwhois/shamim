<?php

use App\Enums\AccountPermission;
use App\Http\Controllers\Editor\EditorAiController;
use App\Http\Controllers\ErrorPageController;
use App\Http\Controllers\FeedAttachmentController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\LogSearchController;
use App\Http\Controllers\MarketplaceMediaController;
use App\Http\Controllers\OrderDownloadController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Support\HttpErrorCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route map
|--------------------------------------------------------------------------
|
| Every web route in the application, in five groups, ordered by who may
| reach them:
|
|   1. Public site          — guests and signed-in accounts alike
|   2. Authenticated space  — the account's own workspace and the editor's
|                             JSON endpoints
|   3. Seller workspace     — `/seller`, guarded by the selling permission
|   4. Admin workspace      — `/admin`, guarded by the admin permission
|   5. Backward-compatible redirects, then the settings routes
|
| Each group declares its own middleware, prefix, and name prefix once, so a
| route line states only what is unique about that route. Route names and URL
| paths are frozen: views, tests, bookmarks, and docs/ROUTES.md all address
| them, so a reorganisation may move a line but never rename or re-path it.
| The exception is a deliberate relocation — a page that changes workspace, as
| the author screens did when they moved to `/admin`. That renames freely, and
| owes group 5 a 301 under every old name in exchange.
|
*/

/*
|--------------------------------------------------------------------------
| 1. Public site
|--------------------------------------------------------------------------
|
| The portfolio itself: landing page, blog, about, the community feed, the
| marketplace catalogue, the communities index, the Developer Lab and Ideas
| landing pages, and the AI Knowledge Hub's reading surfaces. All
| server-rendered Livewire single-file components registered with
| Route::livewire(), so each page is reactive without a client-side router.
|
| None of these carries an auth guard. A visitor reading a page is not
| entering a workspace — writing is gated, in group 2.
|
*/

Route::livewire('/', 'pages::home')->name('home');

Route::livewire('blog', 'pages::blog.index')->name('blog.index');
Route::livewire('blog/{post:slug}', 'pages::blog.show')->name('blog.show');

Route::livewire('about', 'pages::about')->name('about');

/*
| The project dossier behind Selected work: `/work/{project}` is the case study
| and `/work/{project}/{doc}` is one full documentation page. The optional
| segment is matched against the project's own docs index, so an unknown
| document answers 404 rather than rendering an empty article.
*/

Route::livewire('work/{project}/{doc?}', 'pages::work.show')->name('work.show');

/*
| The community feed. `feed/attachments/{attachment}` is declared before
| `feed/{slug}` so the more specific URI is matched first, and it is the only
| feed route that is not a page: it streams a private-disk image through a
| temporary signed URL.
*/

Route::livewire('feed', 'pages::feed')->name('feed.index');

Route::get('feed/attachments/{attachment}', [FeedAttachmentController::class, 'show'])
    ->name('feed.attachments.show');

Route::livewire('feed/{slug}', 'pages::feed.show')->name('feed.show');

/*
| The rest of the product's surfaces, all wearing the log's frame. A profile is
| addressed by its handle the way the design writes one — `/@priya` — and the
| inbox, remotes and the starred list are ordinary pages rather than tabs on the
| feed, because each answers a different question and deserves its own URL.
*/
Route::get('log/developers', LogSearchController::class)->name('log.search');

Route::livewire('@{handle}', 'pages::profile')
    ->where('handle', '[A-Za-z0-9_-]+')
    ->name('profile.show');

Route::livewire('starred', 'pages::starred')->name('starred.index');
Route::livewire('search', 'pages::search')->name('search.index');
Route::livewire('notifications', 'pages::notifications')->name('notifications.index');
Route::livewire('remotes/{remote?}', 'pages::remotes')->name('remotes.index');

/*
| The marketplace is a storefront, not a workspace: anyone may read it, the way
| a public package index is readable. Selling stays permission-gated, which is
| what group 3 guards. The category route renders the same component as the
| index with the filter pre-seeded, so there is one definition of "the
| catalogue" rather than two.
*/

Route::livewire('marketplace', 'pages::marketplace.index')->name('marketplace.index');

/*
| A listing screenshot lives on a private disk, so the gallery has no public path
| to fall back on and this is the only door to the bytes. It is a public route
| because a published listing is readable by anyone; the signature and the
| listing's own visibility are what make it safe, not the session. It sits at the
| depth `marketplace/products/…` so it can never collide with a listing whose slug
| happens to be `products`.
*/
Route::get('marketplace/products/{product:id}/media/{media}', [MarketplaceMediaController::class, 'show'])
    ->name('marketplace.media.show');

Route::livewire('marketplace/categories/{category:slug}', 'pages::marketplace.index')
    ->name('marketplace.category');
Route::livewire('marketplace/{product:slug}', 'pages::marketplace.show')
    ->name('marketplace.show');

/*
| Buying is the one marketplace act that needs an account: an order belongs to
| a buyer, and a guest has no one to owe. `auth` only — the base role holds no
| marketplace permission, and the checkout's real guards are ownership,
| publication and enabled providers, all enforced in `PlaceOrder`. The invoice
| is the same rule read from the document side: its component answers 404 to
| anyone who is not the buyer or an operator holding `sale:view-any`.
*/

Route::middleware(['auth'])
    ->group(function () {
        Route::livewire('marketplace/{product:slug}/checkout', 'pages::marketplace.checkout')
            ->name('marketplace.checkout');

        Route::prefix('purchases')->name('purchases.')->group(function () {
            Route::livewire('/', 'pages::marketplace.purchases')->name('index');
            Route::livewire('{order}', 'pages::marketplace.payment')->name('show');
            Route::livewire('{order}/invoice', 'pages::marketplace.invoice')->name('invoice');

            /*
            | The deliverable is the other document-side door: the buyer's own file,
            | streamed from the private disk while the order still entitles. Ownership
            | of the order is the whole rule, so the controller answers 404 to a
            | stranger rather than admitting the order exists.
            */
            Route::get('{order}/download', [OrderDownloadController::class, 'show'])
                ->name('download');
        });
    });

Route::livewire('communities', 'pages::communities.index')->name('communities.index');

/*
|--------------------------------------------------------------------------
| Developer Lab
|--------------------------------------------------------------------------
|
| The same URL space shape as Ideas, for the same reason: one prefix holds a
| workspace and a public surface, and the public detail route sits at the depth the
| workspace literals live at.
|
| The workspace routes are registered first and are either literals (`dashboard`,
| `sandbox`, `snippets`, `history`) or deeper than the public pair
| (`snippets/{snippet:slug}/edit` has one more segment than
| `snippets/{snippet:slug}`). Matching follows registration order, so a snippet
| saved with the slug `sandbox` can never swallow the editor; `App\Rules\SnippetSlug`
| refuses the name at the write as well, so the row cannot be born claiming a URL
| that already opens a screen.
|
| The public three come last. The landing page describes the module, the gallery
| lists what its accounts chose to publish, and one published snippet has a page
| that aborts 404 through `LabSnippet::isVisibleTo()` — a snippet nobody published
| has no public URL at all.
|
| It is public rather than a workspace page: a reader may see what the module is
| for without signing in, the same contract as the marketplace and the hub. The
| URL space stays claimed — this is a top-level name nothing else may take. The
| workspace entries in `DashboardNavigation` point at the module's own dashboard
| for an account that holds the permission. The console an operator would use is
| in group 4.
*/

Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::UseDeveloperLab->value])
    ->prefix('dev-lab')
    ->name('dev-lab.')
    ->group(function () {
        Route::livewire('dashboard', 'pages::dev-lab.dashboard')->name('dashboard');
        Route::livewire('sandbox', 'pages::dev-lab.sandbox')->name('sandbox');

        Route::livewire('snippets', 'pages::dev-lab.snippets')->name('snippets');
        Route::livewire('snippets/create', 'pages::dev-lab.snippet')->name('snippets.create');
        Route::livewire('snippets/{snippet:slug}/edit', 'pages::dev-lab.snippet')->name('snippets.edit');

        Route::livewire('history', 'pages::dev-lab.history')->name('history');
    });

Route::livewire('dev-lab', 'pages::dev-lab.index')->name('dev-lab.index');

Route::livewire('dev-lab/gallery', 'pages::dev-lab.gallery')->name('dev-lab.gallery');

Route::livewire('dev-lab/snippets/{snippet:slug}', 'pages::dev-lab.snippet-show')
    ->name('dev-lab.snippets.show');

/*
|--------------------------------------------------------------------------
| Ideas
|--------------------------------------------------------------------------
|
| One URL space, two audiences, and the order of the registrations below is the
| security property that keeps them apart.
|
| The workspace routes come first and are literals (`dashboard`, `create`,
| `categories`) or more specific than the public detail route
| (`{idea:slug}/edit` has one more segment than `{idea:slug}`). Laravel matches
| in registration order, so `/ideas/plans` can never be swallowed by an idea
| whose slug happens to be "plans". `App\Rules\IdeaSlug` enforces the same rule
| from the other direction: a row may not be *named* after a screen.
|
| The public pair last: `/ideas` is the published index and `/ideas/{idea:slug}`
| is one published idea. Both stay readable without an account, and the
| component itself aborts 404 through `Idea::isVisibleTo()`, so an idea that was
| never published has no public URL at all.
*/

Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::UseIdeas->value])
    ->prefix('ideas')
    ->name('ideas.')
    ->group(function () {
        Route::livewire('dashboard', 'pages::ideas.dashboard')->name('dashboard');
        Route::livewire('create', 'pages::ideas.edit')->name('create');
        Route::livewire('categories', 'pages::ideas.categories')->name('categories');

        // Plans live one level down in the same URL space, and the literals come
        // before the parametric pair for the reason the docblock above gives.
        Route::livewire('plans', 'pages::ideas.plans')->name('plans');
        Route::livewire('plans/create', 'pages::ideas.plan-edit')->name('plan.create');
        Route::livewire('plans/{plan:slug}', 'pages::ideas.plan')->name('plan');
        Route::livewire('plans/{plan:slug}/edit', 'pages::ideas.plan-edit')->name('plan.edit');

        Route::livewire('{idea:slug}/edit', 'pages::ideas.edit')->name('edit');
    });

Route::livewire('ideas', 'pages::ideas.index')->name('ideas.index');

Route::livewire('ideas/{idea:slug}', 'pages::ideas.show')->name('ideas.show');

/*
|--------------------------------------------------------------------------
| The shared subject taxonomy
|--------------------------------------------------------------------------
|
| Two public rows, and the first segment is the whole collision story. `/topics`
| and `/topics/{topic:slug}` sit beside `/ideas/{idea:slug}` and
| `/dev-lab/snippets/{snippet:slug}`, and because those claim different first
| segments none of them can swallow another: the reserved-word lists on the two
| module slugs exist for their own siblings inside their own prefix, not for this
| pair. The hub's identically shaped `ai-hub/topics` pair is inside the hub's
| prefix and is a different, hierarchical taxonomy over a different table.
|
| Both are readable without an account, and the topic page aborts 404 through
| `PublicTaxonomy::isBrowseable()` — the same count gate that decides which chips
| the ideas landing and the gallery show. A subject nothing published carries has
| no public URL, which is what keeps the index, the chips and this page telling one
| story about what exists.
*/

Route::livewire('topics', 'pages::topics')->name('topics.index');

Route::livewire('topics/{topic:slug}', 'pages::topic')->name('topics.show');

/*
| The AI Knowledge Hub's reading surfaces share one URL space and one name
| prefix. The hub's own curated Developer Lab and Ideas shelves also live
| here, but they are staff pages: they gate on `ai-hub:manage`, because the
| hub-side curation model that would fill them does not exist yet. Ideas and
| the Developer Lab each have their own module and their own workspaces now —
| what is missing is an article-like row saying which of their records the hub
| has chosen and in what order, which is a different object. An honest
| placeholder beats a public 500.
|
| Shelf routes for `posts`, `comments` and the per-item detail pages were
| once declared with no backing model or component and answered every request
| with a 500. They are gone: the blog and the feed own that content, and the
| detail routes return per-module when the shelves gain real content models.
*/

Route::prefix('ai-hub')->name('ai-hub.')->group(function () {
    Route::livewire('/', 'pages::ai-hub.index')->name('index');
    Route::livewire('articles', 'pages::ai-hub.articles')->name('articles');
    Route::livewire('articles/{article:slug}', 'pages::ai-hub.article')->name('article');

    Route::livewire('topics', 'pages::ai-hub.topics')->name('topics');
    Route::livewire('topics/{topic:slug}', 'pages::ai-hub.topic')->name('topic');

    Route::livewire('paths', 'pages::ai-hub.paths')->name('paths');
    Route::livewire('paths/{path:slug}', 'pages::ai-hub.path')->name('path');

    Route::livewire('resources', 'pages::ai-hub.resources')->name('resources');

    Route::livewire('dev-lab', 'pages::ai-hub.dev-lab')
        ->middleware(['auth', 'verified', 'permission:'.AccountPermission::ManageAiHub->value])
        ->name('dev-lab.index');

    Route::livewire('ideas', 'pages::ai-hub.ideas')
        ->middleware(['auth', 'verified', 'permission:'.AccountPermission::ManageAiHub->value])
        ->name('ideas.index');
});

/*
| Error pages. One dynamic route renders every supported status page: the
| reusable component reads its copy from `App\Support\HttpErrorCatalog`, the
| same source the `whereIn` pattern and the exception handler's
| `errors.{status}` views draw from. The response carries the real status,
| so `/errors/404` answers with a 404.
*/

Route::get('errors/{code}', ErrorPageController::class)
    ->whereIn('code', HttpErrorCatalog::codes())
    ->name('errors.show');

/*
| Machine-readable endpoints. Plain controllers because they return XML and
| plain text, not HTML — the error pages above are the only other
| non-Livewire routes, because they must render without Livewire to be
| reachable during a real failure.
*/

Route::get('feed.xml', FeedController::class)->name('feed');
Route::get('sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('robots.txt', RobotsController::class);

/*
|--------------------------------------------------------------------------
| 2. Authenticated space
|--------------------------------------------------------------------------
|
| The account's own workspace: one page per surface, plus the editor's JSON
| endpoints. `permission:` is the account-level guard from
| {@see AccountPermission}, enforced on the route as well as in the component,
| so an unauthorised account never reaches the page at all — a 403 before the
| component boots, rather than a rendered shell with a hidden button.
|
*/

Route::middleware(['auth', 'verified'])->prefix('dashboard')->name('dashboard.')->group(function () {
    /*
     * The user role's home. It is also the safe landing spot for every role,
     * which forwards a seller or an admin to the dashboard it owns. The author
     * screens that used to sit beside it — posts, comments, taxonomy — now live
     * in the admin workspace (group 4), so what is left here is read-only.
     */
    Route::livewire('/', 'pages::dashboard.index')->name('index');
});

/*
|--------------------------------------------------------------------------
| Editor AI
|--------------------------------------------------------------------------
|
| JSON endpoints behind the rich text editor's AI features. They live under
| `/editor/ai` rather than inside `/dashboard` because the editor is mounted
| from the dashboard but is a self-contained widget, and because these are the
| only JSON endpoints in the app — one namespace makes that obvious at a glance.
|
| Every route requires `post:create`: reviewing text, generating drafts and
| generating images are all authoring operations. The throttle limits are
| deliberately tight because these endpoints spend real money on every call —
| the per-minute limit is what stops someone leaning on a keyboard shortcut
| from emptying an account. Image generation is throttled hardest.
|
*/

Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::CreatePost->value])
    ->prefix('editor/ai')
    ->name('editor.ai.')
    ->group(function () {
        Route::get('capabilities', [EditorAiController::class, 'capabilities'])
            ->name('capabilities');

        Route::post('preview', [EditorAiController::class, 'preview'])
            ->middleware('throttle:120,1')
            ->name('preview');

        Route::post('correct', [EditorAiController::class, 'correct'])
            ->middleware('throttle:20,1')
            ->name('correct');

        Route::post('generate', [EditorAiController::class, 'generate'])
            ->middleware('throttle:10,1')
            ->name('generate');

        Route::post('image-prompt', [EditorAiController::class, 'imagePrompt'])
            ->middleware('throttle:15,1')
            ->name('image-prompt');

        Route::post('image', [EditorAiController::class, 'generateImage'])
            ->middleware('throttle:5,1')
            ->name('image');
    });

/*
|--------------------------------------------------------------------------
| 3. Seller workspace
|--------------------------------------------------------------------------
|
| Its own top-level URL space, so the first path segment names the surface.
| Guarded by the selling permission, not by the role name — a role check would
| drift from the permission matrix, a permission check cannot.
|
*/

Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::CreateProduct->value])
    ->prefix('seller')
    ->name('seller.')
    ->group(function () {
        Route::livewire('dashboard', 'pages::seller.dashboard')->name('dashboard');
        Route::livewire('sales', 'pages::seller.sales')
            ->middleware('permission:'.AccountPermission::ViewOwnSales->value)
            ->name('sales');

        /*
         * The listing pipeline: a shelf of the account's own products, a blank
         * editor, and the same editor around a listing. `products/new` is
         * registered before `products/{product:slug}` because matching follows
         * registration order — a listing slugged "new" would otherwise open the
         * blank form instead of its own editor.
         *
         * The edit route is param-bound, so the guard sweeps cannot reach it;
         * ownership is enforced inside the component, which answers 404 rather
         * than 403 to a stranger's slug.
         */
        Route::livewire('products', 'pages::seller.products')->name('products');
        Route::livewire('products/new', 'pages::seller.product-edit')->name('products.create');
        Route::livewire('products/{product:slug}', 'pages::seller.product-edit')->name('products.edit');
    });

/*
|--------------------------------------------------------------------------
| 4. Admin workspace
|--------------------------------------------------------------------------
|
| Platform administration, one page per managed surface. The group requires
| `admin:access`; a page that is narrower than the panel declares that
| permission as additional middleware, so holding the panel key is not the
| same as holding every lock behind the door.
|
| The authoring screens — posts, comments, taxonomy — are the exceptions, and
| deliberately so. They moved here from `/dashboard` because authoring is a
| staff act on this platform, and what limits them is not a permission but
| ownership: each component lists and edits only the signed-in account's own
| posts and comments. `feed`, `dev-lab` and `ideas` carry no narrower lock
| either.
|
| The Developer Lab and Ideas consoles are platform-wide oversight: they list
| every account's snippets, runs, ideas and plans, and the lab console may remove
| a snippet. Neither may edit. An idea or a plan is somebody's thinking, and
| rewriting it is not what an operator key buys, so the authoring surfaces stay
| inside the modules' own workspaces. They carry only `admin:access` because
| there is nothing narrower to divide — and unlike feed moderation they are
| offered as destinations in the admin rail, because they have work in them.
|
*/

Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::AccessAdminPanel->value])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::livewire('dashboard', 'pages::admin.dashboard')->name('dashboard');
        Route::livewire('posts', 'pages::admin.posts.index')->name('posts.index');
        Route::livewire('posts/create', 'pages::admin.posts.edit')->name('posts.create');
        Route::livewire('posts/{post:id}/edit', 'pages::admin.posts.edit')->name('posts.edit');
        Route::livewire('comments', 'pages::admin.comments')->name('comments');
        Route::livewire('taxonomy', 'pages::admin.taxonomy')->name('taxonomy');
        Route::livewire('feed', 'pages::admin.feed')
            ->middleware('permission:'.AccountPermission::ModerateComments->value)
            ->name('feed');
        Route::livewire('users', 'pages::admin.users')
            ->middleware('permission:'.AccountPermission::ManageUsers->value)
            ->name('users');
        Route::livewire('users/create', 'pages::admin.users.edit')
            ->middleware('permission:'.AccountPermission::ManageUsers->value)
            ->name('users.create');
        Route::livewire('users/{user:id}/edit', 'pages::admin.users.edit')
            ->middleware('permission:'.AccountPermission::ManageUsers->value)
            ->name('users.edit');
        Route::livewire('marketplace', 'pages::admin.marketplace')
            ->middleware('permission:'.AccountPermission::ReviewProducts->value)
            ->name('marketplace');
        Route::livewire('payment', 'pages::admin.payment')
            ->middleware('permission:'.AccountPermission::ConfigurePayments->value)
            ->name('payment');
        Route::livewire('orders', 'pages::admin.orders')
            ->middleware('permission:'.AccountPermission::ViewAnySales->value)
            ->name('orders');
        /*
         * The payment ledger reads the same book as the orders console — every
         * sale on the platform — so it borrows `sale:view-any` rather than
         * minting a second key for it. It shows nothing that moves money: the
         * capture and refund controls stay on the order that owes them.
         */
        Route::livewire('payments', 'pages::admin.payments')
            ->middleware('permission:'.AccountPermission::ViewAnySales->value)
            ->name('payments');
        Route::livewire('about', 'pages::admin.about')
            ->middleware('permission:'.AccountPermission::ManageAboutContent->value)
            ->name('about');
        Route::livewire('ai-hub', 'pages::admin.ai-hub')
            ->middleware('permission:'.AccountPermission::ManageAiHub->value)
            ->name('ai-hub');
        Route::livewire('dev-lab', 'pages::admin.dev-lab')->name('dev-lab');
        Route::livewire('ideas', 'pages::admin.ideas')->name('ideas');
    });

/*
|--------------------------------------------------------------------------
| 5. Backward-compatible redirects
|--------------------------------------------------------------------------
|
| The role dashboards moved from nested paths to role-owned top-level spaces.
| These redirects keep existing bookmarks, external links, and any in-flight
| email working. 301 rather than 302 because the move is permanent — a search
| engine or browser is correct to cache it.
|
| The `team.dashboard` name is preserved as a redirect to `/dashboard`. It was
| the starter kit's team-scoped dashboard; with the team feature removed there
| is no team to scope by, but the name is still referenced in documentation and
| may be bookmarked, so it resolves rather than 404s.
|
| The author screens are redirected the same way, and they keep their old route
| *names* as well as their old paths for the same reason: a `route()` call that
| outlives the move, or an `url.intended` written by the permission middleware,
| should resolve rather than throw. Two of them are closures because those
| pages carry their filters in the query string — `?status=draft` and `?q=` are
| the page's state, and `Route::redirect()` would drop them. `{id}` is a scalar,
| not a bound model: a public route that resolves a post would answer 404 for a
| missing one and 301 for an existing one, which tells a guest which post IDs
| exist.
|
*/

Route::redirect('dashboard/seller', '/seller/dashboard', 301)->name('dashboard.seller.redirect');
Route::redirect('dashboard/admin', '/admin/dashboard', 301)->name('dashboard.admin.redirect');

Route::get('dashboard/posts', fn (Request $request) => redirect()->to(route('admin.posts.index', $request->query(), false), 301))
    ->name('dashboard.posts.index');
Route::redirect('dashboard/posts/create', '/admin/posts/create', 301)->name('dashboard.posts.create');
Route::get('dashboard/posts/{id}/edit', fn (int $id) => redirect()->to(route('admin.posts.edit', ['post' => $id]), 301))
    ->whereNumber('id')
    ->name('dashboard.posts.edit');
Route::get('dashboard/comments', fn (Request $request) => redirect()->to(route('admin.comments', $request->query(), false), 301))
    ->name('dashboard.comments');
Route::redirect('dashboard/taxonomy', '/admin/taxonomy', 301)->name('dashboard.taxonomy');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('teams/dashboard', '/dashboard', 301)->name('team.dashboard');
});

/*
| Authentication routes (login, register, password reset, two-factor, passkeys)
| are registered by Fortify. Login, registration, and the forgot-password
| request are modal-first: their GET routes redirect to the flagged home page
| (`?signin` / `?signup` / `?forgot`) where the `x-auth.modals` dialogs open,
| so no standalone auth pages exist for them. The four mid-flow screens remain
| real pages under resources/views/pages/auth. Account settings and the
| passkey discovery document live in settings.php.
*/

require __DIR__.'/settings.php';
