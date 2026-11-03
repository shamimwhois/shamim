<?php

use App\Models\Idea;
use App\Models\LabSnippet;
use App\Models\Topic;

/*
|--------------------------------------------------------------------------
| The public subject index and its shelves
|--------------------------------------------------------------------------
|
| `/topics` and `/topics/{slug}` are the two screens a stranger reaches when they
| want the vocabulary rather than one module's slice of it. Everything the gate
| decides is already pinned in `PublicTaxonomyTest`, so these tests are about the
| pages: that they read the gate instead of re-deriving it, that the two families
| appear side by side, that the links out carry the subject with them, and that a
| subject nobody can read anything under is a 404 rather than an empty page.
|
| The cut is pinned too. Each shelf shows one page of rows and says how many exist,
| because the module's own list is the complete answer and is one click away — a
| page that silently truncated would be the one thing a shared taxonomy must not do.
|
*/

test('the subject index opens for a stranger', function () {
    $topic = Topic::factory()->create(['name' => 'Forms']);
    Idea::factory()->published()->create()->topics()->sync([$topic->id]);

    $this->get(route('topics.index'))
        ->assertOk()
        ->assertSee('data-test="topics-index"', false)
        ->assertSee('Forms');
});

test('the index lists a subject only once a published row carries it', function () {
    $published = Topic::factory()->create(['name' => 'Accessibility']);
    $draftOnly = Topic::factory()->create(['name' => 'Private Filing']);
    $seededAndQuiet = Topic::factory()->create(['name' => 'Never Filed']);

    Idea::factory()->published()->create()->topics()->sync([$published->id]);
    // The same subject attached to an idea that was never approved: it exists in the
    // taxonomy and in a draft, and a stranger must not be told about either.
    Idea::factory()->create()->topics()->sync([$draftOnly->id]);

    $page = $this->get(route('topics.index'))->assertOk();

    $page->assertSee('Accessibility')
        ->assertDontSee('Private Filing')
        ->assertDontSee('Never Filed');
});

test('a subject card says what opens behind it in both families', function () {
    $topic = Topic::factory()->create(['name' => 'Layouts', 'description' => 'Grids that hold at every width.']);

    Idea::factory()->published()->count(2)->create()->each(
        fn (Idea $idea) => $idea->topics()->sync([$topic->id])
    );
    LabSnippet::factory()->published()->create()->topics()->sync([$topic->id]);

    $this->get(route('topics.index'))
        ->assertOk()
        ->assertSee('Grids that hold at every width.')
        ->assertSee('2 ideas')
        ->assertSee('1 snippet')
        ->assertSee('/topics/'.$topic->slug, false);
});

test('a topic page shows the ideas and the code filed under it together', function () {
    $topic = Topic::factory()->create(['name' => 'Forms']);

    $idea = Idea::factory()->published()->create(['title' => 'Why inline validation lies']);
    $idea->topics()->sync([$topic->id]);

    $snippet = LabSnippet::factory()->published()->create(['title' => 'A debounce that waits']);
    $snippet->topics()->sync([$topic->id]);

    $this->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('data-test="topic-show"', false)
        ->assertSee('Why inline validation lies')
        ->assertSee('A debounce that waits')
        ->assertSee('1 idea')
        ->assertSee('1 snippet');
});

test('both shelves link out carrying the subject they are showing', function () {
    $topic = Topic::factory()->create(['name' => 'Performance', 'slug' => 'performance']);

    $idea = Idea::factory()->published()->create();
    $idea->topics()->sync([$topic->id]);

    $snippet = LabSnippet::factory()->published()->create();
    $snippet->topics()->sync([$topic->id]);

    $this->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('/ideas?topic=performance', false)
        ->assertSee('/dev-lab/gallery?topic=performance', false);
});

test('a subject with published ideas and no published code says so', function () {
    $topic = Topic::factory()->create(['name' => 'Security']);
    Idea::factory()->published()->create()->topics()->sync([$topic->id]);

    $this->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('no published snippet yet');
});

test('a subject with published code and no published idea says so', function () {
    $topic = Topic::factory()->create(['name' => 'Tooling']);
    LabSnippet::factory()->published()->create()->topics()->sync([$topic->id]);

    $this->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('no published idea yet');
});

test('a topic page for a subject nothing published carries is a 404', function () {
    $topic = Topic::factory()->create(['name' => 'Empty Shelf']);
    Idea::factory()->create()->topics()->sync([$topic->id]);

    $this->get(route('topics.show', ['topic' => $topic->slug]))->assertNotFound();
});

test('a topic an operator switched off closes its page while its rows stay published', function () {
    $topic = Topic::factory()->create(['name' => 'Retired']);
    $idea = Idea::factory()->published()->create();
    $idea->topics()->sync([$topic->id]);

    // Withdrawn before the request rather than between two of them: the gate is
    // scoped to a request and memoises its answer, so a second `$this->get()` in the
    // same test would read the first request's cache and the toggle would appear to
    // do nothing. One request per test is what makes this assertion about the switch.
    $topic->update(['is_active' => false]);

    $this->get(route('topics.show', ['topic' => $topic->slug]))->assertNotFound();

    // What was withdrawn is the subject as a place to arrive at, not the author's work.
    expect($idea->fresh()->isPublished())->toBeTrue()
        ->and($idea->fresh()->topics->pluck('id'))->toContain($topic->id);
});

test('a topic an operator switched off leaves the subject index too', function () {
    $topic = Topic::factory()->create(['name' => 'Retired']);
    Idea::factory()->published()->create()->topics()->sync([$topic->id]);
    $other = Topic::factory()->create(['name' => 'Still Open']);
    Idea::factory()->published()->create()->topics()->sync([$other->id]);

    $topic->update(['is_active' => false]);

    $this->get(route('topics.index'))
        ->assertOk()
        ->assertDontSee('Retired')
        ->assertSee('Still Open');
});

test('the index and the shelf describe themselves as collections', function () {
    $topic = Topic::factory()->create(['name' => 'Data Visualisation']);
    Idea::factory()->published()->create()->topics()->sync([$topic->id]);

    $this->get(route('topics.index'))
        ->assertOk()
        ->assertSee('"@type":"CollectionPage"', false)
        ->assertSee('"@type":"ItemList"', false);

    $this->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('"@type":"CollectionPage"', false)
        ->assertSee('"@type":"BreadcrumbList"', false);
});

test('the published row limit says what it cut rather than hiding it', function () {
    $topic = Topic::factory()->create(['name' => 'Css']);
    $limit = (int) config('ideas.per_page.public');

    Idea::factory()->published()->count($limit + 1)->create()->each(
        fn (Idea $idea) => $idea->topics()->sync([$topic->id])
    );

    $this->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('Showing the first '.$limit.' of '.($limit + 1))
        ->assertSee('the ideas index paginates the rest');
});

test('both landings and the feed menu lead to the subject index', function () {
    $topic = Topic::factory()->create(['name' => 'Testing']);
    Idea::factory()->published()->create()->topics()->sync([$topic->id]);
    LabSnippet::factory()->published()->create()->topics()->sync([$topic->id]);

    $href = e(route('topics.index', absolute: false));

    $this->get(route('ideas.index'))->assertOk()->assertSee($href, false);
    $this->get(route('dev-lab.gallery'))->assertOk()->assertSee($href, false);
    $this->get(route('feed.index'))->assertOk()->assertSee('Browse by subject');
});

test('the subject index is reachable beside the modules without shadowing them', function () {
    // `/topics/{topic:slug}` sits at the same depth as the two module detail routes,
    // and an idea whose slug happens to be "topics" lives one segment deeper under
    // `/ideas`, so neither can swallow the other.
    $idea = Idea::factory()->published()->create(['slug' => 'topics', 'title' => 'A subject of its own']);

    $this->get(route('topics.index'))->assertOk();
    $this->get('/topics')->assertOk();
    $this->get('/ideas/topics')->assertOk()->assertSee('A subject of its own');
    $this->assertNotSame(
        route('ideas.show', ['idea' => $idea->slug]),
        route('topics.index')
    );
});
