<?php

use App\Models\Idea;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Reading an idea
|--------------------------------------------------------------------------
|
| The idea page renders Markdown and used to stop there, even though it already
| carried the attribute the reading progress bar looks for and the site already
| owns an outline component the blog and the hub both use. These tests pin the
| layer, not the prose: the outline points at the ids the renderer wrote, the
| outline hides when there is nothing to outline, and the walk to the neighbouring
| ideas agrees with the order the public index prints.
|
| The outline's item count is pinned rather than the uniqueness of its anchors. Two
| identical headings produce the same anchor in this app, in the blog and in the hub,
| because that is what the shared renderer does; pinning the count here keeps the
| limitation visible without pretending this page owns it.
|
*/

test('the outline lists every level two and three heading the body rendered', function () {
    $idea = Idea::factory()->published()->create([
        'content' => <<<'MARKDOWN'
            ## Why the sandbox is an iframe

            Prose about frames, long enough to be worth scrolling through.

            ## What the frame may not do

            More prose.

            ### Same origin is the line

            And a third paragraph of prose.
            MARKDOWN,
    ]);

    $outline = Livewire::test('pages::ideas.show', ['idea' => $idea])
        ->instance()
        ->outline;

    expect($outline)->toHaveCount(3)
        ->and($outline[0]['level'])->toBe(2)
        ->and($outline[0]['text'])->toBe('Why the sandbox is an iframe')
        ->and($outline[2]['level'])->toBe(3);
});

test('the outline and the rendered headings carry the same anchors', function () {
    $idea = Idea::factory()->published()->create([
        'content' => "## First section\n\nBody.\n\n## Second section\n\nMore body.",
    ]);

    $page = Livewire::test('pages::ideas.show', ['idea' => $idea]);

    foreach ($page->instance()->outline as $entry) {
        $page->assertSeeHtml('id="'.$entry['id'].'"');
    }

    // Desktop sidebar and the narrow-screen disclosure, each with its own heading id.
    $page->assertSeeHtml('id="toc-heading"')
        ->assertSeeHtml('id="toc-mobile-heading"')
        ->assertSeeHtml('data-reading-progress')
        ->assertSeeHtml('data-reading-target');
});

test('an idea with one heading gets no table of contents', function () {
    $idea = Idea::factory()->published()->create([
        'content' => "## Only section\n\nA paragraph, and that is the whole idea.",
    ]);

    Livewire::test('pages::ideas.show', ['idea' => $idea])
        ->assertDontSee('On this page')
        ->assertDontSeeHtml('data-toc');
});

test('an idea with no body offers no reading time where there is nothing to read', function () {
    $idea = Idea::factory()->published()->create(['content' => null]);

    $page = Livewire::test('pages::ideas.show', ['idea' => $idea]);

    expect($page->instance()->wordCount)->toBe(0)
        ->and($page->instance()->readingMinutes)->toBe(1);

    $page->assertDontSee('min read')
        ->assertSee('This idea is still just a line');
});

test('reading time and word count are printed for an idea that has prose', function () {
    $idea = Idea::factory()->published()->create([
        'content' => str_repeat('A sentence worth reading. ', 60),
    ]);

    $page = Livewire::test('pages::ideas.show', ['idea' => $idea]);

    expect($page->instance()->wordCount)->toBe(240)
        ->and($page->instance()->readingMinutes)->toBe(2);

    $page->assertSee('240 words')
        ->assertSee('2 min read');
});

test('the share bar shares the idea rather than a blog post', function () {
    $idea = Idea::factory()->published()->create(['title' => 'A debounce hook worth keeping']);

    Livewire::test('pages::ideas.show', ['idea' => $idea])
        ->assertSeeHtml('data-share-url="'.route('ideas.show', ['idea' => $idea->slug]).'"')
        ->assertSeeHtml('data-share-title="A debounce hook worth keeping"');
});

test('the byline links to the same landing narrowed to its author', function () {
    $author = User::factory()->create(['handle' => 'marta']);

    $idea = Idea::factory()->published()->create(['user_id' => $author->id]);

    Livewire::test('pages::ideas.show', ['idea' => $idea])
        ->assertSeeHtml('/ideas?author=marta')
        ->assertSee($author->name);
});

test('the page reaches the ideas published either side of it', function () {
    $earlier = Idea::factory()->published()->create([
        'title' => 'The one before',
        'published_at' => now()->subDays(6),
    ]);
    $middle = Idea::factory()->published()->create([
        'title' => 'The one in the middle',
        'published_at' => now()->subDays(4),
    ]);
    Idea::factory()->published()->create([
        'title' => 'The one after',
        'published_at' => now()->subDays(2),
    ]);

    $page = Livewire::test('pages::ideas.show', ['idea' => $middle]);

    $adjacent = $page->instance()->adjacent;

    expect($adjacent['previous'])->not->toBeNull()
        ->and($adjacent['previous']->id)->toBe($earlier->id)
        ->and($adjacent['next'])->not->toBeNull();

    $page->assertSee('The one before')
        ->assertSee('The one after')
        ->assertSeeHtml('rel="prev"')
        ->assertSeeHtml('rel="next"');
});

test('the first idea published has nothing older to point at', function () {
    $oldest = Idea::factory()->published()->create([
        'title' => 'The oldest idea',
        'published_at' => now()->subDays(9),
    ]);
    Idea::factory()->published()->create([
        'title' => 'A newer idea',
        'published_at' => now()->subDays(3),
    ]);

    $page = Livewire::test('pages::ideas.show', ['idea' => $oldest]);

    expect($page->instance()->adjacent['previous'])->toBeNull();

    $page->assertDontSee('Earlier')
        ->assertSee('A newer idea');
});

test('a lone published idea gets no neighbours at all', function () {
    $only = Idea::factory()->published()->create(['title' => 'The only idea here']);

    $adjacent = Livewire::test('pages::ideas.show', ['idea' => $only])
        ->instance()
        ->adjacent;

    expect($adjacent)->toBe(['previous' => null, 'next' => null]);
});

test('an unpublished idea its author is reading walks no published set', function () {
    $draft = Idea::factory()->create(['published_at' => null, 'content' => '## Plan']);

    $page = Livewire::actingAs($draft->user)
        ->test('pages::ideas.show', ['idea' => $draft])
        ->assertOk();

    expect($page->instance()->adjacent)->toBe(['previous' => null, 'next' => null]);
});
