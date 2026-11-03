<?php

use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Developer Lab landing page
|--------------------------------------------------------------------------
|
| Three facts are worth pinning about this page. It is assembled from a list of
| sections, so a declared panel must reach the output. One section changes with the
| audience, so a guest and an account cannot be shown the same promise. And the
| page no longer describes a plan: the sentence "not yet available" was true of the
| lab for as long as it was a specification, and a landing page that still says it
| after the sandbox exists is turning a reader away from a feature they can use.
|
| The fourth case is the one that would rot quietly. The page asserts a security
| boundary in plain English, so the words are pinned here as well as in the tests
| that check the frame's attributes — copy that stops matching the implementation is
| still a bug, just one no browser error reports.
|
*/

test('the landing page renders every section it declares', function () {
    $this->get('/dev-lab')
        ->assertOk()
        ->assertSee('What the lab does')
        ->assertSee('What the lab will not do')
        ->assertSee('Run composes the three panes')
        ->assertSee('docs/lab.md');
});

test('the capability groups reach the page in the order the workflow has them', function () {
    $html = $this->get('/dev-lab')->assertOk()->getContent();

    $groups = ['Write', 'Run and inspect', 'Keep and publish'];

    foreach ($groups as $group) {
        expect($html)->toContain($group);
    }

    // The list in the page component is the order; nothing sorts it later, so a
    // reordered array must be visible in the rendered output.
    $positions = array_map(fn (string $group) => strpos($html, $group), $groups);

    expect($positions)->toBe(array_values($positions));
});

test('the landing page no longer says the module is unavailable', function () {
    $this->get('/dev-lab')
        ->assertOk()
        ->assertDontSee('Not yet available')
        ->assertDontSee('the module is not built')
        ->assertSee('Runs in your browser');
});

test('a guest and an account are told different things by the access panel', function () {
    $this->get('/dev-lab')
        ->assertSee('Read the gallery first')
        ->assertSee('Open the gallery')
        ->assertDontSee('The editor is open');

    // Every role holds `dev-lab:use`, so an authenticated reader is in the branch
    // that sends them to the sandbox rather than to the gallery.
    $this->actingAs(User::factory()->create())
        ->get('/dev-lab')
        ->assertOk()
        ->assertSee('The editor is open')
        ->assertSee('Open the sandbox')
        ->assertSee(route('dev-lab.sandbox'))
        ->assertDontSee('Read the gallery first');
});

test('the page states that PHP is stored rather than executed', function () {
    $this->get('/dev-lab')
        ->assertOk()
        ->assertSee('There is no server-side runner')
        ->assertSee('PHP')
        ->assertSee('cannot read this site', false);
});

test('the landing page shows published work and hides the strip when there is none', function () {
    // No rows yet, so the door to the gallery is the button in the access panel and
    // nothing claims a featured list.
    $this->get('/dev-lab')->assertOk()->assertDontSee('Published this week and before');

    $snippet = LabSnippet::factory()->published()->create([
        'title' => 'Debounce a callback',
        'language' => LabLanguage::Javascript,
    ]);
    LabSnippet::factory()->create(['title' => 'Still private to its author']);

    $this->get('/dev-lab')
        ->assertOk()
        ->assertSee('Published this week and before')
        ->assertSee($snippet->title)
        ->assertSee($snippet->slug)
        ->assertDontSee('Still private to its author');
});
