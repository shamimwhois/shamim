<?php

use App\Enums\FeedPostAudience;
use App\Enums\FeedPostStatus;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Feed post visibility
|--------------------------------------------------------------------------
|
| `isVisibleTo()` is the one predicate every surface asks before it renders a
| post, and `isPublishedNow()` is the publish gate underneath it. Both are pure
| functions of columns the row already carries, so they are tested here with no
| database at all: a failure points at the rule, never at a fixture.
|
| The membership list is passed in rather than looked up, which is how the feed
| page calls it to stay inside its query budget. The fallback branch that does
| query is exercised in the Feature suite.
|
*/

/**
 * An account that exists only as an id, because visibility is decided on ids.
 */
function reader(int $id): User
{
    return (new User)->forceFill(['id' => $id, 'name' => "Reader {$id}", 'email' => "reader{$id}@example.test"]);
}

/*
|--------------------------------------------------------------------------
| The publish gate
|--------------------------------------------------------------------------
*/

test('a published post with no schedule is live immediately', function () {
    expect(feedPost(['status' => FeedPostStatus::Published, 'scheduled_at' => null])->isPublishedNow())->toBeTrue();
});

test('a published post scheduled for the future is not live yet', function () {
    expect(feedPost(['status' => FeedPostStatus::Published, 'scheduled_at' => now()->addMinute()])->isPublishedNow())->toBeFalse();
});

test('a published post whose scheduled moment has passed is live', function () {
    expect(feedPost(['status' => FeedPostStatus::Published, 'scheduled_at' => now()->subMinute()])->isPublishedNow())->toBeTrue();
});

test('a draft is never live no matter what its timestamps say', function () {
    expect(feedPost(['status' => FeedPostStatus::Draft, 'scheduled_at' => now()->subYear()])->isPublishedNow())->toBeFalse();
});

test('a scheduled post is not live until the publisher flips its status', function () {
    // The gate is deliberately two-factor: the cron flips `status`, and only the
    // combination of "published" and "due" opens it. A row that is merely past
    // its minute but still marked Scheduled stays shut.
    expect(feedPost(['status' => FeedPostStatus::Scheduled, 'scheduled_at' => now()->subMinute()])->isPublishedNow())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The audience matrix
|--------------------------------------------------------------------------
*/

test('a public post is readable by everyone, including a guest', function () {
    $post = feedPost(['audience' => FeedPostAudience::Public, 'user_id' => 7]);

    expect($post->isVisibleTo(null))->toBeTrue()
        ->and($post->isVisibleTo(reader(7)))->toBeTrue()
        ->and($post->isVisibleTo(reader(8), []))->toBeTrue();
});

test('a post addressed to the author alone is invisible to every other reader', function () {
    $post = feedPost(['audience' => FeedPostAudience::Self, 'user_id' => 7]);

    expect($post->isVisibleTo(reader(7)))->toBeTrue()
        ->and($post->isVisibleTo(reader(8)))->toBeFalse()
        ->and($post->isVisibleTo(null))->toBeFalse();
});

test('a self post with no author belongs to nobody', function () {
    // A corpus row that was never claimed cannot be "only me" for anybody, so it
    // must not leak to the first signed-in reader who asks.
    expect(feedPost(['audience' => FeedPostAudience::Self, 'user_id' => null])->isVisibleTo(reader(7)))->toBeFalse();
});

test('a community post is readable by members and refused to everyone else', function () {
    $post = feedPost(['audience' => FeedPostAudience::Community, 'user_id' => 7, 'community_id' => 42]);

    expect($post->isVisibleTo(reader(99), [42]))->toBeTrue()
        ->and($post->isVisibleTo(reader(99), [41, 43]))->toBeFalse()
        ->and($post->isVisibleTo(reader(99), []))->toBeFalse()
        ->and($post->isVisibleTo(null, [42]))->toBeFalse();
});

test('the author of a community post can always read it', function () {
    // Membership is a separate fact from authorship: the author may have left the
    // community, or posted into it before joining, and their own post must not
    // disappear from their own feed.
    $post = feedPost(['audience' => FeedPostAudience::Community, 'user_id' => 7, 'community_id' => 42]);

    expect($post->isVisibleTo(reader(7), []))->toBeTrue();
});

test('a community post with no community attached is readable by nobody but its author', function () {
    expect(feedPost(['audience' => FeedPostAudience::Community, 'user_id' => 7, 'community_id' => null])
        ->isVisibleTo(reader(99), [42]))->toBeFalse();
});

test('membership comparison is strict, so a string id cannot unlock a community post', function () {
    // `in_array(..., true)` is load-bearing: a loose comparison would let the
    // community id `0` or `'42abc'` match, quietly widening who can read.
    expect(feedPost(['audience' => FeedPostAudience::Community, 'user_id' => 7, 'community_id' => 42])
        ->isVisibleTo(reader(99), ['42']))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Moderation and status override audience
|--------------------------------------------------------------------------
*/

test('a hidden post is invisible to its own author', function () {
    $post = feedPost(['user_id' => 7, 'audience' => FeedPostAudience::Self, 'hidden' => true]);

    expect($post->isVisibleTo(reader(7)))->toBeFalse();
});

test('a removed post is invisible to its own author', function () {
    $post = feedPost(['user_id' => 7, 'audience' => FeedPostAudience::Self, 'deleted' => true]);

    expect($post->isVisibleTo(reader(7)))->toBeFalse();
});

test('a draft is invisible to a guest even though its audience is public', function () {
    expect(feedPost(['status' => FeedPostStatus::Draft, 'scheduled_at' => null])->isVisibleTo(null))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Every audience falls back to the same gate
|--------------------------------------------------------------------------
*/

test('no audience can be read while the publish gate is shut', function (FeedPostAudience $audience) {
    $post = feedPost([
        'status' => FeedPostStatus::Draft,
        'scheduled_at' => null,
        'audience' => $audience,
        'user_id' => 7,
        'community_id' => 42,
    ]);

    expect($post->isVisibleTo(reader(7)))->toBeFalse()
        ->and($post->isVisibleTo(reader(7), [42]))->toBeFalse()
        ->and($post->isVisibleTo(null, [42]))->toBeFalse();
})->with([
    FeedPostAudience::Public,
    FeedPostAudience::Self,
    FeedPostAudience::Community,
]);

test('a post defaults to published and public when nothing decides its state', function () {
    // The in-memory `$attributes` on the model exist so a row built before it
    // reaches the database already answers "may this be read" the way a saved row
    // would. If that default drifts, the predicate below is where it shows up.
    $post = feedPost();

    expect($post->status)->toBe(FeedPostStatus::Published)
        ->and($post->audience)->toBe(FeedPostAudience::Public)
        ->and($post->isVisibleTo(null))->toBeTrue();
});
