<?php

use App\Models\User;
use App\Support\HttpErrorCatalog;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HTTP error pages
|--------------------------------------------------------------------------
|
| The six statuses a visitor can meaningfully meet render through one
| reusable component (`x-app.errors.page`), one dynamic route
| (`/errors/{code}`), and the framework's own `errors.{status}` view
| discovery — so a real `abort()` anywhere in the app shows the same styled
| page the preview route shows. These tests pin both paths, the honesty of
| the status codes, and the boundary that JSON requests never receive the
| HTML page.
|
*/

test('every supported status renders its own page under the dynamic route', function () {
    foreach (HttpErrorCatalog::all() as $code => $copy) {
        $response = $this->get(route('errors.show', ['code' => $code]));

        // The page answers with its real status, not a cosmetic 200: a
        // monitoring probe pointed at /errors/503 must read a 503.
        $response->assertStatus($code)
            ->assertSee((string) $code, false)
            ->assertSee($copy['title'], false)
            ->assertSee($copy['detail'], false)
            ->assertSee('data-test="error-home-link"', false)
            ->assertSee('href="'.route('home').'"', false);
    }
});

test('an unsupported code falls through to the 404 page', function () {
    // The route pattern refuses the match, and the 404 that answers is the
    // styled one — the truthful response to "show me error 418".
    $this->get('/errors/418')
        ->assertNotFound()
        ->assertSee(HttpErrorCatalog::describe(404)['title'], false);
});

test('a real 404 renders the styled error page', function () {
    $this->get('/definitely-not-a-real-page')
        ->assertNotFound()
        ->assertSee(HttpErrorCatalog::describe(404)['title'], false)
        ->assertSee('data-test="error-home-link"', false);
});

test('a real 403 renders the styled error page', function () {
    // A signed-in account without the permission is the only way to meet a
    // real 403 — a guest is bounced to sign in before any guard runs.
    $this->actingAs(User::factory()->create())
        ->get('/admin/about')
        ->assertForbidden()
        ->assertSee(HttpErrorCatalog::describe(403)['title'], false);
});

test('an uncaught exception renders the styled 500 page outside debug mode', function () {
    config(['app.debug' => false]);

    // A throwaway route is the only honest way to produce a real uncaught
    // exception; it exists for this test alone and matches nothing else.
    Route::get('testing/boom', fn (): never => throw new RuntimeException('kaboom'));

    $this->get('/testing/boom')
        ->assertStatus(500)
        ->assertSee(HttpErrorCatalog::describe(500)['title'], false);
});

test('json requests keep receiving json errors', function () {
    // `shouldRenderJsonWhen` scopes HTML error pages to browser requests;
    // a machine client asking for a missing route must never get markup.
    $response = $this->getJson('/definitely-not-a-real-page');

    $response->assertNotFound();

    expect($response->headers->get('content-type'))
        ->toContain('application/json');
});
