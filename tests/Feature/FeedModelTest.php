<?php

use App\Models\FeedPost;
use Carbon\CarbonInterface;
use Database\Seeders\FeedPostSeeder;

use function Pest\Laravel\get;

/**
 * The FeedPost model — casts, scopes and the display helpers the feed
 * surfaces rely on. These pin the contract the pages are written against.
 */
beforeEach(function (): void {
    $this->seed(FeedPostSeeder::class);
});

it('casts the JSON columns to arrays and the flags to booleans', function (): void {
    $post = FeedPost::query()->where('slug', 'weekly-poll-runtimes')->first();

    expect($post->tags)->toBe(['Runtimes'])
        ->and($post->reactions)->toBe(['like' => 44, 'love' => 2, 'insight' => 6])
        ->and($post->poll['votes'])->toBe(4180)
        ->and($post->pinned)->toBeFalse()
        ->and($post->posted_at)->toBeInstanceOf(CarbonInterface::class);
});

it('casts the detail payload to an array with its nested diff', function (): void {
    $post = FeedPost::query()->where('slug', 'rust-async-stream-processing')->first();

    expect($post->hasDetail())->toBeTrue()
        ->and($post->detail['diff']['filename'])->toBe('src/ingest.rs')
        ->and(count($post->detail['diff']['lines']))->toBe(15)
        ->and($post->detail['replies'])->toHaveCount(3);
});

it('keeps a post without authored detail out of the withDetail scope', function (): void {
    expect(FeedPost::query()->withDetail()->count())->toBe(1)
        ->and(FeedPost::query()->where('slug', 'eloquent-n1-dashboard')->first()->hasDetail())->toBeFalse();
});

it('excludes hidden and deleted posts from the visible scope', function (): void {
    FeedPost::query()->where('slug', 'design-systems-hill')->update(['hidden' => true]);
    FeedPost::query()->where('slug', 'boring-tools')->update(['deleted' => true]);

    expect(FeedPost::query()->visible()->count())->toBe(8)
        ->and(FeedPost::query()->visible()->pluck('slug'))->not->toContain('design-systems-hill')
        ->and(FeedPost::query()->visible()->pluck('slug'))->not->toContain('boring-tools');
});

it('scopes the unanswered set to open questions only', function (): void {
    expect(FeedPost::query()->unanswered()->count())->toBe(1)
        ->and(FeedPost::query()->unanswered()->value('slug'))->toBe('eloquent-chunk-export');
});

it('orders the curated scope by curation, then recency', function (): void {
    $slugs = FeedPost::query()->curated()->pluck('slug');

    // The pinned-and-featured discussion floats above everything.
    expect($slugs->first())->toBe('rust-async-stream-processing');
});

it('binds routes by slug', function (): void {
    expect(FeedPost::query()->first()->getRouteKeyName())->toBe('slug');

    get(route('feed.show', ['slug' => 'rust-async-stream-processing']))->assertOk();
});

it('formats the score and sums reactions for display', function (): void {
    $post = FeedPost::query()->where('slug', 'eloquent-n1-dashboard')->first();

    expect($post->formattedScore())->toBe('210')
        ->and($post->reactionSum())->toBe(33)
        ->and($post->isQuestion())->toBeFalse()
        ->and($post->isSolved())->toBeFalse();
});

it('reads a solved question as solved and an unanswered one as not', function (): void {
    expect(FeedPost::query()->where('slug', 'tailwind-v4-dark-mode')->first()->isSolved())->toBeTrue()
        ->and(FeedPost::query()->where('slug', 'eloquent-chunk-export')->first()->isSolved())->toBeFalse();
});

it('stores relative times as real timestamps at seed time', function (): void {
    $post = FeedPost::query()->where('slug', 'eloquent-n1-dashboard')->first();

    // The corpus label "2h ago" becomes a timestamp two hours in the past.
    expect($post->posted_at->diffInHours(now()))->toBeLessThan(2.01)
        ->and($post->posted_at->diffInHours(now()))->toBeGreaterThan(1.99);
});
