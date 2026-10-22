<?php

use App\Actions\DevLab\SaveLabSnippet;
use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use App\Models\LabExecution;
use App\Models\LabSnippet;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Snippets: the publishable half of the Developer Lab
|--------------------------------------------------------------------------
|
| Two rules carry the weight here, and both are pinned at more than one level.
|
| The first is the publish pair. A snippet is readable by a stranger only when
| `visibility` says public *and* `published_at` is set, and the pair is written by
| one action. So the tests ask what happens to a row with one half and none of the
| other: it stays invisible, on the gallery and at its own URL alike.
|
| The second is that stored code is data. The public page prints it escaped, so a
| snippet whose body is a script tag shows the characters rather than running them,
| and the only place it becomes executable is a frame the visitor opened on purpose,
| under an opaque origin. That is asserted by reading the rendered HTML, not by
| trusting a template to remember `{{ }}`.
|
*/

test('the form creates a snippet and derives its address from the title', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dev-lab.snippet')
        ->set('title', 'Debounce a callback')
        ->set('language', LabLanguage::Javascript->value)
        ->set('code', 'const debounce = (fn, ms) => {}')
        ->call('save')
        ->assertHasNoErrors();

    $snippet = LabSnippet::query()->first();

    expect($snippet->user_id)->toBe($user->id)
        ->and($snippet->slug)->toBe('debounce-a-callback')
        ->and($snippet->language)->toBe(LabLanguage::Javascript)
        ->and($snippet->visibility)->toBe(ContentVisibility::Private)
        ->and($snippet->published_at)->toBeNull();
});

test('a snippet may not be named after one of the lab’s own screens', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.snippet')
        ->set('title', 'Whatever')
        ->set('code', 'body { color: red; }')
        ->set('slug', 'sandbox')
        ->call('save')
        ->assertHasErrors(['slug']);

    expect(LabSnippet::query()->count())->toBe(0);
});

test('the slug is unique and a hand-written clash is refused', function () {
    $existing = LabSnippet::factory()->create(['slug' => 'taken-address']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.snippet')
        ->set('title', 'Clash')
        ->set('code', '<p>hi</p>')
        ->set('slug', $existing->slug)
        ->call('save')
        ->assertHasErrors(['slug']);
});

test('a public flag with no publish timestamp keeps the snippet off the gallery', function () {
    $snippet = LabSnippet::factory()->unpublishedPublic()->create(['title' => 'Almost public']);

    expect($snippet->isPublished())->toBeFalse()
        ->and($snippet->isVisibleTo(null))->toBeFalse();

    $this->get('/dev-lab/gallery')->assertDontSee('Almost public');

    $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))->assertNotFound();
});

test('a published snippet is readable by anyone and listed in the gallery', function () {
    $snippet = LabSnippet::factory()->published()->create(['title' => 'Widely readable']);

    $this->get('/dev-lab/gallery')->assertSee('Widely readable');

    $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertOk()
        ->assertSee('Widely readable');
});

test('a private snippet has no public page for a stranger but does for its author', function () {
    $snippet = LabSnippet::factory()->create(['title' => 'Still drafting']);

    $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertNotFound();

    $this->actingAs($snippet->user)
        ->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertOk();
});

test('the public page escapes stored code instead of printing it as markup', function () {
    $snippet = LabSnippet::factory()->published()->create([
        'title' => 'Looks like a script',
        'code' => '<script>alert(document.cookie)</script>',
    ]);

    $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(document.cookie)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(document.cookie)</script>', false);
});

test('the gallery offers no preview for php and the snippet page says why', function () {
    $snippet = LabSnippet::factory()->php()->published()->create(['title' => 'Some Laravel helper']);

    $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertOk()
        ->assertSee('There is no Run button for PHP')
        ->assertDontSee('sandbox="allow-scripts"', false);
});

test('a runnable snippet’s page declares a sandboxed frame with no origin grant', function () {
    $snippet = LabSnippet::factory()->published()->create(['title' => 'A grid in css']);

    $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))
        ->assertOk()
        ->assertSee('sandbox="allow-scripts"', false)
        ->assertDontSee('allow-same-origin', false);
});

test('the view counter moves once per session, not once per request', function () {
    $snippet = LabSnippet::factory()->published()->create();

    $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))->assertOk();
    $this->get(route('dev-lab.snippets.show', ['snippet' => $snippet->slug]))->assertOk();

    expect($snippet->fresh()->view_count)->toBe(1);
});

test('publishing from the list sets the pair, and unpublishing takes it back', function () {
    $snippet = LabSnippet::factory()->create(['code' => '<p>ready</p>']);

    Livewire::actingAs($snippet->user)
        ->test('pages::dev-lab.snippets')
        ->call('publish', $snippet->id);

    expect($snippet->fresh()->isPublished())->toBeTrue();

    Livewire::actingAs($snippet->user)
        ->test('pages::dev-lab.snippets')
        ->call('unpublish', $snippet->id);

    $after = $snippet->fresh();

    expect($after->visibility)->toBe(ContentVisibility::Private);
});

test('publishing is refused when there is no code to read', function () {
    $user = User::factory()->create();

    $snippet = app(SaveLabSnippet::class)->handle($user, [
        'title' => 'Empty box',
        'language' => LabLanguage::Html,
        'code' => '<p>something</p>',
    ]);

    // Empty it through the same action that guards the pair, then try to publish.
    app(SaveLabSnippet::class)->handle($user, ['code' => '   '], $snippet);

    expect(fn () => app(SaveLabSnippet::class)->handle($user, [
        'visibility' => ContentVisibility::Public,
    ], $snippet->fresh()))->toThrow(InvalidArgumentException::class);

    expect($snippet->fresh()->isPublished())->toBeFalse();
});

test('one account cannot change another’s snippet through the list screen', function () {
    $snippet = LabSnippet::factory()->create();

    // A 404 and not a 403: the lookup is scoped to the signed-in account, so the
    // response does not confirm that the id names a real row.
    Livewire::actingAs(User::factory()->create())
        ->test('pages::dev-lab.snippets')
        ->call('publish', $snippet->id)
        ->assertNotFound();

    expect($snippet->fresh()->isPublished())->toBeFalse();
});

test('the edit screen loads only its owner’s snippet', function () {
    $snippet = LabSnippet::factory()->create(['title' => 'Mine to edit']);

    Livewire::actingAs($snippet->user)
        ->test('pages::dev-lab.snippet', ['snippet' => $snippet])
        ->assertSet('title', 'Mine to edit');

    $this->actingAs(User::factory()->create())
        ->get(route('dev-lab.snippets.edit', ['snippet' => $snippet->slug]))
        ->assertNotFound();
});

test('deleting a snippet leaves its run history standing', function () {
    $user = User::factory()->create();

    $snippet = LabSnippet::factory()->create(['user_id' => $user->id, 'title' => 'Brief life']);

    LabExecution::factory()->create([
        'user_id' => $user->id,
        'label' => 'Brief life',
    ]);

    Livewire::actingAs($user)
        ->test('pages::dev-lab.snippets')
        ->call('destroy', $snippet->id);

    expect(LabSnippet::query()->count())->toBe(0)
        ->and(LabExecution::query()->count())->toBe(1);
});

test('the gallery filters by language and only ever lists published rows', function () {
    $css = LabSnippet::factory()->language(LabLanguage::Css)->published()->create(['title' => 'A css one']);
    $php = LabSnippet::factory()->php()->published()->create(['title' => 'A php one']);
    $hidden = LabSnippet::factory()->create(['title' => 'A private one']);

    $page = $this->get('/dev-lab/gallery?lang=css');

    $page->assertSee('A css one')
        ->assertDontSee('A php one')
        ->assertDontSee('A private one');

    expect($css->isPublished())->toBeTrue()
        ->and($php->isPublished())->toBeTrue()
        ->and($hidden->isPublished())->toBeFalse();
});
