<?php

use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use Livewire\Livewire;

/*
 * The landing page is public and it is composed entirely of `#[Computed]`
 * queries, which means almost everything it claims can be checked by writing
 * rows and reading the component back. These tests drive that: what is a lead
 * item, what never appears at all, and which numbers the stats band is
 * allowed to print.
 */

test('the writing section leads with the featured post and does not show it twice', function () {
    $lead = Post::factory()->published()->featured()->create(['title' => 'The lead article']);
    $second = Post::factory()->published()->create(['title' => 'A second article']);

    $component = Livewire::test('pages::home');

    expect($component->instance()->featured->pluck('id')->all())->toBe([$lead->id])
        // `latest` is defined as "everything else", so the lead must not repeat.
        ->and($component->instance()->latest->pluck('id')->all())->toBe([$second->id])
        ->and($component->instance()->posts->pluck('id')->sort()->values()->all())
        ->toBe([$lead->id, $second->id]);

    $component->assertSee('The lead article')->assertSee('A second article');
});

test('a draft never reaches the landing page', function () {
    Post::factory()->published()->create(['title' => 'Ready and published']);
    Post::factory()->create(['title' => 'Still a draft']);

    $component = Livewire::test('pages::home');

    expect($component->instance()->posts->pluck('title')->all())->toBe(['Ready and published']);

    $component->assertSee('Ready and published')->assertDontSee('Still a draft');
});

test('the writing section is capped at the configured number of posts', function () {
    config(['portfolio.blog.latest_limit' => 3]);

    foreach (range(1, 5) as $number) {
        Post::factory()->published()->create(['title' => "Article {$number}"]);
    }

    expect(Livewire::test('pages::home')->instance()->posts)->toHaveCount(3);
});

test('the topic strip lists only categories that actually have published writing', function () {
    $withPublished = Category::factory()->create(['name' => 'Architecture']);
    $draftOnly = Category::factory()->create(['name' => 'Unfinished']);
    Category::factory()->create(['name' => 'Empty']);

    Post::factory()->published()->create(['category_id' => $withPublished->id]);
    Post::factory()->create(['category_id' => $draftOnly->id]);

    $titles = Livewire::test('pages::home')->instance()->categories->pluck('name')->all();

    expect($titles)->toBe(['Architecture']);
});

test('a post card counts approved comments only', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->approved()->create(['post_id' => $post->id]);
    Comment::factory()->create(['post_id' => $post->id]);
    Comment::factory()->rejected()->create(['post_id' => $post->id]);

    $counts = Livewire::test('pages::home')->instance()->posts->pluck('comments_count', 'id');

    expect($counts[$post->id])->toBe(1);
});

test('the tag strip drops a tag whose only post is unpublished', function () {
    $tag = Tag::factory()->create(['name' => 'Testing']);
    $published = Tag::factory()->create(['name' => 'Routing']);

    $post = Post::factory()->published()->create();
    $post->tags()->attach($published->id);

    $draft = Post::factory()->create();
    $draft->tags()->attach($tag->id);

    expect(Livewire::test('pages::home')->instance()->tags->pluck('name')->all())->toBe(['Routing']);
});

test('the stats band reports what the tables actually hold', function () {
    Post::factory()->published()->count(2)->create();
    Post::factory()->count(3)->create();
    Post::factory()->archived()->create();

    $commented = Post::factory()->published()->create();
    Comment::factory()->approved()->create(['post_id' => $commented->id]);
    Comment::factory()->create(['post_id' => $commented->id]);

    $metrics = Livewire::test('pages::home')->instance()->metrics;

    expect($metrics->totalPosts)->toBe(7)
        ->and($metrics->publishedPosts)->toBe(3)
        ->and($metrics->draftPosts)->toBe(3)
        ->and($metrics->archivedPosts)->toBe(1)
        ->and($metrics->totalComments)->toBe(2)
        ->and($metrics->pendingComments)->toBe(1);
});

test('the landing page is reachable by a guest', function () {
    Post::factory()->published()->create(['title' => 'Readable without an account']);

    $this->get(route('home'))->assertOk()->assertSee('Readable without an account');
});
