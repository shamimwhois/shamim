<?php

use App\Http\Controllers\FeedController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\EnsureTeamMembership;
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

/*
| Feeds and machine-readable endpoints.
*/
Route::get('feed.xml', FeedController::class)->name('feed');
Route::get('sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('robots.txt', RobotsController::class);

/*
|--------------------------------------------------------------------------
| Author dashboard
|--------------------------------------------------------------------------
|
| Deliberately NOT nested under the `{current_team}` prefix used by the
| starter kit's team routes. DevFolio is single-author: posts belong to a
| user, not a team, and forcing a team slug into every authoring URL would
| add a segment that carries no meaning. The starter kit's team routes are
| left untouched below.
|
*/

Route::middleware(['auth', 'verified'])->prefix('dashboard')->name('dashboard.')->group(function () {
    Route::livewire('/', 'pages::dashboard.index')->name('index');

    Route::livewire('posts', 'pages::dashboard.posts.index')->name('posts.index');
    Route::livewire('posts/create', 'pages::dashboard.posts.edit')->name('posts.create');
    Route::livewire('posts/{post:id}/edit', 'pages::dashboard.posts.edit')->name('posts.edit');

    Route::livewire('comments', 'pages::dashboard.comments')->name('comments');

    Route::livewire('taxonomy', 'pages::dashboard.taxonomy')->name('taxonomy');
});

/*
|--------------------------------------------------------------------------
| Starter kit routes
|--------------------------------------------------------------------------
|
| Preserved as shipped. The team-scoped dashboard remains reachable at
| /{current_team}/dashboard so the invitation and team-management flows
| continue to work unchanged.
|
*/

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('team.dashboard');
    });

/*
| Authentication routes (login, register, password reset, two-factor, passkeys)
| are registered by Fortify. Their views live in resources/views/pages/auth.
*/

require __DIR__.'/settings.php';
