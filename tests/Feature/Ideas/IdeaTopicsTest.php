<?php

use App\Actions\DevLab\SaveLabSnippet;
use App\Actions\Ideas\SaveIdea;
use App\Enums\ContentVisibility;
use App\Enums\IdeaStatus;
use App\Enums\LabLanguage;
use App\Models\Idea;
use App\Models\LabSnippet;
use App\Models\Topic;
use App\Models\User;
use Database\Seeders\TopicsSeeder;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Filing rows under public topics
|--------------------------------------------------------------------------
|
| The taxonomy is platform-owned and the author only picks from it, so the whole
| trust boundary sits in the sync: a hand-crafted list of ids must not be able to
| attach a topic that does not exist, one an operator has switched off, or more
| subjects than a chip row can hold.
|
| The second half of the boundary is the call path. Most writers know nothing about
| topics — the admin status edit, the sandbox's save-from-the-pane — and must not
| erase the subjects an author chose just because their payload has no `topics` key.
|
*/

test('saving an idea files it under the topics chosen', function () {
    $author = User::factory()->create();
    [$first, $second] = Topic::factory()->count(2)->create();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Ship the reading progress bar',
        'topics' => [$first->id, $second->id],
    ]);

    $this->assertSame(
        [$first->slug, $second->slug],
        $idea->topics->pluck('slug')->all()
    );
});

test('the chosen order is stored, so the first subject is the primary one', function () {
    $author = User::factory()->create();
    [$alpha, $beta] = Topic::factory()->count(2)->create();

    // Listed beta-first on purpose: the pivot must remember the submission order
    // rather than the topics' own sort order, because "primary" is the author's
    // statement, not the platform's.
    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Order the subjects',
        'topics' => [$beta->id, $alpha->id],
    ]);

    $this->assertSame($beta->id, $idea->primaryTopic()?->id);
    $this->assertSame(
        [0, 1],
        $idea->topics->pluck('pivot')->pluck('sort_order')->all()
    );
});

test('topics beyond the cap are dropped rather than stored', function () {
    $author = User::factory()->create();
    $topics = Topic::factory()->count(Topic::MAX_PER_ROW + 3)->create();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Everything at once',
        'topics' => $topics->pluck('id')->all(),
    ]);

    $this->assertCount(Topic::MAX_PER_ROW, $idea->fresh()->topics);
    $this->assertSame(
        $topics->take(Topic::MAX_PER_ROW)->pluck('id')->all(),
        $idea->fresh()->topics->pluck('id')->all()
    );
});

test('a topic that does not exist cannot be attached', function () {
    $author = User::factory()->create();
    $real = Topic::factory()->create();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'A list from a crafted request',
        'topics' => [$real->id, 999999],
    ]);

    // Dropped silently rather than refused: the box renders only what exists, so an
    // id that is not there came from somewhere other than the form.
    $this->assertSame([$real->id], $idea->fresh()->topics->pluck('id')->all());
});

test('a deactivated topic cannot be attached even though its row is there', function () {
    $author = User::factory()->create();
    $retired = Topic::factory()->inactive()->create();
    $live = Topic::factory()->create();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Filed under something switched off',
        'topics' => [$retired->id, $live->id],
    ]);

    $this->assertSame([$live->id], $idea->fresh()->topics->pluck('id')->all());
});

test('an id list with junk in it is filtered rather than fatal', function () {
    $author = User::factory()->create();
    $topic = Topic::factory()->create();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Strings, zeroes and repeats',
        'topics' => [(string) $topic->id, 'not-a-number', 0, $topic->id],
    ]);

    $this->assertSame([$topic->id], $idea->fresh()->topics->pluck('id')->all());
});

test('a payload without a topics key leaves the subjects an author chose alone', function () {
    $author = User::factory()->create();
    $topic = Topic::factory()->create();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Filed by subject',
        'topics' => [$topic->id],
    ]);

    // This is the workflow the moderation console runs: a status change arrives with
    // no taxonomy in it, and must not quietly unfile the idea.
    $changed = app(SaveIdea::class)->handle($author, [
        'title' => 'Filed by subject',
        'status' => IdeaStatus::UnderReview,
    ], $idea);

    $this->assertSame(IdeaStatus::UnderReview, $changed->status);
    $this->assertSame([$topic->id], $changed->topics->pluck('id')->all());
});

test('an empty topics list means clear it', function () {
    $author = User::factory()->create();
    $topic = Topic::factory()->create();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Filed then unfiled',
        'topics' => [$topic->id],
    ]);

    $cleared = app(SaveIdea::class)->handle($author, [
        'title' => 'Filed then unfiled',
        'topics' => [],
    ], $idea);

    $this->assertCount(0, $cleared->topics);
});

test('the saved idea reflects its new topics without a reload', function () {
    $author = User::factory()->create();
    [$first, $second] = Topic::factory()->count(2)->create();

    $idea = app(SaveIdea::class)->handle($author, [
        'title' => 'Reads back what it wrote',
        'topics' => [$first->id],
    ]);

    app(SaveIdea::class)->handle($author, [
        'title' => 'Reads back what it wrote',
        'topics' => [$second->id],
    ], $idea);

    // The action unsets the relation. Without that, this object would still carry
    // the first topic from its eager-loaded cache and the edit screen would render
    // a checkbox state the database no longer holds.
    $this->assertSame([$second->id], $idea->topics->pluck('id')->all());
});

test('the same rules govern snippets', function () {
    $author = User::factory()->create();
    [$live, $retired] = [Topic::factory()->create(), Topic::factory()->inactive()->create()];

    $snippet = app(SaveLabSnippet::class)->handle($author, [
        'title' => 'Focus ring that is actually visible',
        'language' => LabLanguage::Css,
        'code' => ':focus-visible { outline: 2px solid; }',
        'topics' => [$retired->id, (string) $live->id, 123456],
    ]);

    $this->assertSame([$live->id], $snippet->topics->pluck('id')->all());
    $this->assertSame([$live->name], $snippet->topicNames());

    app(SaveLabSnippet::class)->handle($author, [
        'title' => 'Focus ring that is actually visible',
        'visibility' => ContentVisibility::Public,
    ], $snippet);

    $this->assertSame([$live->id], $snippet->fresh()->topics->pluck('id')->all());
});

test('a snippet body over the byte ceiling is still refused with topics attached', function () {
    $author = User::factory()->create();
    $topic = Topic::factory()->create();

    expect(fn () => app(SaveLabSnippet::class)->handle($author, [
        'title' => 'Too large to store',
        'language' => LabLanguage::Html,
        'code' => str_repeat('<p>pad</p>', 4000),
        'topics' => [$topic->id],
    ]))->toThrow(InvalidArgumentException::class);

    // The transaction covers the pivot write, so a refused save files nothing.
    $this->assertSame(0, $topic->fresh()->labSnippets()->count());
});

test('the topic seeder is idempotent across runs', function () {
    $before = TopicsSeeder::defaults();

    (new TopicsSeeder)->run();
    $firstPass = Topic::query()->orderBy('id')->get(['slug', 'name'])->toArray();

    (new TopicsSeeder)->run();

    $this->assertSame($firstPass, Topic::query()->orderBy('id')->get(['slug', 'name'])->toArray());
    $this->assertSame(count($before), Topic::query()->count());
});

test('a re-seed refreshes the shipped names and leaves the switch alone', function () {
    (new TopicsSeeder)->run();

    Topic::query()->where('slug', 'css')->update(['name' => 'Styling']);
    Topic::query()->where('slug', 'testing')->update(['is_active' => false]);

    (new TopicsSeeder)->run();

    // The name is the platform's copy and a spelling fix has to be able to reach a
    // database seeded last month. Whether a subject is switched on is the
    // operator's decision, so a seed that re-activated a retired topic would put it
    // straight back in front of the public.
    $this->assertSame('CSS', Topic::query()->where('slug', 'css')->value('name'));
    $this->assertFalse((bool) Topic::query()->where('slug', 'testing')->value('is_active'));
});

/*
|--------------------------------------------------------------------------
| The picker on both edit screens
|--------------------------------------------------------------------------
|
| A checkbox group is the only place an author meets the taxonomy, so what is
| pinned here is what the group is allowed to contain and what a save driven by
| it actually writes.
|
*/

test('the idea form offers the active subjects and files the chosen ones', function () {
    $user = User::factory()->create();
    $topic = Topic::factory()->create(['name' => 'Accessibility']);

    $idea = Idea::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::ideas.edit', ['idea' => $idea])
        ->assertSee('Accessibility')
        ->set('topicIds', [(string) $topic->id])
        ->call('save')
        ->assertHasNoErrors();

    $this->assertSame([$topic->id], $idea->fresh()->topics->pluck('id')->all());
});

test('the idea form keeps a subject this row already holds even after it is retired', function () {
    $user = User::factory()->create();
    $retired = Topic::factory()->create(['name' => 'Frames']);
    $retired->update(['is_active' => false]);

    $idea = Idea::factory()->create(['user_id' => $user->id, 'title' => 'Held subject']);
    $idea->topics()->sync([$retired->id]);

    Livewire::actingAs($user)
        ->test('pages::ideas.edit', ['idea' => $idea])
        // The box is rendered checked, which is the only way the author can see
        // what they are holding and let it go.
        ->assertSee('Frames')
        ->set('topicIds', [])
        ->call('save')
        ->assertHasNoErrors();

    $this->assertCount(0, $idea->fresh()->topics);
});

test('the idea form refuses more subjects than the cap', function () {
    $user = User::factory()->create();
    $topics = Topic::factory()->count(Topic::MAX_PER_ROW + 1)->create();

    Livewire::actingAs($user)
        ->test('pages::ideas.edit')
        ->set('title', 'Filed under everything')
        ->set('topicIds', $topics->pluck('id')->map(fn (int $id): string => (string) $id)->all())
        ->call('save')
        ->assertHasErrors('topicIds');

    $this->assertSame(0, Idea::query()->where('title', 'Filed under everything')->count());
});

test('the snippet form files the chosen subjects', function () {
    $user = User::factory()->create();
    $topic = Topic::factory()->create(['name' => 'Forms']);

    Livewire::actingAs($user)
        ->test('pages::dev-lab.snippet')
        ->assertSee('Forms')
        ->set('title', 'A focus ring worth seeing')
        ->set('language', 'css')
        ->set('code', 'a:focus-visible { outline: 3px solid; }')
        ->set('topicIds', [(string) $topic->id])
        ->call('save')
        ->assertHasNoErrors();

    $snippet = LabSnippet::query()->firstWhere('title', 'A focus ring worth seeing');
    $this->assertNotNull($snippet);
    $this->assertSame([$topic->id], $snippet->topics->pluck('id')->all());
});
