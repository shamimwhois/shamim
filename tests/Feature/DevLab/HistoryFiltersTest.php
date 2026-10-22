<?php

use App\Enums\LabLanguage;
use App\Models\LabExecution;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The run history and the one filter over it
|--------------------------------------------------------------------------
|
| The history screen offers a language chip only for a language this account has
| actually run, which is a stricter promise than the gallery's published counts and
| has the same failure behind it: a control that can only produce an empty page is not
| navigation, it is decoration with a click cost. So the interesting pins are which
| chips appear, and what the page says when a stale `?lang=` from an old link arrives
| anyway.
|
*/

test('only the languages this account has actually run are offered as chips', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabExecution::factory()->language(LabLanguage::Css)->create(['user_id' => $author->id, 'label' => 'A ring in css']);
    LabExecution::factory()->language(LabLanguage::Javascript)->create(['user_id' => $author->id, 'label' => 'A helper in javascript']);

    $filters = Livewire::actingAs($author)->test('pages::dev-lab.history')->instance()->filters;

    expect($filters)->toBe(['css' => 'CSS', 'javascript' => 'JavaScript']);
});

test('the every language control clears the filter rather than setting a zero', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabExecution::factory()->language(LabLanguage::Css)->create(['user_id' => $author->id, 'label' => 'A ring in css']);

    // The "Every language" chip used to be one entry in the language map, and PHP turns
    // an array key of the empty string into the integer zero when it stores it. The chip
    // therefore asked Livewire to set the language filter to "0", which no language is
    // called and which never compares equal to the empty string the filter clears to.
    Livewire::actingAs($author)
        ->test('pages::dev-lab.history')
        ->assertDontSeeHtml("\$set('language', '0')")
        ->assertSeeHtml("\$set('language', '')")
        ->set('language', 'css')
        ->assertSee('A ring in css')
        ->set('language', '')
        ->assertSee('A ring in css');
});

test('choosing a language lists only the runs made in it', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabExecution::factory()->language(LabLanguage::Css)->create(['user_id' => $author->id, 'label' => 'A ring in css']);
    LabExecution::factory()->language(LabLanguage::Javascript)->create(['user_id' => $author->id, 'label' => 'A helper in javascript']);

    Livewire::actingAs($author)->test('pages::dev-lab.history')
        ->set('language', 'css')
        ->assertSee('A ring in css')
        ->assertDontSee('A helper in javascript')
        ->set('language', '')
        ->assertSee('A helper in javascript');
});

test('a language that was never run here says so and offers the way back', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabExecution::factory()->language(LabLanguage::Css)->create(['user_id' => $author->id, 'label' => 'A ring in css']);

    // PHP is storable and so is a valid `?lang=php` from an old bookmark, but this
    // account has never run it. The empty state names the reason rather than showing
    // the runs that are there.
    Livewire::actingAs($author)->test('pages::dev-lab.history')
        ->set('language', 'php')
        ->assertDontSee('A ring in css')
        ->assertSee('Nothing has been run in that language yet')
        ->assertSeeHtml("\$set('language', '')");
});

test('the filter never reaches another account runs', function () {
    $author = User::factory()->create(['handle' => 'shelf']);
    $stranger = User::factory()->create(['handle' => 'other']);

    LabExecution::factory()->language(LabLanguage::Css)->create(['user_id' => $author->id, 'label' => 'My own css run']);
    LabExecution::factory()->language(LabLanguage::Css)->create(['user_id' => $stranger->id, 'label' => 'A stranger css run']);

    foreach (['', 'css'] as $language) {
        Livewire::actingAs($author)->test('pages::dev-lab.history')
            ->set('language', $language)
            ->assertDontSee('A stranger css run');
    }

    Livewire::actingAs($author)->test('pages::dev-lab.history')
        ->set('language', 'css')
        ->assertSee('My own css run');
});
