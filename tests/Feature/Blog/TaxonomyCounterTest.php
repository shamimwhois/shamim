<?php

use App\Actions\Posts\PublishPost;
use App\Actions\Posts\SyncTaxonomyCounts;
use App\Actions\Posts\UnpublishPost;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

/*
|--------------------------------------------------------------------------
| The denormalised taxonomy counters
|--------------------------------------------------------------------------
|
| `categories.posts_count` and `tags.posts_count` are written rather than
| counted at read time, which means they are only ever as true as the last
| reconciliation. The rule the whole design rests on is that a recount is
| idempotent: it recalculates from the rows instead of adding and subtracting,
| so a transition that runs twice — or a code path that forgets to touch a
| counter — heals on the next write instead of drifting permanently.
|
| Nothing else in the suite writes a wrong number on purpose, which is the only
| way to tell a recount from an increment.
|
*/

function drifted(Category $category, Tag $tag, int $wrong): void
{
    $category->forceFill(['posts_count' => $wrong])->save();
    $tag->forceFill(['posts_count' => $wrong])->save();
}

it('recalculates the counters from the rows instead of adding to what is there', function (): void {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $post = Post::factory()->published()->create(['category_id' => $category->id]);
    $post->tags()->attach($tag);

    drifted($category, $tag, 17);

    app(SyncTaxonomyCounts::class)->handle($post);

    expect($category->fresh()->posts_count)->toBe(1)
        ->and($tag->fresh()->posts_count)->toBe(1);
});

it('says the same thing twice, so a transition that runs twice costs nothing', function (): void {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $post = Post::factory()->published()->create(['category_id' => $category->id]);
    $post->tags()->attach($tag);

    $sync = app(SyncTaxonomyCounts::class);
    $sync->handle($post);
    $sync->handle($post);
    $sync->handle($post);

    expect($category->fresh()->posts_count)->toBe(1)
        ->and($tag->fresh()->posts_count)->toBe(1);
});

it('counts only the published posts in a category', function (): void {
    $category = Category::factory()->create();

    Post::factory()->published()->count(2)->create(['category_id' => $category->id]);
    $draft = Post::factory()->create(['category_id' => $category->id]);

    drifted($category, Tag::factory()->create(), 0);
    $category->forceFill(['posts_count' => 9])->save();

    app(SyncTaxonomyCounts::class)->handle($draft);

    expect($category->fresh()->posts_count)->toBe(2);
});

it('heals a counter on a row the reconciling post never mentions', function (): void {
    $untouched = Category::factory()->create();
    $quietTag = Tag::factory()->create();
    $post = Post::factory()->published()->create(['category_id' => $untouched->id]);
    $post->tags()->attach($quietTag);

    drifted($untouched, $quietTag, 42);

    // A full sweep is what an operator runs after importing rows by hand: it walks
    // every category and tag, not only the ones the current post touches.
    app(SyncTaxonomyCounts::class)->handleAll();

    expect($untouched->fresh()->posts_count)->toBe(1)
        ->and($quietTag->fresh()->posts_count)->toBe(1);
});

it('drops a tag count when the pivot goes away underneath it', function (): void {
    $tag = Tag::factory()->create();
    $post = Post::factory()->published()->create();
    $post->tags()->attach($tag);

    app(SyncTaxonomyCounts::class)->handle($post);
    expect($tag->fresh()->posts_count)->toBe(1);

    // Detaching writes the pivot directly and bypasses any observer, which is
    // exactly the drift the recount exists to survive.
    $post->tags()->detach($tag);
    app(SyncTaxonomyCounts::class)->handle($post);

    expect($tag->fresh()->posts_count)->toBe(0);
});

it('leaves a post without a category to recount its tags alone', function (): void {
    $tag = Tag::factory()->create();
    $post = Post::factory()->published()->create(['category_id' => null]);
    $post->tags()->attach($tag);

    drifted(Category::factory()->create(), $tag, 5);

    app(SyncTaxonomyCounts::class)->handle($post);

    expect($tag->fresh()->posts_count)->toBe(1);
});

it('follows the counter through a real status transition', function (): void {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $post = Post::factory()->create(['category_id' => $category->id]);
    $post->tags()->attach($tag);

    // Draft at the start: the transition actions are what move the number.
    expect($category->fresh()->posts_count)->toBe(0)
        ->and($tag->fresh()->posts_count)->toBe(0);

    app(PublishPost::class)->handle($post->fresh());
    expect($post->fresh()->isPublished())->toBeTrue()
        ->and($category->fresh()->posts_count)->toBe(1)
        ->and($tag->fresh()->posts_count)->toBe(1);

    app(UnpublishPost::class)->handle($post->fresh());
    expect($category->fresh()->posts_count)->toBe(0)
        ->and($tag->fresh()->posts_count)->toBe(0);
});
