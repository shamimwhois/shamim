<?php

use App\Enums\ContentVisibility;
use App\Enums\IdeaStatus;
use App\Enums\LabLanguage;
use App\Models\Category;
use App\Models\Idea;
use App\Models\LabExecution;
use App\Models\LabSnippet;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Database\Seeders\TopicsSeeder;

/*
|--------------------------------------------------------------------------
| Page two of a narrowed list
|--------------------------------------------------------------------------
|
| Every facet in this application is URL state, and every pagination control is a
| real link with `wire:navigate` on it (`vendor/pagination/devfolio.blade.php`), not
| a Livewire action. So the link's href is the whole contract: a reader who clicks
| "2" is performing a navigation, and the component is mounted again from that URL.
| If the href carries only `page`, the filter they chose is gone when they arrive,
| and page two of "everything published" is what they get while the subject chip
| they clicked is nowhere in the address bar.
|
| That is the failure `withQueryString()` exists for, and it is invisible to the
| component tests: those call `set()` on a property, the filter lives in PHP state,
| and the list narrows correctly no matter what the link says. So every case here is
| a real HTTP request against a URL a reader could actually be holding, and every
| assertion is about the rendered href rather than the rows.
|
| Each surface is fed one row past its own page size, because a list that does not
| paginate has nothing to be wrong about.
|
*/

/**
 * The page-size floor each list is configured with, so a test that needs page two
 * creates exactly one more row than the page holds.
 */
function filterTest_overpage(string $config): int
{
    return (int) config($config) + 1;
}

test('the ideas landing keeps the subject through page two', function () {
    $this->seed(TopicsSeeder::class);

    $topic = Topic::query()->where('slug', 'forms')->firstOrFail();
    $author = User::factory()->create(['handle' => 'marta']);

    Idea::factory()
        ->count(filterTest_overpage('ideas.per_page.public'))
        ->published()
        ->withTopics([$topic->id])
        ->create(['user_id' => $author->id]);

    $this->get('/ideas?topic=forms')
        ->assertOk()
        ->assertSee('topic=forms&amp;page=2', false);
});

test('the ideas landing keeps the query and the author through page two', function () {
    $author = User::factory()->create(['handle' => 'marta']);

    Idea::factory()
        ->count(filterTest_overpage('ideas.per_page.public'))
        ->published()
        ->create([
            'user_id' => $author->id,
            'title' => 'Container queries in a landing page',
        ]);

    $this->get('/ideas?q=container&author=marta')
        ->assertOk()
        ->assertSee('q=container&amp;author=marta&amp;page=2', false);
});

test('the gallery keeps language subject and author through page two', function () {
    $this->seed(TopicsSeeder::class);

    $topic = Topic::query()->where('slug', 'css')->firstOrFail();
    $author = User::factory()->create(['handle' => 'marta']);

    LabSnippet::factory()
        ->count(filterTest_overpage('dev_lab.per_page.public'))
        ->published()
        ->withTopics([$topic->id])
        ->create(['user_id' => $author->id, 'language' => 'html']);

    $this->get('/dev-lab/gallery?lang=html&topic=css&author=marta')
        ->assertOk()
        ->assertSee('lang=html&amp;topic=css&amp;author=marta&amp;page=2', false);
});

test('the blog index keeps its taxonomy through page two', function () {
    $category = Category::factory()->create(['slug' => 'guides']);

    Post::factory()
        ->count(filterTest_overpage('portfolio.blog.per_page'))
        ->published()
        ->create(['category_id' => $category->id]);

    $this->get('/blog?category=guides')
        ->assertOk()
        ->assertSee('category=guides&amp;page=2', false);
});

test('the ideas workspace keeps its tab and its search through page two', function () {
    $author = User::factory()->create();

    Idea::factory()
        ->count(filterTest_overpage('ideas.per_page.workspace'))
        ->create([
            'user_id' => $author->id,
            'title' => 'A capture to find again',
            'status' => IdeaStatus::Draft,
            'visibility' => ContentVisibility::Private,
        ]);

    $this->actingAs($author)
        ->get('/ideas/dashboard?tab=all&q=capture')
        ->assertOk()
        ->assertSee('tab=all&amp;q=capture&amp;page=2', false);
});

test('the snippet workspace keeps language and visibility through page two', function () {
    $author = User::factory()->create();

    LabSnippet::factory()
        ->count(filterTest_overpage('dev_lab.per_page.workspace'))
        ->create(['user_id' => $author->id, 'language' => 'php']);

    $this->actingAs($author)
        ->get('/dev-lab/snippets?lang=php&visibility=all')
        ->assertOk()
        ->assertSee('lang=php&amp;visibility=all&amp;page=2', false);
});

test('the run history keeps its language filter through page two', function () {
    $author = User::factory()->create();

    LabExecution::factory()
        ->count(filterTest_overpage('dev_lab.per_page.workspace'))
        ->create(['user_id' => $author->id, 'language' => LabLanguage::Javascript]);

    $this->actingAs($author)
        ->get('/dev-lab/history?lang=javascript')
        ->assertOk()
        ->assertSee('lang=javascript&amp;page=2', false);
});
