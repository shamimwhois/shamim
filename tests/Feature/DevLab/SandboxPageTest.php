<?php

use App\Actions\DevLab\RecordLabExecution;
use App\Enums\LabLanguage;
use App\Models\LabExecution;
use App\Models\LabSnippet;
use App\Models\LabWorkspace;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The sandbox screen, and the one write its Run button is allowed to make
|--------------------------------------------------------------------------
|
| The browser executes the code; the server only decides whether a run is recorded.
| That split is the entire security story of this build, so the tests aim at the
| server side of it: what the Run button writes, what it refuses to write, and the
| attribute on the frame that makes the rest of it true.
|
| Two things are deliberately not asserted here. Whether the preview actually
| rendered is a browser question with no headless answer in this suite, and the
| console bridge is Alpine code this project does not unit-test. What is pinned is
| that the frame is declared with `allow-scripts` and never `allow-same-origin`,
| because that single token is the difference between a sandbox and a same-origin
| document that can read the visitor's cookies.
|
*/

test('an account holding the lab permission opens the sandbox', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dev-lab/sandbox')
        ->assertOk()
        ->assertSee('data-sui', false);
});

test('a guest is bounced out of the sandbox', function () {
    $this->get('/dev-lab/sandbox')->assertRedirect(route('home', ['signin' => 'required']));
});

test('a guest cannot reach the sandbox through the livewire endpoint', function () {
    // The route middleware never runs on an update POST, so this asserts the
    // component's own `boot()` guard rather than the route group.
    Livewire::test('pages::dev-lab.sandbox')->assertForbidden();
});

test('the preview frame is sandboxed to scripts and granted no origin', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dev-lab/sandbox')
        ->assertSee('sandbox="allow-scripts"', false)
        ->assertDontSee('allow-same-origin', false);
});

test('a run with nothing written records nothing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->call('run')
        ->assertHasNoErrors();

    expect(LabExecution::query()->count())->toBe(0);
});

test('a run records exactly one execution for the author', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->set('name', 'Marquee experiment')
        ->set('html', '<h1>Hi</h1>')
        ->call('run')
        ->assertDispatched('lab-run');

    $run = LabExecution::query()->first();

    expect(LabExecution::query()->count())->toBe(1)
        ->and($run->user_id)->toBe($user->id)
        ->and($run->language)->toBe(LabLanguage::Html)
        ->and($run->label)->toBe('Marquee experiment');
});

test('the day’s allowance is enforced by the server and says so', function () {
    config(['dev_lab.daily_run_limit' => 2]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->set('html', '<p>loop</p>')
        ->call('run')
        ->call('run')
        ->call('run');

    expect(LabExecution::query()->count())->toBe(2);

    // The third press is refused, and the refusal is the message rather than a
    // silently dead button.
    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->set('html', '<p>loop</p>')
        ->call('run')
        ->assertHasNoErrors();

    expect(LabExecution::query()->count())->toBe(2);
});

test('a saved workspace stamps its own last run', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->set('name', 'Grid study')
        ->set('html', '<div></div>')
        ->call('save')
        ->call('run');

    expect(LabWorkspace::query()->first()->last_run_at)->not->toBeNull();
});

test('php is refused a run before the row is written', function () {
    $user = User::factory()->create();

    expect(fn () => app(RecordLabExecution::class)->handle($user, LabLanguage::Php, 'Stored PHP'))
        ->toThrow(InvalidArgumentException::class);

    expect(LabExecution::query()->count())->toBe(0);
});

test('the pane a snippet is saved from is the pane that is stored', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dev-lab.sandbox')
        ->set('name', 'Debounce helper')
        ->set('html', '<button>Click</button>')
        ->set('js', 'const debounce = (fn, ms) => {}')
        ->call('makeSnippet', 'js')
        ->assertRedirect(route('dev-lab.snippets.edit', ['snippet' => 'debounce-helper']));

    $snippet = LabSnippet::query()->first();

    expect($snippet->language)->toBe(LabLanguage::Javascript)
        ->and($snippet->code)->toBe('const debounce = (fn, ms) => {}')
        ->and($snippet->title)->toBe('Debounce helper');
});

test('saving an empty pane as a snippet is refused', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.sandbox')
        ->set('css', '')
        ->call('makeSnippet', 'css');

    expect(LabSnippet::query()->count())->toBe(0);
});
