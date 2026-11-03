<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| AI Hub curated shelves
|--------------------------------------------------------------------------
|
| The hub's own Developer Lab and Ideas shelves share the public `/ai-hub`
| prefix but are staff pages: they gate on `ai-hub:manage`. Pinned here: the
| gate, the honest placeholder copy managers see, and the removal of the
| shelf routes that were once declared with no backing component and 500-ed
| on every request.
|
*/

test('the curated shelves redirect guests to sign in', function () {
    $this->get('/ai-hub/dev-lab')->assertRedirect();
    $this->get('/ai-hub/ideas')->assertRedirect();
});

test('the curated shelves are refused to accounts without ai-hub:manage', function () {
    $this->actingAs(User::factory()->create())
        ->get('/ai-hub/dev-lab')
        ->assertForbidden();

    $this->actingAs(User::factory()->seller()->create())
        ->get('/ai-hub/ideas')
        ->assertForbidden();
});

test('an admin sees the honest placeholders for both shelves', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/ai-hub/dev-lab')
        ->assertOk()
        ->assertSee('Not yet available', escape: false);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/ai-hub/ideas')
        ->assertOk()
        ->assertSee('Not yet available', escape: false);
});

test('the never-built shelf routes are gone rather than erroring', function () {
    foreach (['/ai-hub/posts', '/ai-hub/comments', '/ai-hub/dev-lab/retro-llm', '/ai-hub/ideas/ship-an-agent'] as $path) {
        $this->get($path)->assertNotFound();
    }
});
