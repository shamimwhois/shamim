<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

test('the blog index lists published posts', function () {
    Post::factory()->published()->count(3)->create();
    Post::factory()->create(['title' => 'An unpublished draft']);

    $this->get(route('blog.index'))
        ->assertOk()
        ->assertDontSee('An unpublished draft');
});

test('drafts and archived posts never appear on the public index', function () {
    Post::factory()->published()->create(['title' => 'Live post']);
    Post::factory()->create(['title' => 'Draft post']);
    Post::factory()->archived()->create(['title' => 'Archived post']);

    $this->get(route('blog.index'))
        ->assertOk()
        ->assertSee('Live post')
        ->assertDontSee('Draft post')
        ->assertDontSee('Archived post');
});

test('the index can be filtered by category', function () {
    $php = Category::factory()->create(['name' => 'PHP', 'slug' => 'php']);
    $ops = Category::factory()->create(['name' => 'DevOps', 'slug' => 'devops']);

    Post::factory()->published()->for($php)->create(['title' => 'A PHP post']);
    Post::factory()->published()->for($ops)->create(['title' => 'A DevOps post']);

    $this->get(route('blog.index', ['category' => 'php']))
        ->assertOk()
        ->assertSee('A PHP post')
        ->assertDontSee('A DevOps post');
});

test('the index can be filtered by tag', function () {
    $laravel = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);
    $redis = Tag::factory()->create(['name' => 'Redis', 'slug' => 'redis']);

    $withLaravel = Post::factory()->published()->create(['title' => 'Queues in Laravel']);
    $withLaravel->tags()->attach($laravel);

    $withRedis = Post::factory()->published()->create(['title' => 'Caching with Redis']);
    $withRedis->tags()->attach($redis);

    $this->get(route('blog.index', ['tag' => 'laravel']))
        ->assertOk()
        ->assertSee('Queues in Laravel')
        ->assertDontSee('Caching with Redis');
});

test('the index can be searched by term', function () {
    Post::factory()->published()->create(['title' => 'Understanding database indexes']);
    Post::factory()->published()->create(['title' => 'A guide to watercolor painting']);

    $this->get(route('blog.index', ['q' => 'database']))
        ->assertOk()
        ->assertSee('Understanding database indexes')
        ->assertDontSee('A guide to watercolor painting');
});

test('search terms with wildcards are matched literally', function () {
    Post::factory()->published()->create(['title' => 'Everything about percentages']);
    Post::factory()->published()->create(['title' => 'Nothing relevant here']);

    // A bare % would match every row if it were interpolated unescaped.
    $this->get(route('blog.index', ['q' => '%']))
        ->assertOk()
        ->assertDontSee('Everything about percentages');
});

test('filtered and paged views are excluded from indexing', function () {
    Post::factory()->published()->count(3)->create();

    $this->get(route('blog.index'))->assertDontSee('noindex');

    $this->get(route('blog.index', ['q' => 'anything']))
        ->assertSee('<meta name="robots" content="noindex', escape: false);
});

test('an empty result set shows a helpful empty state', function () {
    Post::factory()->published()->create();

    $this->get(route('blog.index', ['q' => 'zzzz-no-match']))
        ->assertOk()
        ->assertSeeText('No posts match those filters');
});

test('the index paginates rather than loading every post', function () {
    Post::factory()->published()->count(30)->create();

    $response = $this->get(route('blog.index'));
    $response->assertOk();

    expect($response->getContent())
        ->toContain('page=2');
});

test('the index wears the log frame, not the marketing header', function () {
    Post::factory()->published()->create();

    // The blog is a module of the log, like the community directory, the AI
    // hub, Ideas and the Dev Lab, so it renders the application shell. It used
    // to fall back to the public layout as well, which put the site header on
    // top of the log's own nav rail: two navigations on one page.
    $this->get(route('blog.index'))
        ->assertOk()
        ->assertSee('dn-shell', false)
        ->assertSee('dn-nav', false)
        ->assertDontSee('Toggle navigation', false)
        ->assertDontSee('All rights reserved', false);
});
