<?php

use App\Actions\DevLab\CloneSnippetToWorkspace;
use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Models\LabWorkspace;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Opening a published snippet in your own sandbox
|--------------------------------------------------------------------------
|
| The snippet page is public and read-only, and it now offers one mutation: copy
| this into a workspace so the reader can edit it. Three things are worth pinning
| about that.
|
| The write lands in the pane the language names and empties the other two, because
| a frame that composed the snippet *plus* whatever was in the buffer before would be
| running something the reader never asked for. The buffer is found by a name derived
| from the title, so a second press re-opens one workspace instead of stacking copies
| on the shelf. And the permission is checked on the verb, not on the route, because
| Livewire posts to `/livewire/update` where the module's middleware never runs.
|
| PHP is the case that has no answer: there is no PHP pane, so there is nothing to
| open, and the refusal is server-side as well as in the markup that hides the button.
|
*/

test('cloning a snippet writes its code into the pane its language names', function () {
    $reader = User::factory()->create();
    $snippet = LabSnippet::factory()->published()->create([
        'title' => 'A grid that holds',
        'code' => '<div class="grid"></div>',
    ]);

    $page = Livewire::actingAs($reader)
        ->test('pages::dev-lab.snippet-show', ['snippet' => $snippet])
        ->call('openInSandbox');

    $workspace = LabWorkspace::query()->first();

    expect(LabWorkspace::query()->count())->toBe(1);

    $page->assertRedirect(route('dev-lab.sandbox', ['workspace' => $workspace->id]));

    expect($workspace->user_id)->toBe($reader->id)
        ->and($workspace->name)->toBe('From A grid that holds')
        ->and($workspace->html)->toBe('<div class="grid"></div>')
        ->and($workspace->css)->toBe('')
        ->and($workspace->js)->toBe('');
});

test('the code travels to the pane its own language names', function () {
    $reader = User::factory()->create();

    $css = LabSnippet::factory()->published()->language(LabLanguage::Css)->create();
    $script = LabSnippet::factory()->published()->language(LabLanguage::Javascript)->create();

    Livewire::actingAs($reader)->test('pages::dev-lab.snippet-show', ['snippet' => $css])->call('openInSandbox');
    Livewire::actingAs($reader)->test('pages::dev-lab.snippet-show', ['snippet' => $script])->call('openInSandbox');

    $clones = LabWorkspace::query()->get()->keyBy('name');

    expect($clones['From '.$css->title]->css)->toBe($css->code)
        ->and($clones['From '.$css->title]->js)->toBe('')
        ->and($clones['From '.$script->title]->js)->toBe($script->code)
        ->and($clones['From '.$script->title]->css)->toBe('');
});

test('pressing the button twice re-opens one workspace instead of stacking copies', function () {
    $reader = User::factory()->create();
    $snippet = LabSnippet::factory()->published()->create(['title' => 'Debounce a callback']);

    Livewire::actingAs($reader)
        ->test('pages::dev-lab.snippet-show', ['snippet' => $snippet])
        ->call('openInSandbox');

    $id = LabWorkspace::query()->value('id');

    Livewire::actingAs($reader)
        ->test('pages::dev-lab.snippet-show', ['snippet' => $snippet])
        ->call('openInSandbox')
        ->assertRedirect(route('dev-lab.sandbox', ['workspace' => $id]));

    expect(LabWorkspace::query()->count())->toBe(1)
        ->and(LabWorkspace::query()->first()->id)->toBe($id);
});

test('a workspace the reader renamed is not overwritten by the next clone', function () {
    $reader = User::factory()->create();
    $snippet = LabSnippet::factory()->published()->create(['title' => 'Sticky footer']);

    Livewire::actingAs($reader)
        ->test('pages::dev-lab.snippet-show', ['snippet' => $snippet])
        ->call('openInSandbox');

    // The lookup key is the name the action would write. A buffer filed under a name
    // of the author's choosing is theirs, and a clone opens a new one rather than
    // quietly replacing work done under another label.
    LabWorkspace::query()->update(['name' => 'My footer experiments']);

    Livewire::actingAs($reader)
        ->test('pages::dev-lab.snippet-show', ['snippet' => $snippet])
        ->call('openInSandbox');

    expect(LabWorkspace::query()->count())->toBe(2)
        ->and(LabWorkspace::query()->where('name', 'My footer experiments')->count())->toBe(1);
});

test('the page offers the button to a reader the sandbox is open to', function () {
    $snippet = LabSnippet::factory()->published()->create(['title' => 'Aspect ratio box']);

    $this->actingAs(User::factory()->create())
        ->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertOk()
        ->assertSee('Open in your sandbox')
        ->assertSee('data-test="clone-to-workspace"', false)
        ->assertSee('Copy this into a workspace of your own');
});

test('a guest is offered no button and cannot make the request', function () {
    $snippet = LabSnippet::factory()->published()->create();

    $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertOk()
        ->assertDontSee('Open in your sandbox')
        ->assertDontSee('clone-to-workspace', false);

    // The route middleware never runs on an update POST, so this asserts the guard on
    // the method itself.
    Livewire::test('pages::dev-lab.snippet-show', ['snippet' => $snippet])
        ->call('openInSandbox')
        ->assertForbidden();

    expect(LabWorkspace::query()->count())->toBe(0);
});

test('a php snippet is offered neither a run button nor a clone', function () {
    $snippet = LabSnippet::factory()->published()->php()->create(['title' => 'Convert a temperature']);

    $this->actingAs(User::factory()->create())
        ->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertOk()
        ->assertSee('There is no Run button for PHP')
        ->assertDontSee('Preview this snippet')
        ->assertDontSee('Open in your sandbox');

    expect(fn () => app(CloneSnippetToWorkspace::class)->handle(User::factory()->create(), $snippet))
        ->toThrow(InvalidArgumentException::class);

    expect(LabWorkspace::query()->count())->toBe(0);
});

test('cloning somebody else’s snippet files the buffer under the reader', function () {
    $author = User::factory()->create();
    $reader = User::factory()->create();

    $snippet = LabSnippet::factory()->published()->create([
        'user_id' => $author->id,
        'title' => 'Somebody else’s marquee',
    ]);

    Livewire::actingAs($reader)
        ->test('pages::dev-lab.snippet-show', ['snippet' => $snippet])
        ->call('openInSandbox');

    $workspace = LabWorkspace::query()->first();

    expect($workspace->user_id)->toBe($reader->id)
        ->and($workspace->isVisibleTo($reader))->toBeTrue()
        ->and($workspace->isVisibleTo($author))->toBeFalse();
});
