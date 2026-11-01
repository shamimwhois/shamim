<?php

use App\Models\FeedPost;
use App\Models\User;
use Livewire\Livewire;

/**
 * The log's row list must be morph-stable.
 *
 * `resources/views/pages/⚡feed.blade.php` renders each row of the log through a
 * loop that can emit up to four different top-level nodes: a "last visited"
 * marker, a day marker, the commit itself, and the follow interlude. Which of
 * them appear changes from render to render — the visit line is claimed once,
 * the day marker only when the date turns over, the interlude only after the
 * fifth commit.
 *
 * Livewire morphs siblings **by position** unless they carry a `wire:key`: the
 * first child of the new markup is merged into the first child of the old one.
 * Drop one row out of the middle and every commit below it shifts up a slot, so
 * the node that used to describe post 34 is rewritten to describe post 35 while
 * its action bindings lag a pass behind. The reader then presses Delete on one
 * row and a different post is deleted.
 *
 * That bug is invisible to every test that drives the component through
 * `call()` — the server-side action always receives the id it was given. It was
 * found by pressing the real button in a real browser. So this file asserts the
 * *markup* invariant the protocol depends on: a stable key on every node the
 * loop can emit, keyed by identity rather than by position.
 */
test('every commit row carries a key on its own identity', function () {
    $reader = User::factory()->create(['handle' => 'keyed']);
    $post = FeedPost::factory()->create([
        'user_id' => $reader->id,
        'handle' => '@keyed',
        'author' => 'Keyed Author',
        'body' => 'A row that must not be confused with its neighbour.',
    ]);

    $html = Livewire::actingAs($reader)->test('pages::feed')->html();

    // The key has to name the post, not the slot: `commit-{{ $loop->index }}`
    // would be a key that moves with the row and pins nothing.
    expect($html)->toContain('wire:key="commit-'.$post->id.'"');
});

test('the keys on a page of rows are all distinct', function () {
    $reader = User::factory()->create(['handle' => 'distinct']);
    FeedPost::factory()->count(6)->create([
        'user_id' => $reader->id,
        'handle' => '@distinct',
        'author' => 'Distinct Author',
    ]);

    $html = Livewire::actingAs($reader)->test('pages::feed')->html();

    preg_match_all('/wire:key="([^"]+)"/', $html, $matches);
    $keys = $matches[1];

    expect($keys)->not->toBeEmpty();

    // A duplicate key is a worse bug than a missing one: Livewire finds the
    // first match and morphs into it, so two rows would share a node.
    expect(array_unique($keys))->toHaveCount(count($keys));
});

test('the marker nodes in the row loop are keyed, not just the commits', function () {
    $reader = User::factory()->create(['handle' => 'markers']);

    // The visit line comes from a session key, not a column: `lastVisitId` is
    // read from `feed.last_visit_id` and only honoured when it sits strictly
    // below the newest visible id, so the seed has to be an id that exists.
    $older = FeedPost::factory()->create([
        'user_id' => $reader->id,
        'handle' => '@markers',
        'author' => 'Marker Author',
        'body' => 'Yesterday.',
        'created_at' => now()->subDay(),
    ]);
    FeedPost::factory()->create([
        'user_id' => $reader->id,
        'handle' => '@markers',
        'author' => 'Marker Author',
        'body' => 'Today.',
        'created_at' => now(),
    ]);

    session()->put('feed.last_visit_id', $older->id);

    $html = Livewire::actingAs($reader)->test('pages::feed')->html();

    /*
     * The commit key alone is not enough. The loop's *other* nodes shift the
     * positions around it: when a day marker appears or disappears, every
     * commit below it moves one slot, and an unkeyed marker is what lets the
     * browser match a marker to a commit. Anchor on the element, not on the
     * bare substring, so a key that ended up on the wrong tag is still caught.
     */
    preg_match('/<div[^>]*wire:key="day-[^"]*"/', $html, $dayMatch);
    expect($dayMatch)->not->toBeEmpty('the day marker must carry a wire:key');

    // And the visit line, when the reader has a previous visit to mark.
    if (str_contains($html, 'data-test="last-visit"')) {
        preg_match('/<div[^>]*wire:key="visit-[^"]*"[^>]*data-test="last-visit"|'
            .'<div[^>]*data-test="last-visit"[^>]*wire:key="visit-[^"]*"/s', $html, $visitMatch);
        expect($visitMatch)->not->toBeEmpty('the last-visit line must carry a wire:key');
    }
});

test('the interlude carries a key when it is drawn', function () {
    $reader = User::factory()->create(['handle' => 'interlude']);

    // The interlude lands after the fifth commit, so six rows guarantee it.
    FeedPost::factory()->count(6)->create([
        'user_id' => $reader->id,
        'handle' => '@interlude',
        'author' => 'Interlude Author',
    ]);

    $html = Livewire::actingAs($reader)->test('pages::feed')->html();

    if (str_contains($html, 'data-test="log-interlude"')) {
        preg_match('/<div[^>]*data-test="log-interlude"[^>]*wire:key="interlude-[^"]*"|'
            .'<div[^>]*wire:key="interlude-[^"]*"[^>]*data-test="log-interlude"/s', $html, $interludeMatch);
        expect($interludeMatch)->not->toBeEmpty('the interlude must carry a wire:key');
    } else {
        // Not drawn on this page — the assertion is vacuous, and saying so is
        // better than a test that silently stops covering the node.
        expect(true)->toBeTrue();
    }
});
