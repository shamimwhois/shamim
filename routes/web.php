<?php

use App\Enums\AccountPermission;
use App\Http\Controllers\Editor\EditorAiController;
use App\Http\Controllers\ErrorPageController;
use App\Http\Controllers\FeedAttachmentController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Support\HttpErrorCatalog;
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
| The marketplace is a storefront, not a workspace: anyone may read it, the way
| a public package index is readable. Selling stays permission-gated, which is
| what group 3 guards. The category route renders the same component as the
| index with the filter pre-seeded, so there is one definition of "the
| catalogue" rather than two.
*/

Route::livewire('marketplace', 'pages::marketplace.index')->name('marketplace.index');
Route::livewire('marketplace/categories/{category:slug}', 'pages::marketplace.index')
    ->name('marketplace.category');
Route::livewire('marketplace/{product:slug}', 'pages::marketplace.show')
    ->name('marketplace.show');

Route::livewire('communities', 'pages::communities.index')->name('communities.index');

/*
| The Developer Lab and Ideas landing pages. Neither module is built yet, so
| each page is an honest placeholder that states what is planned and what
| already exists.
|
| They are public rather than workspace pages: a reader may see what the module
| is for without signing in, the same contract as the marketplace and the hub.
| The URL space stays claimed — these are top-level names nothing else may
| take — and the workspace entries in `DashboardNavigation` still point here
| for an account that holds `dev-lab:use` / `ideas:use`. The consoles an
| operator would use are in group 4.
*/

Route::livewire('dev-lab', 'pages::dev-lab.index')->name('dev-lab.index');

Route::livewire('ideas', 'pages::ideas.index')->name('ideas.index');

/*
| The AI Knowledge Hub's reading surfaces share one URL space and one name
| prefix. The hub's own curated Developer Lab and Ideas shelves also live
| here, but they are staff pages: they gate on `ai-hub:manage`, because their
| content models are not built yet. An honest placeholder beats a public 500 —
| the same contract the unbuilt module consoles in group 4 follow.
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
| The account's own workspace. `permission:` is the account-level guard from
| {@see AccountPermission}, enforced on the route as well as in the component,
| so an unauthorised account never reaches the page at all — a 403 before the
| component boots, rather than a rendered shell with a hidden button.
|
*/

Route::middleware(['auth', 'verified'])->prefix('dashboard')->name('dashboard.')->group(function () {
    /*
     * The user role's home and shared author workspace. Post and comment pages
     * are available to every account role; taxonomy has its own permission guard.
     */
    Route::livewire('/', 'pages::dashboard.index')->name('index');

    Route::livewire('posts', 'pages::dashboard.posts.index')->name('posts.index');
    Route::livewire('posts/create', 'pages::dashboard.posts.edit')->name('posts.create');
    Route::livewire('posts/{post:id}/edit', 'pages::dashboard.posts.edit')->name('posts.edit');
    Route::livewire('comments', 'pages::dashboard.comments')->name('comments');
    Route::livewire('taxonomy', 'pages::dashboard.taxonomy')
        ->middleware('permission:'.AccountPermission::ManageTaxonomy->value)
        ->name('taxonomy');
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
    });

/*
|--------------------------------------------------------------------------
| 4. Admin workspace
|--------------------------------------------------------------------------
|
| Platform administration, one page per managed surface. The group requires
| `admin:access`; each page then declares the narrower permission that actually
| governs it, so holding the panel key is not the same as holding every lock
| behind the door.
|
| The Developer Lab and Ideas consoles hold the line their public landing
| pages in group 1 state: the module is unbuilt, so this is the slot the
| console will be built into. They are placeholders rather than tools, and they
| stay out of the admin rail the way feed moderation does — reachable by URL,
| not offered as a destination until there is something to do there.
|
*/

Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::AccessAdminPanel->value])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::livewire('dashboard', 'pages::admin.dashboard')->name('dashboard');
        Route::livewire('feed', 'pages::admin.feed')
            ->middleware('permission:'.AccountPermission::ModerateComments->value)
            ->name('feed');
        Route::livewire('users', 'pages::admin.users')
            ->middleware('permission:'.AccountPermission::ManageUsers->value)
            ->name('users');
        Route::livewire('marketplace', 'pages::admin.marketplace')
            ->middleware('permission:'.AccountPermission::ReviewProducts->value)
            ->name('marketplace');
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
*/

Route::redirect('dashboard/seller', '/seller/dashboard', 301)->name('dashboard.seller.redirect');
Route::redirect('dashboard/admin', '/admin/dashboard', 301)->name('dashboard.admin.redirect');

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
