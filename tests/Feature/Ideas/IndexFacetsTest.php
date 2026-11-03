<?php

use App\Enums\ContentVisibility;
use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Models\Topic;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The facets on the public ideas landing
|--------------------------------------------------------------------------
|
| Three things have to hold for a facet to be navigation rather than decoration.
| It only ever offers what a reader can open, which is the gate
| {@see \App\Services\PublicTaxonomy} owns and this screen renders. It resets the
| page, because page two of a narrowed list is usually a page of nothing. And it
| survives being typed by hand, because `?topic=` in a shared URL is not a click on
| a chip.
|
| The ordering pins matter for a different reason. The sort select arrived on a page
| that already had tabs, and each tab carries an order. The default option must leave
| that exactly as it was, or the new control silently rewrites what "Promoted" means.
|
*/

test('the subject row offers only subjects with something published', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    $filed = Topic::factory()->create(['name' => 'Forms', 'slug' => 'forms']);
    $empty = Topic::factory()->create(['name' => 'Layouts', 'slug' => 'layouts']);

    Idea::factory()->published()->withTopics([$filed->id])->create([
        'user_id' => $author->id,
        'title' => 'A focus ring you can actually see',
    ]);

    // The same subject carried by a draft: invisible to a stranger, so absent here too.
    Idea::factory()->create([
        'user_id' => $author->id,
        'title' => 'A draft nobody can read',
        'status' => IdeaStatus::Draft,
        'visibility' => ContentVisibility::Private,
    ])->topics()->attach($empty->id);

    Livewire::test('pages::ideas.index')
        ->assertSee('Forms')
        ->assertDontSee('Layouts')
        ->assertSee('A focus ring you can actually see')
        ->assertDontSee('A draft nobody can read');
});

test('choosing a subject filters the list to the published ideas carrying it', function () {
    $forms = Topic::factory()->create(['name' => 'Forms', 'slug' => 'forms']);
    $css = Topic::factory()->create(['name' => 'CSS', 'slug' => 'css']);

    Idea::factory()->published()->withTopics([$forms->id])->create(['title' => 'Fieldset anatomy']);
    Idea::factory()->published()->withTopics([$css->id])->create(['title' => 'Container queries at work']);

    Livewire::test('pages::ideas.index')
        ->call('toggleTopic', 'forms')
        ->assertSet('topicSlug', 'forms')
        ->assertSee('Fieldset anatomy')
        ->assertDontSee('Container queries at work');
});

test('toggling the same subject off returns to the whole published set', function () {
    $forms = Topic::factory()->create(['name' => 'Forms', 'slug' => 'forms']);

    Idea::factory()->published()->withTopics([$forms->id])->create(['title' => 'Fieldset anatomy']);
    Idea::factory()->published()->create(['title' => 'Something unfiled']);

    Livewire::test('pages::ideas.index')
        ->call('toggleTopic', 'forms')
        ->assertSee('Fieldset anatomy')
        ->assertDontSee('Something unfiled')
        ->call('toggleTopic', 'forms')
        ->assertSet('topicSlug', '')
        ->assertSee('Something unfiled');
});

test('a hand-typed subject nobody can browse empties the list instead of pretending', function () {
    Idea::factory()->published()->create(['title' => 'Something published']);

    // The filter is still echoed, so the reader can see what to undo and clear it.
    Livewire::test('pages::ideas.index')
        ->set('topicSlug', 'retired-subject')
        ->assertDontSee('Something published')
        ->assertSee('retired-subject');
});

test('the author facet narrows to one handle and only offers names with published work', function () {
    $visible = User::factory()->create(['handle' => 'marta', 'name' => 'Marta']);
    $silent = User::factory()->create(['handle' => 'quiet', 'name' => 'Quiet Person']);

    Idea::factory()->published()->create(['user_id' => $visible->id, 'title' => 'Written by Marta']);
    Idea::factory()->create([
        'user_id' => $silent->id,
        'title' => 'Written by nobody yet',
        'status' => IdeaStatus::Draft,
        'visibility' => ContentVisibility::Private,
    ]);

    Livewire::test('pages::ideas.index')
        ->assertSee('Marta')
        ->assertDontSee('Quiet Person')
        ->set('authorHandle', 'marta')
        ->assertSee('Written by Marta')
        ->assertDontSee('Written by nobody yet');
});

test('an author handle from another page filters to nothing rather than erroring', function () {
    Idea::factory()->published()->create(['title' => 'Somebody else wrote this']);

    Livewire::test('pages::ideas.index')
        ->set('authorHandle', 'stranger')
        ->assertDontSee('Somebody else wrote this');
});

test('sorting orders the published set the way each option says', function () {
    Idea::factory()->published()->create(['title' => 'Bravo', 'published_at' => now()->subDays(9)]);
    Idea::factory()->published()->create(['title' => 'Alpha', 'published_at' => now()->subDay()]);

    $read = Idea::factory()->published()->create(['title' => 'Charlie', 'published_at' => now()->subDays(30)]);
    $read->forceFill(['view_count' => 900])->save();

    Livewire::test('pages::ideas.index')
        ->set('sort', 'title')
        ->assertSeeInOrder(['Alpha', 'Bravo', 'Charlie']);

    Livewire::test('pages::ideas.index')
        ->set('sort', 'oldest')
        ->assertSeeInOrder(['Charlie', 'Bravo', 'Alpha']);

    Livewire::test('pages::ideas.index')
        ->set('sort', 'recent')
        ->assertSeeInOrder(['Alpha', 'Bravo', 'Charlie']);

    Livewire::test('pages::ideas.index')
        ->set('sort', 'views')
        ->assertSeeInOrder(['Charlie', 'Alpha', 'Bravo']);
});

test('the default sort leaves the promoted tab order alone', function () {
    Idea::factory()->published()->create(['title' => 'Recently published', 'published_at' => now()->subDay()]);
    Idea::factory()->published()->pinned()->create(['title' => 'Pinned and older', 'published_at' => now()->subWeek()]);

    // Nothing chosen: the tab still decides, and a pin still comes first.
    Livewire::test('pages::ideas.index')
        ->assertSeeInOrder(['Pinned and older', 'Recently published']);

    // An explicit choice overrides the tab, which is the point of the control.
    Livewire::test('pages::ideas.index')
        ->set('sort', 'recent')
        ->assertSeeInOrder(['Recently published', 'Pinned and older']);
});

test('a one-character query is refused with a word instead of an empty list', function () {
    Idea::factory()->published()->create(['title' => 'Everything published']);

    Livewire::test('pages::ideas.index')
        ->set('search', 'x')
        ->assertSee('Type at least two characters')
        ->assertSee('Everything published');
});

/*
| The filtering assertion runs on the first `set()`, not the second. A computed
| search guard would be evaluated during `updating()` — before the new term lands —
| and held for the rest of the request, so the list would filter one keystroke behind
| the box and this test would only pass because the second set inherited the first.
|
*/

test('a query long enough to run filters and echoes the term escaped', function () {
    Idea::factory()->published()->create(['title' => 'A debounce hook worth keeping']);
    Idea::factory()->published()->create(['title' => 'Unrelated note entirely']);

    Livewire::test('pages::ideas.index')
        ->set('search', 'debounce hook')
        ->assertSee('A debounce hook worth keeping')
        ->assertDontSee('Unrelated note entirely')
        ->assertDontSee('Type at least two characters')
        ->set('search', '<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('clearing the subject leaves the other filters exactly where they were', function () {
    Topic::factory()->create(['slug' => 'forms']);

    Livewire::test('pages::ideas.index')
        ->set('topicSlug', 'forms')
        ->set('authorHandle', 'marta')
        ->set('search', 'ring')
        ->set('sort', 'title')
        ->call('toggleTopic', 'forms')
        ->assertSet('topicSlug', '')
        ->assertSet('authorHandle', 'marta')
        ->assertSet('search', 'ring')
        ->assertSet('sort', 'title');
});

test('reset filters returns every control to its default at once', function () {
    Livewire::test('pages::ideas.index')
        ->set('topicSlug', 'forms')
        ->set('authorHandle', 'marta')
        ->set('search', 'ring')
        ->set('sort', 'views')
        ->set('tab', 'latest')
        ->call('clearFilters')
        ->assertSet('topicSlug', '')
        ->assertSet('authorHandle', '')
        ->assertSet('search', '')
        ->assertSet('sort', 'tab')
        ->assertSet('tab', 'promoted');
});

test('moving a facet resets the page so a narrowed list is not empty by arithmetic', function () {
    $forms = Topic::factory()->create(['slug' => 'forms']);

    Idea::factory()
        ->count((int) config('ideas.per_page.public') + 1)
        ->published()
        ->sequence(fn ($sequence) => ['title' => 'Idea number '.$sequence->index])
        ->create()
        ->each(function (Idea $idea) use ($forms): void {
            $idea->topics()->attach($forms->id);
        });

    Livewire::test('pages::ideas.index')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->call('toggleTopic', 'forms')
        ->assertSet('paginators.page', 1);
});
