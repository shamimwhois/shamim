<?php

use App\Enums\AiArticleFormat;
use App\Enums\AiDifficulty;
use App\Enums\AiResourceKind;
use App\Models\AiArticle;
use App\Models\AiLearningPath;
use App\Models\AiResource;
use App\Models\AiTopic;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Public hub surfaces
|--------------------------------------------------------------------------
|
| The reading side of the hub is a guest-readable catalogue that shows
| Published rows and nothing else. These tests pin the visibility rule at
| every surface — landing, article index, article page, topics, paths,
| resources — and the dynamic filters that share their state through the
| query string.
|
*/

test('a guest reads the landing page and meets only published writing', function () {
    AiArticle::factory()->published()->featured()->create(['title' => 'Ship Ready Article']);
    AiArticle::factory()->create(['title' => 'Unlisted Working Draft']);

    $this->get('/ai-hub')
        ->assertOk()
        ->assertSee('Ship Ready Article')
        ->assertDontSee('Unlisted Working Draft');
});

test('a guest opens a published article and reads its stored rendering', function () {
    $article = AiArticle::factory()
        ->published()
        ->body("# Retrieval Notes\n\nA sentence only the published body carries: chromatic marker.")
        ->create(['title' => 'Retrieval Notes']);

    $this->get(route('ai-hub.article', $article))
        ->assertOk()
        ->assertSee('Retrieval Notes')
        ->assertSee('chromatic marker');
});

test('an unpublished article is a 404 for everyone except its author', function () {
    $author = User::factory()->create();
    $article = AiArticle::factory()->create([
        'title' => 'Private Working Copy',
        'user_id' => $author->id,
    ]);

    $this->get(route('ai-hub.article', $article))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('ai-hub.article', $article))->assertNotFound();
    $this->actingAs($author)->get(route('ai-hub.article', $article))
        ->assertOk()
        ->assertSee('Private Working Copy');
});

test('the article index lists published writing only', function () {
    AiArticle::factory()->published()->create(['title' => 'Onward Published']);
    AiArticle::factory()->review()->create(['title' => 'Waiting For Review']);
    AiArticle::factory()->archived()->create(['title' => 'Shelved Away']);

    $this->get('/ai-hub/articles')
        ->assertOk()
        ->assertSee('Onward Published')
        ->assertDontSee('Waiting For Review')
        ->assertDontSee('Shelved Away');
});

test('the article index searches titles and body text', function () {
    AiArticle::factory()->published()
        ->body('# Vector Drift\n\nEverything about quantile reservoirs.')
        ->create(['title' => 'Vector Drift Explained']);
    AiArticle::factory()->published()->create(['title' => 'An Unrelated Piece']);

    Livewire::test('pages::ai-hub.articles')
        ->set('search', 'Vector Drift')
        ->assertSee('Vector Drift Explained')
        ->assertDontSee('An Unrelated Piece');

    Livewire::test('pages::ai-hub.articles')
        ->set('search', 'quantile reservoirs')
        ->assertSee('Vector Drift Explained')
        ->assertDontSee('An Unrelated Piece');
});

test('the article index filters by difficulty and format', function () {
    AiArticle::factory()->published()
        ->difficulty(AiDifficulty::Advanced)
        ->format(AiArticleFormat::Tutorial)
        ->create(['title' => 'Deep Dive Tutorial']);
    AiArticle::factory()->published()->create(['title' => 'Shallow Note']);

    Livewire::test('pages::ai-hub.articles')
        ->set('difficulty', AiDifficulty::Advanced->value)
        ->assertSee('Deep Dive Tutorial')
        ->assertDontSee('Shallow Note');

    Livewire::test('pages::ai-hub.articles')
        ->set('format', AiArticleFormat::Tutorial->value)
        ->assertSee('Deep Dive Tutorial')
        ->assertDontSee('Shallow Note');
});

test('the article index filters by topic chip and clears back to everything', function () {
    $topic = AiTopic::factory()->create(['slug' => 'evals-quality']);
    AiArticle::factory()->published()->withTopics([$topic->id])->create(['title' => 'Filed Under Evals']);
    AiArticle::factory()->published()->create(['title' => 'Unfiled Elsewhere']);

    Livewire::test('pages::ai-hub.articles')
        ->call('toggleTopic', $topic->slug)
        ->assertSee('Filed Under Evals')
        ->assertDontSee('Unfiled Elsewhere')
        ->call('clearFilters')
        ->assertSee('Filed Under Evals')
        ->assertSee('Unfiled Elsewhere');
});

test('the listing container survives a filter change', function () {
    AiArticle::factory()->published()->create(['title' => 'Keeper One']);

    Livewire::test('pages::ai-hub.articles')
        ->assertSee('data-test="ai-hub-articles"', false)
        ->set('search', 'Keeper')
        ->assertSee('data-test="ai-hub-articles"', false)
        ->set('search', 'nothing matches this term')
        ->assertSee('data-test="ai-hub-articles"', false);
});

test('the landing search box hands its term to the article index', function () {
    Livewire::test('pages::ai-hub.index')
        ->set('search', 'prompting')
        ->call('submitSearch')
        ->assertRedirect(route('ai-hub.articles', ['q' => 'prompting']));
});

test('the topics shelf lists active topics and hides the retired one', function () {
    AiTopic::factory()->create(['name' => 'Prompt Engineering']);
    AiTopic::factory()->inactive()->create(['name' => 'Hardware Notes']);

    $this->get('/ai-hub/topics')
        ->assertOk()
        ->assertSee('Prompt Engineering')
        ->assertDontSee('Hardware Notes');
});

test('a topic page shows published filings and 404s once retired', function () {
    $topic = AiTopic::factory()->create(['name' => 'Evals Quality']);
    AiArticle::factory()->published()->withTopics([$topic->id])->create(['title' => 'Readable Under Evals']);
    AiArticle::factory()->withTopics([$topic->id])->create(['title' => 'Draft Under Evals']);

    $this->get(route('ai-hub.topic', $topic))
        ->assertOk()
        ->assertSee('Readable Under Evals')
        ->assertDontSee('Draft Under Evals');

    $topic->update(['is_active' => false]);

    $this->get(route('ai-hub.topic', $topic))->assertNotFound();
});

test('a topic page narrows its filings by difficulty', function () {
    $topic = AiTopic::factory()->create();
    AiArticle::factory()->published()->withTopics([$topic->id])
        ->difficulty(AiDifficulty::Expert)
        ->create(['title' => 'Expert Filing']);
    AiArticle::factory()->published()->withTopics([$topic->id])
        ->difficulty(AiDifficulty::Beginner)
        ->create(['title' => 'Beginner Filing']);

    $this->get(route('ai-hub.topic', $topic).'?difficulty=expert')
        ->assertOk()
        ->assertSee('Expert Filing')
        ->assertDontSee('Beginner Filing');
});

test('the paths index lists published routes only', function () {
    AiLearningPath::factory()->published()->create(['title' => 'A Published Route']);
    AiLearningPath::factory()->create(['title' => 'An Assembling Route']);

    $this->get('/ai-hub/paths')
        ->assertOk()
        ->assertSee('A Published Route')
        ->assertDontSee('An Assembling Route');
});

test('a path page walks its lessons in stored order and 404s while unpublished', function () {
    $first = AiArticle::factory()->published()->create(['title' => 'Lesson Alpha']);
    $second = AiArticle::factory()->published()->create(['title' => 'Lesson Beta']);
    $publishedPath = AiLearningPath::factory()->published()
        ->withLessons([$first->id, $second->id])
        ->create(['title' => 'Sequenced Route']);
    $draftPath = AiLearningPath::factory()->create(['title' => 'Hidden Route']);

    $this->get(route('ai-hub.path', $publishedPath))
        ->assertOk()
        ->assertSeeInOrder(['Lesson Alpha', 'Lesson Beta']);

    $this->get(route('ai-hub.path', $draftPath))->assertNotFound();
});

test('the resources shelf lists published entries and filters by kind', function () {
    AiResource::factory()->published()->kind(AiResourceKind::Tool)->create(['title' => 'Ollama Runner']);
    AiResource::factory()->published()->kind(AiResourceKind::Course)->create(['title' => 'Deep Learning Course']);
    AiResource::factory()->create(['title' => 'Unvetted Bookmark']);

    $this->get('/ai-hub/resources')
        ->assertOk()
        ->assertSee('Ollama Runner')
        ->assertSee('Deep Learning Course')
        ->assertDontSee('Unvetted Bookmark');

    Livewire::test('pages::ai-hub.resources')
        ->set('kind', AiResourceKind::Tool->value)
        ->assertSee('Ollama Runner')
        ->assertDontSee('Deep Learning Course');
});

test('a landing page stocked only with resources is not announced as empty', function () {
    // Resources publish on their own and are featured onto the landing shelf
    // without an article or a topic to hang from, so the empty state has to
    // wait for every shelf, not just the writing.
    AiResource::factory()->published()->featured()->create(['title' => 'A Lone Resource']);

    $this->get('/ai-hub')
        ->assertOk()
        ->assertSee('A Lone Resource')
        ->assertDontSee('The hub has no published content yet');
});

test('a hub slug that matches no shelf is a 404, not a server error', function () {
    // The retired `ai-hub.show` alias pointed at a view that no longer
    // exists; a stray or bookmarked hit on the path must fall through to the
    // router's 404 rather than blow up while compiling the page.
    $this->get('/ai-hub/ai-hub/nothing-here')->assertNotFound();
});

test('an over-long hero search is capped before it becomes a query string', function () {
    // The hero field is a relay to `/ai-hub/articles?q=…`, not a search engine
    // itself: the index's scope escapes whatever it receives, but no server in
    // front of the app is obliged to accept an essay-length query string, so
    // the relay caps the term before redirecting.
    Livewire::test('pages::ai-hub.index')
        ->set('search', str_repeat('a', 5000))
        ->call('submitSearch')
        ->assertRedirect(route('ai-hub.articles', ['q' => str_repeat('a', 200)]));
});
