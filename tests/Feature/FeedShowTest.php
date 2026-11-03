<?php

use App\Models\FeedPost;
use Database\Seeders\FeedPostSeeder;

use function Pest\Laravel\get;

/**
 * The discussion detail — /feed/{slug} with the interactive review diff.
 *
 * The thread resolves from the seeded corpus through {@see FeedPost}; a post
 * that carries no authored detail content is not a thread and 404s, and a
 * post moderation removed cannot be read either.
 */
beforeEach(function (): void {
    $this->seed(FeedPostSeeder::class);
});

it('renders the rust async discussion with its patch and review comments', function (): void {
    get(route('feed.show', ['slug' => 'rust-async-stream-processing']))
        ->assertOk()
        ->assertSee('Rust async stream processing')
        ->assertSee('src/ingest.rs')
        ->assertSee('buffer_unordered(8)', false)
        ->assertSee('has review comments, activate to read', false)
        ->assertSee('Tanzil Ahmed')
        ->assertSee('The conversation');
});

it('renders blocked and added lines in the diff', function (): void {
    $response = get(route('feed.show', ['slug' => 'rust-async-stream-processing']));

    $response->assertOk();
    // The blocking loop is deleted; the stream composition is added.
    expect($response->getContent())
        ->toContain('diff-line kind-del')
        ->toContain('diff-line kind-add')
        ->toContain('blocking_fetch(cursor, PAGE_SIZE)');
});

it('404s for slugs that carry no discussion detail', function (): void {
    get(route('feed.show', ['slug' => 'eloquent-n1-dashboard']))->assertNotFound();
});

it('404s for unknown slugs', function (): void {
    get(route('feed.show', ['slug' => 'privilege-escalation']))->assertNotFound();
});

it('404s for a thread moderation removed', function (): void {
    FeedPost::query()->where('slug', 'rust-async-stream-processing')->update(['hidden' => true]);

    get(route('feed.show', ['slug' => 'rust-async-stream-processing']))->assertNotFound();
});
