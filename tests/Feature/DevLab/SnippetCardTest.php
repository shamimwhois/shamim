<?php

use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The snippet card, drawn once
|--------------------------------------------------------------------------
|
| The gallery grid and the landing's featured strip used to be two copies of the same
| row, and copies drift: one printed the line count, the other printed a "stored"
| badge, and neither was wrong. Both now render the same component, so these tests pin
| that each surface shows what it should and that the shared card, not the page, owns
| the derivation.
|
| The workspace rows and the admin console rows are deliberately left out. They carry
| the visibility state, the view count and the publish controls, which is a management
| list rather than a public card, and the component says so in its own docblock.
|
*/

test('the gallery prints one card for each published snippet', function () {
    LabSnippet::factory()->published()->count(3)->create();

    Livewire::test('pages::dev-lab.gallery')
        ->assertSeeHtml('wire:key="gallery-snippet-1"')
        ->assertSeeHtml('wire:key="gallery-snippet-2"')
        ->assertSeeHtml('wire:key="gallery-snippet-3"')
        ->assertSeeHtml('data-test="dev-lab-gallery"');
});

test('a card names the language, the line count and the account that kept it', function () {
    $author = User::factory()->create(['name' => 'Rosa Quesada']);

    $snippet = LabSnippet::factory()->published()->language(LabLanguage::Css)->create([
        'user_id' => $author->id,
        'title' => 'A card worth keeping',
    ]);

    $lines = trans_choice(':count line|:count lines', $snippet->lineCount(), ['count' => $snippet->lineCount()]);

    Livewire::test('pages::dev-lab.gallery')
        ->assertSee('A card worth keeping')
        ->assertSee('CSS')
        ->assertSee($lines)
        ->assertSee('Rosa Quesada')
        ->assertSee($snippet->published_at->translatedFormat('j M Y'));
});

test('a php card says there is no run button rather than promising one', function () {
    LabSnippet::factory()->published()->php()->create(['title' => 'Celsius to Fahrenheit']);

    Livewire::test('pages::dev-lab.gallery')
        ->assertSee('Celsius to Fahrenheit')
        ->assertSee('No run button');
});

test('a card a browser can run says nothing about a missing run button', function () {
    LabSnippet::factory()->published()->language(LabLanguage::Html)->create([
        'title' => 'A section worth keeping',
    ]);

    Livewire::test('pages::dev-lab.gallery')
        ->assertSee('A section worth keeping')
        ->assertDontSee('No run button');
});

test('the landing strip draws the same card in its compact size', function () {
    $author = User::factory()->create(['name' => 'Rosa Quesada']);

    LabSnippet::factory()->published()->php()->create([
        'user_id' => $author->id,
        'title' => 'Celsius to Fahrenheit',
    ]);

    $this->get(route('dev-lab.index'))
        ->assertSee('Published this week and before')
        ->assertSee('Celsius to Fahrenheit')
        ->assertSee('stored')
        ->assertSee('Rosa Quesada');
});

test('the landing no longer resolves a language badge for its own template', function () {
    $component = Livewire::test('pages::dev-lab.index')->instance();

    expect(method_exists($component, 'languageBadge'))->toBeFalse();
});

test('both surfaces link a card to the snippet it came from', function () {
    $snippet = LabSnippet::factory()->published()->create(['title' => 'A card worth keeping']);

    $href = route('dev-lab.snippets.show', ['snippet' => $snippet->slug]);

    Livewire::test('pages::dev-lab.gallery')->assertSeeHtml('href="'.$href.'"');

    $this->get(route('dev-lab.index'))->assertSeeHtml('href="'.$href.'"');
});
