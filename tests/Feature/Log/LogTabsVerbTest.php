<?php

/**
 * The four commit verbs on all three logs.
 *
 * `?log=following|latest|trending` is ONE page with one set of actions, so the
 * verbs should not care which tab is showing. That is the property worth
 * pinning: `commits()` is shared, but `following` narrows to joined-community
 * topics and `trending` re-sorts, and a verb that silently stopped appearing on
 * one of them would look like "the button is gone" to a reader who happened to
 * arrive from a different tab.
 *
 * The trap this guards against is subtler than a missing button: `following`
 * can legitimately render an EMPTY log (a reader who joined nothing), and an
 * empty log is indistinguishable from a broken one in a screenshot. So each tab
 * is exercised twice — with a post it should show, and with the honest empty
 * state — and the verbs are asserted on the tab that can actually see a row.
 */
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\FeedPost;
use App\Models\FeedShare;
use App\Models\User;
use Livewire\Livewire;

/**
 * A post the given reader can act on, plus a topic the `following` log shows.
 *
 * @return array{0: User, 1: FeedPost}
 */
function tabPost(string $topic = 'Laravel', string $handle = 'tabauthor'): array
{
    $owner = User::factory()->create(['handle' => $handle]);

    $post = FeedPost::factory()->create([
        'user_id' => $owner->id,
        'handle' => '@'.$handle,
        'author' => 'Tab Author',
        'body' => 'A body the verbs can work against.',
        'topic' => $topic,
    ]);

    return [$owner, $post];
}

/** Put the reader inside the community that owns `$topic`, so `following` shows it. */
function readerInTopic(User $reader, string $topic): void
{
    // The factory's `topic()` state exists for exactly this: bind a community to
    // a feed topic the test controls. `firstOrCreate` bypasses it and trips the
    // NOT NULL on `description`.
    $community = Community::query()->where('topic', $topic)->first()
        ?? Community::factory()->topic($topic)->create();

    CommunityMembership::query()->firstOrCreate([
        'user_id' => $reader->id,
        'community_id' => $community->id,
    ]);
}

dataset('logs', ['following', 'latest', 'trending']);

test('every log offers the same four verbs on a row it shows', function (string $log) {
    [$owner, $post] = tabPost();
    readerInTopic($owner, 'Laravel');

    $instance = Livewire::actingAs($owner)->test('pages::feed', ['log' => $log]);

    // The tab must actually carry THIS row — otherwise the verb assertions
    // below would pass against some other post, or against nothing at all.
    expect($instance->instance()->commits->pluck('id')->all())->toContain($post->id);

    $html = $instance->html();
    expect($html)->toContain('data-test="share"');
    expect($html)->toContain('data-test="reply"');
    expect($html)->toContain('data-test="edit-commit"');
    expect($html)->toContain('data-test="delete-commit"');

    // And the URL state survived, so the verbs were drawn by the tab under test.
    expect($instance->instance()->log)->toBe($log);
})->with('logs');

test('share lands a ledger row from every log', function (string $log) {
    [$owner, $post] = tabPost();
    readerInTopic($owner, 'Laravel');
    $reader = User::factory()->create(['handle' => 'sharer-'.$log]);

    Livewire::actingAs($reader)
        ->test('pages::feed', ['log' => $log])
        ->call('shareCommit', $post->id);

    // One ledger row, same table, whichever tab did the sharing — the count the
    // row prints is derived from these rows, so the tab cannot change the count.
    expect(FeedShare::query()->where('feed_post_id', $post->id)->count())->toBe(1);
})->with('logs');

test('an edit saves from every log', function (string $log) {
    [$owner, $post] = tabPost();
    readerInTopic($owner, 'Laravel');

    $instance = Livewire::actingAs($owner)->test('pages::feed', ['log' => $log]);

    $instance->call('openEdit', $post->id);
    expect($instance->instance()->editingId)->toBe($post->id);
    expect($instance->html())->toContain('data-test="edit-form"');

    $instance->set('editDraft', 'Rewritten from the '.$log.' log.');
    $instance->call('saveEdit');

    expect($post->fresh()->body)->toBe('Rewritten from the '.$log.' log.');
    expect($instance->instance()->editingId)->toBeNull();
})->with('logs');

test('a reply is recorded from every log', function (string $log) {
    [$owner, $post] = tabPost();
    readerInTopic($owner, 'Laravel');

    $instance = Livewire::actingAs($owner)->test('pages::feed', ['log' => $log]);

    $instance->call('openReply', $post->id);
    $instance->set('replyDraft', 'Replied from the '.$log.' log.');
    $instance->call('postReply', $post->id);

    // `replies_count` is a `withCount` attribute, not a column — count the rows.
    expect($post->fresh()->replies()->count())->toBe(1);
})->with('logs');

test('delete tombstones and undoes from every log', function (string $log) {
    [$owner, $post] = tabPost();
    readerInTopic($owner, 'Laravel');

    $instance = Livewire::actingAs($owner)->test('pages::feed', ['log' => $log]);

    $instance->call('deleteCommit', $post->id);
    expect($post->fresh()->deleted)->toBeTrue();

    $instance->call('undoDelete');
    expect($post->fresh()->deleted)->toBeFalse();
})->with('logs');

test('the following log narrows to joined topics without losing the verbs', function () {
    // Two posts in two topics; the reader joins exactly one. `following` must
    // show one row and hide the other — and the row it keeps must still carry
    // every verb, because a filter that also strips the actions is a dead end.
    [$owner, $mine] = tabPost('Laravel', 'infollower');
    [, $other] = tabPost('Go', 'outofscope');
    readerInTopic($owner, 'Laravel');

    $instance = Livewire::actingAs($owner)->test('pages::feed', ['log' => 'following']);
    $ids = $instance->instance()->commits->pluck('id')->all();

    // Assert on the computed set, not on slug substrings in the HTML: slugs are
    // random per run, and a substring match over the whole page can be satisfied
    // by the aside, the rail or the snapshot blob rather than by the stream.
    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($other->id);

    $html = $instance->html();
    expect($html)->toContain('data-test="share"');
    expect($html)->toContain('data-test="edit-commit"');
    expect($html)->toContain('data-test="delete-commit"');
});

test('following is honestly empty for a reader who joined nothing', function () {
    [, $post] = tabPost('Laravel', 'nobodyfollows');
    $loner = User::factory()->create(['handle' => 'loner']);

    $instance = Livewire::actingAs($loner)->test('pages::feed', ['log' => 'following']);

    // Empty means empty: no row, so no verbs. This is the state that makes the
    // "verbs work on every log" claim non-vacuous — there is a tab where they
    // are legitimately absent, and it must be because the LOG is empty.
    expect($instance->instance()->commits->pluck('id')->all())->not->toContain($post->id);
    expect($instance->html())->not->toContain('data-test="share"');
});

test('trending re-sorts without dropping a row', function () {
    // Trending orders by stars*3 + reactions + replies*2.
    //
    // The factory mints a RANDOM reaction tally per post (~40-60 summed), which
    // is larger than the star weight this test can cheaply add — so a naive
    // "give one post five stars" fixture wins or loses at random. Zero the
    // reactions on both posts so the stars are the only thing that differs.
    [$quietOwner, $quiet] = tabPost('Laravel', 'quietpost');
    [$loudOwner, $loud] = tabPost('Laravel', 'loudpost');

    $quiet->forceFill(['reactions' => ['like' => 0, 'love' => 0, 'insight' => 0]])->save();
    $loud->forceFill(['reactions' => ['like' => 0, 'love' => 0, 'insight' => 0]])->save();

    // Both share the factory's `posted_at = now()`, so only the score separates
    // them — which is exactly the axis under test.
    foreach (range(1, 5) as $i) {
        $loud->stars()->create([
            'user_id' => User::factory()->create(['handle' => "st{$i}"])->id,
        ]);
    }

    $instance = Livewire::actingAs($loudOwner)->test('pages::feed', ['log' => 'trending']);

    // The computed log, in order — the same collection the stream renders.
    // Reading it beats `strpos` over the whole HTML, which also carries the
    // aside, the rail and a `wire:snapshot` blob where a slug can appear first.
    $order = $instance->instance()->commits->pluck('id')->all();

    expect(array_search($loud->id, $order, true))->toBeLessThan(array_search($quiet->id, $order, true));

    // Both rows survived the re-sort, and the promoted one still carries verbs.
    expect($order)->toContain($quiet->id);

    $html = $instance->html();
    expect($html)->toContain('data-test="share"');
    expect($html)->toContain('data-test="edit-commit"');
    expect($html)->toContain('data-test="delete-commit"');
});

test('an unknown log falls back to latest rather than blanking the verbs', function () {
    [$owner] = tabPost();
    readerInTopic($owner, 'Laravel');

    $instance = Livewire::actingAs($owner)->test('pages::feed', ['log' => 'nonsense']);

    expect($instance->instance()->log)->toBe('latest');
    expect($instance->html())->toContain('data-test="share"');
});

test('a shared row keeps its is-on mark whichever log is showing', function () {
    [$owner] = tabPost();
    readerInTopic($owner, 'Laravel');
    [, $post] = tabPost('Laravel', 'sharedintab');
    readerInTopic($owner, 'Laravel');

    $reader = User::factory()->create(['handle' => 'markreader']);

    foreach (['following', 'latest', 'trending'] as $log) {
        $share = Livewire::actingAs($reader)->test('pages::feed', ['log' => $log]);
        $share->call('shareCommit', $post->id);

        // The state is read from the ledger, so every tab agrees — no cached
        // flag that could be warm on one tab and cold on another.
        expect($share->instance()->sharedCommitIds)->toHaveKey($post->id);
    }

    expect(FeedShare::query()->where('feed_post_id', $post->id)->count())->toBe(1);
});
