<?php

use App\Models\Idea;
use App\Models\IdeaCategory;
use App\Models\LabSnippet;
use App\Models\Topic;
use App\Models\User;
use App\Services\PublicTaxonomy;
use Closure;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The public topic gate
|--------------------------------------------------------------------------
|
| One rule, three screens: a topic may be advertised only where clicking it
| opens something published. These tests pin the rule at its source, because
| the landing pages, the gallery and the topic index all read it from here and
| any of them drifting would show a chip that leads nowhere.
|
| The private half of the same rule matters just as much: an unpublished idea
| carries a topic too, and must never move that topic into the public list.
|
*/

function taxonomy(): PublicTaxonomy
{
    return app(PublicTaxonomy::class);
}

/**
 * Count the queries one call costs, by replaying the log rather than by adding a
 * listener: the log is what the connection already keeps, and a second set of
 * listeners would count its own registration.
 *
 * @param  Closure(): mixed  $callback
 */
function queriesRunning(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('the facet rows are answered once per request, and the second call costs no query', function () {
    $topic = Topic::factory()->create();
    Idea::factory()->published()->create()->topics()->sync([$topic->id]);

    $service = new PublicTaxonomy;
    $chips = $service->topicChips(PublicTaxonomy::IDEAS);

    // The memo is the same object, not merely an equal one: the landing asks for
    // the chips, the selected chip and the count line inside one render, and a
    // second query would be a second answer to the same question.
    expect($service->topicChips(PublicTaxonomy::IDEAS))->toBe($chips)
        ->and($service->topic(PublicTaxonomy::IDEAS, $topic->slug))->toBe($chips->first())
        ->and(queriesRunning(fn () => $service->topicChips(PublicTaxonomy::IDEAS)))->toBe(0);

    // The other family is its own entry, so a cached ideas row cannot be handed
    // back for a snippets question.
    expect(queriesRunning(fn () => $service->topicChips(PublicTaxonomy::SNIPPETS)))->toBeGreaterThan(0);
});

test('what the facet answered at the start of a request is what it says at the end of it', function () {
    $topic = Topic::factory()->create();
    Idea::factory()->published()->create()->topics()->sync([$topic->id]);

    $service = new PublicTaxonomy;
    $before = $service->topicChips(PublicTaxonomy::IDEAS)->first()->ideas_count;

    // A second idea goes live mid-render. The screen the visitor is being handed
    // has already printed its numbers, and re-reading them would show two counts
    // for one subject in one page.
    Idea::factory()->published()->create()->topics()->sync([$topic->id]);

    expect($service->topicChips(PublicTaxonomy::IDEAS)->first()->ideas_count)->toBe($before)
        ->and((new PublicTaxonomy)->topicChips(PublicTaxonomy::IDEAS)->first()->ideas_count)->toBe($before + 1);
});

test('the index, the picker and the author facet each answer their query once', function () {
    $author = User::factory()->create(['handle' => 'authoronce']);
    $topic = Topic::factory()->create();
    Idea::factory()->published()->create(['user_id' => $author->id])->topics()->sync([$topic->id]);

    $service = new PublicTaxonomy;

    // Pay for each of them once, in the order a page would.
    $service->indexed();
    $service->isBrowseable($topic);
    $service->pickerTopics();
    $service->topicForEditor($topic->slug);
    $service->authorChips(PublicTaxonomy::IDEAS);

    // `isBrowseable()` reads the index, and the topic page asks it per card, so an
    // unmemoised index would be a query per row on the page it is listing.
    expect(queriesRunning(fn () => $service->indexed()))->toBe(0)
        ->and(queriesRunning(fn () => $service->isBrowseable($topic)))->toBe(0)
        ->and(queriesRunning(fn () => $service->pickerTopics()))->toBe(0)
        ->and(queriesRunning(fn () => $service->topicForEditor($topic->slug)))->toBe(0)
        ->and(queriesRunning(fn () => $service->authorChips(PublicTaxonomy::IDEAS)))->toBe(0);

    // The picker is shared by both families, and `topicForEditor()` reads it rather
    // than running its own lookup.
    expect($service->pickerTopics())->toBe($service->pickerTopics())
        ->and($service->topicForEditor($topic->slug))->toBe($service->pickerTopics()->firstWhere('slug', $topic->slug));

    // A fresh instance pays for each of them again: the memo is a request, not a
    // cache, and a page two seconds later has to see what changed.
    $fresh = new PublicTaxonomy;
    expect(queriesRunning(fn () => $fresh->indexed()))->toBe(1)
        ->and(queriesRunning(fn () => $fresh->authorChips(PublicTaxonomy::IDEAS)))->toBeGreaterThan(0);
});

test('a topic no published row carries is absent from both facet rows', function () {
    $lonely = Topic::factory()->create(['name' => 'Nothing Filed Here']);

    $this->assertCount(0, taxonomy()->topicChips(PublicTaxonomy::IDEAS));
    $this->assertCount(0, taxonomy()->topicChips(PublicTaxonomy::SNIPPETS));
    $this->assertNull(taxonomy()->topic(PublicTaxonomy::IDEAS, $lonely->slug));
    $this->assertNull(taxonomy()->topic(PublicTaxonomy::SNIPPETS, $lonely->slug));
    $this->assertFalse(taxonomy()->isBrowseable($lonely));
});

test('the ideas facet counts published ideas only', function () {
    $topic = Topic::factory()->create();

    Idea::factory()->published()->count(2)->create()->each(
        fn (Idea $idea) => $idea->topics()->sync([$topic->id])
    );

    // The inbox row has the same subject attached, and a stranger must not be
    // told two when the page will open three.
    Idea::factory()->create()->topics()->sync([$topic->id]);

    $chip = taxonomy()->topicChips(PublicTaxonomy::IDEAS)->firstWhere('id', $topic->id);
    $this->assertNotNull($chip);
    $this->assertSame(2, $chip->ideas_count);
    $this->assertSame(2, taxonomy()->publishedCount(PublicTaxonomy::IDEAS, $topic->slug));

    // The other family is a separate list: an idea is not a snippet.
    $this->assertNull(taxonomy()->topicChips(PublicTaxonomy::SNIPPETS)->firstWhere('id', $topic->id));
});

test('the snippet facet counts published snippets only', function () {
    $topic = Topic::factory()->create();

    LabSnippet::factory()->published()->count(3)->create()->each(
        fn (LabSnippet $snippet) => $snippet->topics()->sync([$topic->id])
    );

    LabSnippet::factory()->create()->topics()->sync([$topic->id]);

    $chip = taxonomy()->topicChips(PublicTaxonomy::SNIPPETS)->firstWhere('id', $topic->id);
    $this->assertNotNull($chip);
    $this->assertSame(3, $chip->lab_snippets_count);
});

test('the facet row is ordered by how much it holds, then by the operator order', function () {
    $quiet = Topic::factory()->create(['sort_order' => 0]);
    $busy = Topic::factory()->create(['sort_order' => 9]);

    LabSnippet::factory()->published()->create()->topics()->sync([$quiet->id]);
    LabSnippet::factory()->published()->count(3)->create()->each(
        fn (LabSnippet $snippet) => $snippet->topics()->sync([$busy->id])
    );

    $this->assertSame(
        [$busy->slug, $quiet->slug],
        taxonomy()->topicChips(PublicTaxonomy::SNIPPETS)->pluck('slug')->all()
    );
});

test('a deactivated topic leaves the facet rows even while published rows still carry it', function () {
    $topic = Topic::factory()->create();
    LabSnippet::factory()->published()->create()->topics()->sync([$topic->id]);

    $this->assertNotNull(taxonomy()->topic(PublicTaxonomy::SNIPPETS, $topic->slug));

    $topic->update(['is_active' => false]);

    // A fresh instance of the service, because the previous one has memoised the
    // answer for its request: switching a topic off takes effect at the next page
    // view, not in the middle of rendering this one.
    $after = new PublicTaxonomy;
    $this->assertNull($after->topic(PublicTaxonomy::SNIPPETS, $topic->slug));
    $this->assertNull($after->topic(PublicTaxonomy::IDEAS, $topic->slug));
    $this->assertFalse($after->isBrowseable($topic));

    // The author-facing picker is the one surface that keeps offering it, because
    // the published snippet still holds the pivot and the box has to exist so the
    // row can be re-filed once the topic comes back on.
    $this->assertNotNull($after->topicForEditor($topic->slug));
});

test('the editor picker still offers a deactivated topic an existing row carries', function () {
    $topic = Topic::factory()->create(['name' => 'Retired Subject']);

    // A private idea carries it, which is precisely the case the picker has to
    // survive: the topic is off to the public, the author still holds it, and
    // hiding the box would leave the pivot attached with no way to un-check it.
    Idea::factory()->create()->topics()->sync([$topic->id]);
    $topic->update(['is_active' => false]);

    $option = taxonomy()->topicForEditor($topic->slug);
    $this->assertNotNull($option);
    $this->assertSame('Retired Subject', $option->name);

    // A switched-off topic nobody filed anything under is not offered either.
    $unused = Topic::factory()->create();
    $unused->update(['is_active' => false]);
    $this->assertNull(taxonomy()->topicForEditor($unused->slug));
});

test('the editor picker offers every active topic and nothing else', function () {
    $usable = Topic::factory()->create(['sort_order' => 2]);
    $retiredWithRows = Topic::factory()->create(['sort_order' => 1]);
    $retiredAndEmpty = Topic::factory()->create(['sort_order' => 0]);

    LabSnippet::factory()->create()->topics()->sync([$retiredWithRows->id]);
    $retiredWithRows->update(['is_active' => false]);
    $retiredAndEmpty->update(['is_active' => false]);

    $this->assertSame(
        [$retiredWithRows->slug, $usable->slug],
        taxonomy()->pickerTopics()->pluck('slug')->all()
    );
});

test('the index adds a family only where that family has a published row', function () {
    $shared = Topic::factory()->create(['name' => 'Shared Subject']);
    $ideaOnly = Topic::factory()->create(['name' => 'Idea Only']);

    Idea::factory()->published()->create()->topics()->sync([$shared->id, $ideaOnly->id]);
    LabSnippet::factory()->published()->create()->topics()->sync([$shared->id]);

    $indexed = taxonomy()->indexed();

    $this->assertSame(
        ['ideas_count' => 1, 'lab_snippets_count' => 1],
        $indexed->firstWhere('slug', $shared->slug)
            ->only(['ideas_count', 'lab_snippets_count'])
    );

    $quiet = $indexed->firstWhere('slug', $ideaOnly->slug);
    $this->assertSame(1, $quiet->ideas_count);
    $this->assertSame(0, $quiet->lab_snippets_count);
    $this->assertTrue(taxonomy()->isBrowseable($ideaOnly));
});

test('the index row carries both counts so a topic card can say what opens', function () {
    $topic = Topic::factory()->create(['name' => 'Two Families']);
    Idea::factory()->published()->count(2)->create()->each(
        fn (Idea $idea) => $idea->topics()->sync([$topic->id])
    );
    LabSnippet::factory()->published()->create()->topics()->sync([$topic->id]);

    $row = taxonomy()->indexed()->firstWhere('slug', $topic->slug);
    $this->assertNotNull($row);
    $this->assertSame(2, $row->ideas_count);
    $this->assertSame(1, $row->lab_snippets_count);
});

test('a topic is counted through its pivot rather than through the rows themselves', function () {
    $topic = Topic::factory()->create();
    $other = Topic::factory()->create();

    $idea = Idea::factory()->published()->create();
    $idea->topics()->sync([$topic->id, $other->id]);

    // Both chips read one, and the idea is not counted twice into one number.
    $this->assertSame(1, taxonomy()->publishedCount(PublicTaxonomy::IDEAS, $topic->slug));
    $this->assertSame(1, taxonomy()->publishedCount(PublicTaxonomy::IDEAS, $other->slug));
});

test("an author's private folder name reaches no screen a stranger can open", function () {
    // The two layers of the taxonomy are allowed to say opposite things, and this
    // pins the difference: a published idea is public, the folder it sat in is not.
    $idea = Idea::factory()->published()->create();
    $category = IdeaCategory::factory()->create([
        'user_id' => $idea->user_id,
        'name' => 'Clients Not Billed Yet',
    ]);
    $idea->update(['idea_category_id' => $category->id]);

    $topic = Topic::factory()->create(['name' => 'Invoicing']);
    $idea->topics()->sync([$topic->id]);
    LabSnippet::factory()->published()->create()->topics()->sync([$topic->id]);

    foreach ([
        route('ideas.index'),
        route('ideas.show', ['idea' => $idea->slug]),
        route('topics.index'),
        route('topics.show', ['topic' => $topic->slug]),
        route('dev-lab.gallery'),
    ] as $url) {
        $this->get($url)->assertOk()->assertDontSee('Clients Not Billed Yet');
    }

    // The author reading the same public index sees their own filing, because it is
    // theirs. The card's rule is ownership, not page: which is why the card, not the
    // screen, is where it is written.
    $this->actingAs($idea->user)
        ->get(route('ideas.index'))
        ->assertOk()
        ->assertSee('Clients Not Billed Yet');
});
