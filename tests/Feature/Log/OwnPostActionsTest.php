<?php

/**
 * Own posts must be deletable from every surface that draws them, and a handle
 * with no account must never render as a profile link.
 *
 * Both are regressions of the same shape: a partial (`feed/commit.blade.php`)
 * is reused by three pages, so a capability defined on only one of them — or a
 * URL built from data that only one of them happened to have — breaks the other
 * two silently.
 *
 * 1. `feed/commit.blade.php` draws a delete strip for every row where `isOwn`
 *    is true. The feed defined `deleteCommit()` locally; `⚡profile` and
 *    `⚡starred` did not, and their action list came from `HasCommitActions`,
 *    which lacked it. Clicking delete there raised
 *    "Public method [deleteCommit] not found on component" and nothing was
 *    deleted. The action now lives on the trait, ownership-guarded.
 *
 * 2. A corpus author can carry a handle with no matching `users` row
 *    (`@rpaucek`). Row data (aside "Active in the log", the interlude "Active in
 *    this log", the commit name/hovercard) built `route('profile.show')` from
 *    it unconditionally, so every one of those was a live 404. `LogRow` now
 *    resolves "does an account own this handle?" once and emits `profileUrl`,
 *    and each emitter renders text instead of a link when it is null.
 */
use App\Models\FeedPost;
use App\Models\User;
use Livewire\Livewire;

function logOwnerWithPost(string $handle = 'owner'): array
{
    $owner = User::factory()->create(['handle' => $handle]);
    $post = FeedPost::factory()->create([
        'user_id' => $owner->id,
        'handle' => '@'.$handle,
        'author' => 'Owner Person',
    ]);

    return [$owner, $post];
}

test('the delete action is callable from every page that draws own posts', function (string $component, array $params) {
    [$owner, $post] = logOwnerWithPost();

    $instance = Livewire::actingAs($owner)->test($component, $params);

    expect(method_exists($instance->instance(), 'deleteCommit'))->toBeTrue();
    expect(method_exists($instance->instance(), 'undoDelete'))->toBeTrue();

    $instance->call('deleteCommit', $post->id);

    expect($post->fresh()->deleted)->toBeTrue();
})->with([
    'feed' => ['pages::feed', []],
    'profile' => ['pages::profile', ['handle' => 'owner']],
    'starred' => ['pages::starred', []],
]);

test('a stranger deleting someone else\'s post through the trait is a no-op', function () {
    [, $post] = logOwnerWithPost();
    $stranger = User::factory()->create(['handle' => 'stranger']);

    Livewire::actingAs($stranger)->test('pages::starred')->call('deleteCommit', $post->id);

    expect($post->fresh()->deleted)->toBeFalse();
});

test('a deleted own post is restorable in one action', function () {
    [$owner, $post] = logOwnerWithPost();

    $instance = Livewire::actingAs($owner)->test('pages::profile', ['handle' => 'owner']);
    $instance->call('deleteCommit', $post->id);
    $instance->call('undoDelete');

    expect($post->fresh()->deleted)->toBeFalse();
});

test('a handle with no account is rendered as text, never as a profile link', function () {
    $reader = User::factory()->create(['handle' => 'reader']);
    FeedPost::factory()->create([
        'user_id' => User::factory()->create(['handle' => 'ghostowner'])->id,
        'handle' => '@ghostauthor',
        'author' => 'Ghost Author',
    ]);

    foreach (['/feed', '/feed?log=latest', '/feed?log=trending', '/feed?log=following'] as $url) {
        $html = $this->actingAs($reader)->get($url)->getContent();

        expect(strpos($html, '/@ghostauthor'))
            ->toBeFalse("a handle with no account was linked on {$url}");
    }

    // The row itself must still be visible — this is about the link, not the row.
    expect($this->actingAs($reader)->get('/feed')->getContent())->toContain('Ghost Author');
});

test('a handle that does own an account is still linked', function () {
    $reader = User::factory()->create(['handle' => 'reader']);
    $known = User::factory()->create(['handle' => 'priya']);
    FeedPost::factory()->create([
        'user_id' => $known->id,
        'handle' => '@priya',
        'author' => 'Priya',
    ]);

    $html = $this->actingAs($reader)->get('/feed')->getContent();

    expect(strpos($html, '/@priya'))->not->toBeFalse();
});
