<?php

use App\Services\ProjectCatalog;

/*
|--------------------------------------------------------------------------
| Selected work dossiers and documentation
|--------------------------------------------------------------------------
|
| The Details button on the about page has to lead somewhere that answers the
| question the card raised. These tests pin the three parts of that contract:
| every card has a link, the overview renders the project and its documentation
| index, and each document is a real page rather than a modal state.
|
| The site's own project is the one that reads `docs/*.md`; the others carry
| their pages in configuration. Both paths must reach the same shape, because a
| reader cannot tell which source a page came from and should not have to.
|
*/

test('every selected work card links to its dossier', function () {
    $projects = app(ProjectCatalog::class)->all();
    $response = $this->get(route('about'))->assertOk();

    expect($projects)->not->toBeEmpty();

    foreach ($projects as $project) {
        $response
            ->assertSee(route('work.show', ['project' => $project->slug]), false)
            ->assertSeeText($project->name);
    }

    $response->assertSeeText('Details');
});

test('the project overview renders the brief, the result and every configured doc', function () {
    $this->get(route('work.show', ['project' => 'atlas-ledger']))
        ->assertOk()
        ->assertSee('data-test="project-show"', false)
        ->assertSee('Atlas Ledger')
        ->assertSee('The brief')
        ->assertSee('The result')
        ->assertSee('Documentation')
        ->assertSee('Overview')
        ->assertSee('Architecture')
        ->assertSee('Operations')
        ->assertSee('Double-entry accounting engine', false);
});

test('a documentation page renders the configured markdown in full', function () {
    $this->get(route('work.show', ['project' => 'atlas-ledger', 'doc' => 'architecture']))
        ->assertOk()
        ->assertSee('Write path')
        ->assertSee('Read model')
        ->assertSee('Invariants')
        ->assertSee('Project overview');
});

test('the site project reads the repository docs folder', function () {
    $this->get(route('work.show', ['project' => 'devfolio']))
        ->assertOk()
        ->assertSee('DevFolio')
        ->assertSee('Architecture')
        ->assertSee('Route Reference')
        ->assertSee('Documentation');

    $this->get(route('work.show', ['project' => 'devfolio', 'doc' => 'architecture']))
        ->assertOk()
        ->assertSee('The shape of the thing')
        ->assertSee('TechArticle', false);
});

test('a project or document that does not exist is a 404', function () {
    $this->get('/work/not-a-project')->assertNotFound();
    $this->get('/work/atlas-ledger/not-a-document')->assertNotFound();
});
