<?php

use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The three controls over an author's own snippet library
|--------------------------------------------------------------------------
|
| Language, visibility and a search box, each arriving from the address bar as
| `?lang=`, `?visibility=` and `?q=`. Two things are worth pinning here rather than
| on the public gallery: the bands must partition the account's own rows — a snippet
| is either behind the sign-in or it is not, and no row belongs in both lists — and
| a hand-typed value must stay inside the owner's pile.
|
| The workspace offers every language in a select an author can only reach by
| clicking, so an unparseable `?lang=` is ignored rather than treated as a filter.
| That is a different answer from the public gallery's, and deliberate: the gallery
| prints whatever the URL said and therefore has to narrow to what it printed.
|
*/

test('the visibility bands split the account’s own snippets and nothing else', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabSnippet::factory()->published()->create(['user_id' => $author->id, 'title' => 'Shown to strangers']);
    LabSnippet::factory()->create(['user_id' => $author->id, 'title' => 'Kept behind the sign-in']);

    $component = Livewire::actingAs($author)->test('pages::dev-lab.snippets');

    $component->set('visibility', 'public')
        ->assertSee('Shown to strangers')
        ->assertDontSee('Kept behind the sign-in');

    $component->set('visibility', 'private')
        ->assertSee('Kept behind the sign-in')
        ->assertDontSee('Shown to strangers');

    $component->set('visibility', 'all')
        ->assertSee('Shown to strangers')
        ->assertSee('Kept behind the sign-in');
});

test('a visibility nobody defined leaves the whole library in view', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabSnippet::factory()->published()->create(['user_id' => $author->id, 'title' => 'Shown to strangers']);
    LabSnippet::factory()->create(['user_id' => $author->id, 'title' => 'Kept behind the sign-in']);

    Livewire::actingAs($author)->test('pages::dev-lab.snippets')
        ->set('visibility', 'unlisted')
        ->assertSee('Shown to strangers')
        ->assertSee('Kept behind the sign-in');
});

test('the language filter narrows to the code actually written in it', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabSnippet::factory()->language(LabLanguage::Css)->create(['user_id' => $author->id, 'title' => 'A focus ring worth keeping']);
    LabSnippet::factory()->language(LabLanguage::Javascript)->create(['user_id' => $author->id, 'title' => 'A debounce helper']);
    LabSnippet::factory()->php()->create(['user_id' => $author->id, 'title' => 'A temperature conversion']);

    $component = Livewire::actingAs($author)->test('pages::dev-lab.snippets');

    $component->set('language', 'css')
        ->assertSee('A focus ring worth keeping')
        ->assertDontSee('A debounce helper')
        ->assertDontSee('A temperature conversion');

    $component->set('language', 'php')
        ->assertSee('A temperature conversion')
        ->assertDontSee('A focus ring worth keeping');
});

test('a language the lab does not know is ignored rather than emptying the shelf', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabSnippet::factory()->create(['user_id' => $author->id, 'title' => 'A card worth keeping']);

    // The control over this list is a select holding every language the lab can store,
    // so a value outside that set was typed by hand. The library is the author's own
    // pile; widening it back to everything is the harmless answer.
    Livewire::actingAs($author)->test('pages::dev-lab.snippets')
        ->set('language', 'perl')
        ->assertSee('A card worth keeping');
});

test('the three controls compose and each one still applies after the others move', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabSnippet::factory()->published()->language(LabLanguage::Css)
        ->create(['user_id' => $author->id, 'title' => 'Css public zsq', 'code' => '/* zsq */']);
    LabSnippet::factory()->language(LabLanguage::Css)
        ->create(['user_id' => $author->id, 'title' => 'Css private zsq', 'code' => '/* zsq */']);
    LabSnippet::factory()->published()->language(LabLanguage::Javascript)
        ->create(['user_id' => $author->id, 'title' => 'Javascript public zsq', 'code' => '// zsq']);

    Livewire::actingAs($author)->test('pages::dev-lab.snippets')
        ->set('search', 'zsq')
        ->assertSee('Css public zsq')
        ->assertSee('Css private zsq')
        ->assertSee('Javascript public zsq')
        ->set('language', 'css')
        ->assertDontSee('Javascript public zsq')
        ->set('visibility', 'public')
        ->assertSee('Css public zsq')
        ->assertDontSee('Css private zsq');
});

test('clearing the filters returns all three controls to their defaults at once', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabSnippet::factory()->published()->create(['user_id' => $author->id, 'title' => 'A card worth keeping']);

    Livewire::actingAs($author)->test('pages::dev-lab.snippets')
        ->set('search', 'card')
        ->set('language', LabLanguage::Css->value)
        ->set('visibility', ContentVisibility::Public->value)
        ->assertDontSee('A card worth keeping')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('language', '')
        ->assertSet('visibility', 'all')
        ->assertSee('A card worth keeping');
});

test('turning the page does not survive any of the three controls', function () {
    $author = User::factory()->create(['handle' => 'shelf']);
    $perPage = (int) config('dev_lab.per_page.workspace');

    LabSnippet::factory()->count($perPage + 1)->create(['user_id' => $author->id]);

    Livewire::actingAs($author)
        ->test('pages::dev-lab.snippets')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('language', 'css')
        ->assertSet('paginators.page', 1)
        ->call('setPage', 2)
        ->set('visibility', 'public')
        ->assertSet('paginators.page', 1)
        ->call('setPage', 2)
        ->set('search', 'snippet')
        ->assertSet('paginators.page', 1);
});

test('no combination of the controls reaches another account snippets', function () {
    $author = User::factory()->create(['handle' => 'shelf']);
    $stranger = User::factory()->create(['handle' => 'other']);

    LabSnippet::factory()->published()->create(['user_id' => $author->id, 'title' => 'My own public snippet']);
    LabSnippet::factory()->published()->create(['user_id' => $stranger->id, 'title' => 'A stranger public snippet']);
    LabSnippet::factory()->php()->create(['user_id' => $stranger->id, 'title' => 'A stranger php snippet']);

    foreach ([['visibility', 'all'], ['visibility', 'public'], ['language', 'php'], ['search', 'stranger']] as [$control, $value]) {
        Livewire::actingAs($author)->test('pages::dev-lab.snippets')
            ->set($control, $value)
            ->assertDontSee('A stranger public snippet')
            ->assertDontSee('A stranger php snippet');
    }

    Livewire::actingAs($author)->test('pages::dev-lab.snippets')
        ->set('search', 'own')
        ->assertDontSee('A stranger public snippet')
        ->assertSee('My own public snippet');
});
