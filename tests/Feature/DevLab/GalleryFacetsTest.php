<?php

use App\Enums\LabLanguage;
use App\Models\Idea;
use App\Models\LabSnippet;
use App\Models\Topic;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The facets on the public snippet gallery
|--------------------------------------------------------------------------
|
| The gallery already navigated by language. It now navigates by subject and by
| author as well, and the three facets have to agree with each other and with the
| database: a chip that counts published snippets while the list counts everything
| saved is a chip that lies.
|
| Language is the odd one of the three, because it is an enum rather than a table.
| Its counts are computed here, not in the shared gate, and the honest version of a
| language nobody has published is a missing chip.
|
*/

test('the language chips carry the published count for each language', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    LabSnippet::factory()->published()->language(LabLanguage::Css)->count(2)->create(['user_id' => $author->id]);
    LabSnippet::factory()->php()->create(['user_id' => $author->id]);

    $counts = Livewire::test('pages::dev-lab.gallery')->instance()->languageCounts;

    $this->assertSame(2, $counts['css']);
    $this->assertArrayNotHasKey('php', $counts);
});

test('a language with nothing published is not offered as a chip', function () {
    LabSnippet::factory()->published()->language(LabLanguage::Html)->create(['title' => 'A card worth keeping']);

    // PHP exists in the lab and is stored, and the page says so in prose. What it is
    // not allowed to be is a clickable chip that opens an empty list, so the pin is
    // on the control rather than on the word.
    $component = Livewire::test('pages::dev-lab.gallery');

    $counts = $component->instance()->languageCounts;

    $this->assertSame(['html' => 1], $counts);
    $this->assertArrayNotHasKey('php', $counts);

    $component->assertSee('HTML')->assertDontSeeHtml("\$set('language', 'php')");
});

test('a hand-typed language nobody publishes in empties the gallery instead of showing everything', function () {
    LabSnippet::factory()->published()->language(LabLanguage::Css)->create(['title' => 'A ring you can actually see']);

    // The chips only offer what has rows, so a `?lang=` that reaches this page was
    // typed rather than clicked. It is still echoed, so the reader can see and undo it.
    Livewire::test('pages::dev-lab.gallery')
        ->set('language', 'perl')
        ->assertDontSee('A ring you can actually see')
        ->assertSee('perl');

    // A language the lab does know but nobody has published in gives the same answer,
    // so the two kinds of stale link fail the same way.
    Livewire::test('pages::dev-lab.gallery')
        ->set('language', 'php')
        ->assertDontSee('A ring you can actually see');
});

test('choosing a subject filters the gallery to published snippets carrying it', function () {
    $forms = Topic::factory()->create(['name' => 'Forms', 'slug' => 'forms']);
    $css = Topic::factory()->create(['name' => 'CSS', 'slug' => 'css']);

    LabSnippet::factory()->published()->withTopics([$forms->id])->create(['title' => 'Autofill without the jump']);
    LabSnippet::factory()->published()->withTopics([$css->id])->create(['title' => 'A shadow you can believe']);

    Livewire::test('pages::dev-lab.gallery')
        ->call('toggleTopic', 'forms')
        ->assertSet('topicSlug', 'forms')
        ->assertSee('Autofill without the jump')
        ->assertDontSee('A shadow you can believe');
});

test('a subject counted for ideas is not offered to snippets', function () {
    $topic = Topic::factory()->create(['name' => 'Testing', 'slug' => 'testing']);

    // The same platform row, but only an idea carries it. Publication is per family,
    // so the gallery must not show a chip that opens an empty list.
    Idea::factory()->published()->withTopics([$topic->id])->create();

    Livewire::test('pages::dev-lab.gallery')->assertDontSeeHtml($topic->name);
});

test('the author facet offers only names with published snippets', function () {
    $visible = User::factory()->create(['handle' => 'marta', 'name' => 'Marta']);
    $silent = User::factory()->create(['handle' => 'quiet', 'name' => 'Quiet Person']);

    LabSnippet::factory()->published()->create(['user_id' => $visible->id, 'title' => 'Saved by Marta']);
    LabSnippet::factory()->create(['user_id' => $silent->id, 'title' => 'Saved by nobody yet']);

    Livewire::test('pages::dev-lab.gallery')
        ->assertSee('Marta')
        ->assertDontSee('Quiet Person')
        ->set('authorHandle', 'marta')
        ->assertSee('Saved by Marta')
        ->assertDontSee('Saved by nobody yet');
});

test('language and subject compose instead of overwriting each other', function () {
    $forms = Topic::factory()->create(['name' => 'Forms', 'slug' => 'forms']);

    LabSnippet::factory()->published()->language(LabLanguage::Css)
        ->withTopics([$forms->id])->create(['title' => 'Css filed under forms']);
    LabSnippet::factory()->published()->language(LabLanguage::Javascript)
        ->withTopics([$forms->id])->create(['title' => 'Javascript filed under forms']);
    LabSnippet::factory()->published()->language(LabLanguage::Css)
        ->create(['title' => 'Css filed under nothing']);

    Livewire::test('pages::dev-lab.gallery')
        ->call('toggleTopic', 'forms')
        ->set('language', 'css')
        ->assertSee('Css filed under forms')
        ->assertDontSee('Javascript filed under forms')
        ->assertDontSee('Css filed under nothing');
});

test('sorting orders the published set the way each option says', function () {
    LabSnippet::factory()->published()->create(['title' => 'Bravo', 'published_at' => now()->subDays(9)]);
    LabSnippet::factory()->published()->create(['title' => 'Alpha', 'published_at' => now()->subDay()]);

    $read = LabSnippet::factory()->published()->create(['title' => 'Charlie', 'published_at' => now()->subDays(30)]);
    $read->forceFill(['view_count' => 900])->save();

    Livewire::test('pages::dev-lab.gallery')
        ->set('sort', 'title')
        ->assertSeeInOrder(['Alpha', 'Bravo', 'Charlie']);

    Livewire::test('pages::dev-lab.gallery')
        ->set('sort', 'oldest')
        ->assertSeeInOrder(['Charlie', 'Bravo', 'Alpha']);

    Livewire::test('pages::dev-lab.gallery')
        ->set('sort', 'views')
        ->assertSeeInOrder(['Charlie', 'Alpha', 'Bravo']);

    // The untouched default is the order the gallery has always had.
    Livewire::test('pages::dev-lab.gallery')
        ->assertSeeInOrder(['Alpha', 'Bravo', 'Charlie']);
});

test('a one-character query is refused with a word instead of an empty list', function () {
    LabSnippet::factory()->published()->create(['title' => 'Everything published']);

    Livewire::test('pages::dev-lab.gallery')
        ->set('search', 'x')
        ->assertSee('Type at least two characters')
        ->assertSee('Everything published');
});

test('a query long enough to run searches the stored code and echoes escaped', function () {
    LabSnippet::factory()->published()->create([
        'title' => 'Keep this gradient',
        'code' => '.hero { background: linear-gradient(180deg, #fff, #eee); }',
    ]);
    LabSnippet::factory()->published()->create([
        'title' => 'Something else entirely',
        'code' => '<p>plain paragraph</p>',
    ]);

    Livewire::test('pages::dev-lab.gallery')
        ->set('search', 'linear-gradient')
        ->assertSee('Keep this gradient')
        ->assertDontSee('Something else entirely')
        ->set('search', '<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('reset filters returns every control to its default at once', function () {
    Livewire::test('pages::dev-lab.gallery')
        ->set('topicSlug', 'forms')
        ->set('authorHandle', 'marta')
        ->set('language', 'css')
        ->set('search', 'ring')
        ->set('sort', 'views')
        ->call('clearFilters')
        ->assertSet('topicSlug', '')
        ->assertSet('authorHandle', '')
        ->assertSet('language', '')
        ->assertSet('search', '')
        ->assertSet('sort', 'recent');
});

test('moving a facet resets the page so a narrowed list is not empty by arithmetic', function () {
    $forms = Topic::factory()->create(['slug' => 'forms']);

    LabSnippet::factory()
        ->count((int) config('dev_lab.per_page.public') + 1)
        ->published()
        ->sequence(fn ($sequence) => ['title' => 'Snippet number '.$sequence->index])
        ->create()
        ->each(function (LabSnippet $snippet) use ($forms): void {
            $snippet->topics()->attach($forms->id);
        });

    Livewire::test('pages::dev-lab.gallery')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->call('toggleTopic', 'forms')
        ->assertSet('paginators.page', 1);
});
