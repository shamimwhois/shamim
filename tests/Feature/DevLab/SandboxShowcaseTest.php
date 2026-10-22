<?php

use App\Models\LabExecution;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The landing page's showcase frame
|--------------------------------------------------------------------------
|
| The Developer Lab landing is public, and it now runs code: three panes held in
| `config('dev_lab.showcase')` composed into a frame when a visitor presses the
| button. That makes it the one place on a guest-visible page where this application
| puts a script into a browser, so the tests aim at exactly the two things that make
| it safe rather than novel.
|
| The frame carries the same attribute pair the authenticated sandbox does, because a
| weaker copy of the boundary would still be a copy of the boundary. And nothing runs
| until asked: a page that executes on load cannot be described as a demonstration.
|
| Whether the example *renders* is a browser question with no headless answer in this
| suite, same as the rest of the sandbox tests. What is pinned here is the declaration,
| the escaping, and the size of the thing being shipped.
|
*/

test('the landing mounts the sandbox factory over the site’s own example', function () {
    $this->get('/dev-lab')
        ->assertOk()
        ->assertSee('data-test="dev-lab-showcase"', false)
        ->assertSee('labSandbox(', false)
        ->assertSee('runStored()', false)
        ->assertSee('Run the example')
        ->assertSee('Read the three panes');
});

test('the showcase frame is sandboxed to scripts and granted no origin', function () {
    $this->get('/dev-lab')
        ->assertOk()
        ->assertSee('sandbox="allow-scripts"', false)
        ->assertDontSee('allow-same-origin', false)
        ->assertDontSee('allow-modals', false)
        ->assertDontSee('allow-forms', false);
});

test('the example is printed as escaped text rather than as markup', function () {
    $html = config('dev_lab.showcase.html');

    expect($html)->toStartWith('<main');

    // The reader sees the source of the demo the way they see the source of any
    // published snippet: as characters inside a `<pre>`, never as nodes of the page.
    $this->get('/dev-lab')
        ->assertOk()
        ->assertDontSee($html, false)
        ->assertSee(e($html), false);
});

test('no pane of the example is larger than the sandbox stores', function () {
    $limit = (int) config('dev_lab.max_code_bytes');

    foreach (config('dev_lab.showcase') as $pane => $code) {
        expect(strlen($code))->toBeLessThanOrEqual($limit, "The {$pane} pane outgrew the workspace ceiling.");
    }

    // The frame composes all three plus a document around them, so the shipped
    // example has to stay comfortably inside one pane rather than fill it.
    expect(strlen(implode('', config('dev_lab.showcase'))))->toBeLessThan($limit / 4);
});

test('opening the landing runs nothing and records nothing', function () {
    $this->get('/dev-lab')->assertOk();

    expect(LabExecution::query()->count())->toBe(0);
});

test('the showcase is public while the editor behind it is not', function () {
    // A guest is offered the demo and sent to the gallery for the rest; an account
    // is offered the same demo *and* the editor. The panel is not a back door into
    // the part that needs `dev-lab:use`.
    $this->get('/dev-lab')
        ->assertOk()
        ->assertSee('Run the example')
        ->assertSee('Read the gallery first');

    $this->actingAs(User::factory()->create())
        ->get('/dev-lab')
        ->assertOk()
        ->assertSee('Run the example')
        ->assertSee('The editor is open');
});

test('the panel says what the frame cannot do before the button is pressed', function () {
    $this->get('/dev-lab')
        ->assertOk()
        ->assertSee('The frame has no origin of its own')
        ->assertSee('It can loop forever')
        ->assertSee('alert()');
});
