<?php

use App\Enums\AccountPermission;
use App\Http\Controllers\Editor\EditorAiController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public site
|--------------------------------------------------------------------------
|
| The portfolio itself: landing page, blog index, post detail, about. All
| server-rendered Livewire single-file components registered with
| Route::livewire(), so each page is reactive without a client-side router.
|
*/

Route::livewire('/', 'pages::home')->name('home');

Route::livewire('blog', 'pages::blog.index')->name('blog.index');
Route::livewire('blog/{post:slug}', 'pages::blog.show')->name('blog.show');

Route::livewire('about', 'pages::about')->name('about');

Route::livewire('feed', 'pages::feed')->name('feed.index');

/*
| Feeds and machine-readable endpoints.
*/
Route::get('feed.xml', FeedController::class)->name('feed');
Route::get('sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('robots.txt', RobotsController::class);

/*
|--------------------------------------------------------------------------
| Role dashboards
|--------------------------------------------------------------------------
|
| Three separate, role-owned URL spaces. The role is the first path segment,
| so the URL states which surface it belongs to and no role's home is nested
| inside another's:
|
|   /dashboard          user    — author workspace (posts and comments; taxonomy is permission-gated)
|   /seller/dashboard   seller  — selling workspace
|   /admin/dashboard    admin   — platform administration
|
| The shared author workspace stays on `/dashboard` because a seller and an
| admin both write posts; only their *homes* are distinct.
|
| `permission:` is the account-level guard from {@see AccountPermission}. It is
| enforced on the route as well as in the component, so an unauthorised account
| never reaches the page at all — a 403 before the component boots, rather than
| a rendered shell with a hidden button.
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
| Seller workspace. Guarded by the selling permission, not by the role name —
| a role check would drift from the permission matrix, a permission check cannot.
*/
Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::CreateProduct->value])
    ->prefix('seller')
    ->name('seller.')
    ->group(function () {
        Route::livewire('dashboard', 'pages::seller.dashboard')->name('dashboard');
    });

/*
| Admin workspace.
*/
Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::AccessAdminPanel->value])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::livewire('dashboard', 'pages::admin.dashboard')->name('dashboard');
        Route::livewire('users', 'pages::admin.users')
            ->middleware('permission:'.AccountPermission::ManageUsers->value)
            ->name('users');
    });

/*
|--------------------------------------------------------------------------
| Platform modules
|--------------------------------------------------------------------------
|
| One route per product module, each with its own top-level URL space and its
| own permission. The modules are not built yet, so each route renders a
| placeholder page that states what is planned and what already exists. The
| routes are real and the navigation points at them, so each module has a
| landing spot to be built into rather than needing a routing change later.
|
| The URL space is claimed now on purpose: `/ideas`, `/dev-lab`, `/ai-hub` and
| `/marketplace` are top-level names that must not be taken by anything else.
|
*/

Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::UseDeveloperLab->value])
    ->group(function () {
        Route::livewire('dev-lab', 'pages::dev-lab.index')->name('dev-lab.index');
    });

Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::UseAiHub->value])
    ->group(function () {
        Route::livewire('ai-hub', 'pages::ai-hub.index')->name('ai-hub.index');
    });

Route::middleware(['web'])
    ->group(function () {
        Route::livewire('marketplace', 'pages::marketplace.index')->name('marketplace.index');
        Route::livewire('marketplace/{package}', 'pages::marketplace.show')->name('marketplace.show');
    });

Route::middleware(['auth', 'verified', 'permission:'.AccountPermission::UseIdeas->value])
    ->group(function () {
        Route::livewire('ideas', 'pages::ideas.index')->name('ideas.index');
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
| Backward-compatible redirects
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
| are registered by Fortify. Their views live in resources/views/pages/auth.
*/

require __DIR__.'/settings.php';
