<?php

/**
 * The four verbs a reader has on a commit: reply, share, edit, delete.
 *
 * Two of these are new (share, edit), two already existed (reply, delete) — but
 * all four are pinned here together, because the property that matters is the
 * one they share: the row is drawn by ONE partial reused by three pages, so a
 * verb that works on the feed and not on a profile is a bug that only shows up
 * on the page nobody tested.
 *
 * The share ledger follows the same doctrine as `feed_stars` and
 * `feed_poll_votes`: the count is a row count, never a column on the post. A
 * counter that drifts from its rows is the one bug a log cannot survive, and it
 * is invisible until it is wrong.
 */
use App\Models\FeedPost;
use App\Models\FeedShare;
use App\Models\FeedStar;
use App\Models\User;
use Livewire\Livewire;

function shareablePost(string $handle = 'author'): array
{
    $owner = User::factory()->create(['handle' => $handle]);
    $post = FeedPost::factory()->create([
        'user_id' => $owner->id,
        'handle' => '@'.$handle,
        'author' => 'Author Person',
        'body' => 'The original body, before any edit.',
    ]);

    return [$owner, $post];
}

test('sharing records a ledger row rather than nudging a counter', function () {
    [, $post] = shareablePost();
    $reader = User::factory()->create(['handle' => 'sharer']);

    Livewire::actingAs($reader)->test('pages::feed')->call('shareCommit', $post->id);

    expect(FeedShare::query()->where('feed_post_id', $post->id)->count())->toBe(1);

    // The count the row prints comes from the ledger, so the two agree by
    // construction — there is no column that could have been left behind.
    $instance = Livewire::actingAs($reader)->test('pages::feed');
    expect($instance->instance()->shareCounts()[$post->id] ?? 0)->toBe(1);
});

test('sharing twice is one share, whichever route it took', function () {
    [, $post] = shareablePost();
    $reader = User::factory()->create(['handle' => 'twice']);

    $instance = Livewire::actingAs($reader)->test('pages::feed');

    $instance->call('shareCommit', $post->id, FeedShare::CHANNEL_LINK);
    $instance->call('shareCommit', $post->id, FeedShare::CHANNEL_SHEET);
    $instance->call('shareCommit', $post->id, FeedShare::CHANNEL_LINK);

    expect(FeedShare::query()->where('feed_post_id', $post->id)->count())->toBe(1);

    // The channel follows the most recent route, so the ledger says how it was
    // last passed on rather than how it was first passed on.
    expect(FeedShare::query()->where('feed_post_id', $post->id)->first()->channel)
        ->toBe(FeedShare::CHANNEL_LINK);
});

test('an unknown share channel falls back to the clipboard rather than throwing', function () {
    [, $post] = shareablePost();
    $reader = User::factory()->create(['handle' => 'channel']);

    Livewire::actingAs($reader)
        ->test('pages::feed')
        ->call('shareCommit', $post->id, 'carrier-pigeon');

    expect(FeedShare::query()->where('feed_post_id', $post->id)->first()->channel)
        ->toBe(FeedShare::CHANNEL_LINK);
});

test('a guest cannot share — they are sent to sign in', function () {
    [, $post] = shareablePost();

    Livewire::test('pages::feed')
        ->call('shareCommit', $post->id)
        ->assertRedirect(route('feed.index', ['signin' => 'required']));

    expect(FeedShare::query()->count())->toBe(0);
});

test('sharing your own post is allowed', function () {
    [$owner, $post] = shareablePost();

    Livewire::actingAs($owner)->test('pages::feed')->call('shareCommit', $post->id);

    expect(FeedShare::query()->where('feed_post_id', $post->id)->count())->toBe(1);
});

test('the share button is drawn on every surface that draws the row', function (string $component, array $params) {
    [, $post] = shareablePost('onsurface');
    $reader = User::factory()->create(['handle' => 'visitor']);

    // `/starred` only lists what the reader has starred, so the fixture has to
    // be starred for the row to exist at all — otherwise the test asserts about
    // a page with no rows on it.
    FeedStar::query()->create(['feed_post_id' => $post->id, 'user_id' => $reader->id]);

    $html = Livewire::actingAs($reader)->test($component, $params)->html();

    expect(str_contains($html, 'data-test="share"'))->toBeTrue();
    expect(str_contains($html, "shareCommit({$post->id}"))->toBeTrue();
})->with([
    'feed' => ['pages::feed', []],
    'profile' => ['pages::profile', ['handle' => 'onsurface']],
    'starred' => ['pages::starred', []],
]);

test('the edit action opens the row holding its current body', function () {
    [$owner, $post] = shareablePost('editor');

    $instance = Livewire::actingAs($owner)->test('pages::feed');
    $instance->call('openEdit', $post->id);

    expect($instance->instance()->editingId)->toBe($post->id);
    expect($instance->instance()->editDraft)->toBe('The original body, before any edit.');

    // The form has to open carrying the text — a second round trip to fetch it
    // would blank the row while it arrived.
    expect($instance->html())->toContain('data-test="edit-form"');
    expect($instance->html())->toContain('The original body, before any edit.');
});

test('saving an edit rewrites the body and closes the editor', function () {
    [$owner, $post] = shareablePost('saver');

    $instance = Livewire::actingAs($owner)->test('pages::feed');
    $instance->call('openEdit', $post->id);
    $instance->set('editDraft', 'A corrected body.');
    $instance->call('saveEdit');

    expect($post->fresh()->body)->toBe('A corrected body.');
    expect($instance->instance()->editingId)->toBeNull();
    expect($instance->instance()->editDraft)->toBe('');

    expect($instance->html())->not->toContain('data-test="edit-form"');
});

test('cancelling an edit writes nothing', function () {
    [$owner, $post] = shareablePost('canceller');

    $instance = Livewire::actingAs($owner)->test('pages::feed');
    $instance->call('openEdit', $post->id);
    $instance->set('editDraft', 'This should never be saved.');
    $instance->call('cancelEdit');

    expect($post->fresh()->body)->toBe('The original body, before any edit.');
    expect($instance->instance()->editingId)->toBeNull();
});

test('an edit keeps the post published and its pinned state intact', function () {
    [$owner, $post] = shareablePost('keeper');
    $post->forceFill(['pinned' => true])->save();

    $instance = Livewire::actingAs($owner)->test('pages::feed');
    $instance->call('openEdit', $post->id);
    $instance->set('editDraft', 'Edited while pinned.');
    $instance->call('saveEdit');

    $fresh = $post->fresh();

    expect($fresh->body)->toBe('Edited while pinned.');
    expect($fresh->pinned)->toBeTrue();
    expect($fresh->deleted)->toBeFalse();
});

test('an edit is rejected when the body is emptied', function () {
    [$owner, $post] = shareablePost('empty');

    $instance = Livewire::actingAs($owner)->test('pages::feed');
    $instance->call('openEdit', $post->id);
    $instance->set('editDraft', '   ');
    $instance->call('saveEdit')
        ->assertHasErrors(['editDraft']);

    expect($post->fresh()->body)->toBe('The original body, before any edit.');
});

test('a stranger cannot edit someone else\'s post', function () {
    [, $post] = shareablePost('victim');
    $stranger = User::factory()->create(['handle' => 'intruder']);

    $instance = Livewire::actingAs($stranger)->test('pages::feed');
    $instance->call('openEdit', $post->id);

    // The editor never opens, so there is nothing to submit.
    expect($instance->instance()->editingId)->toBeNull();

    $instance->set('editDraft', 'Rewritten by someone else.');
    $instance->call('saveEdit');

    expect($post->fresh()->body)->toBe('The original body, before any edit.');
});

test('the edit control is drawn only on an own row', function () {
    [, $ownPost] = shareablePost('mine');
    $visitor = User::factory()->create(['handle' => 'visitor']);

    // A visitor looking at somebody else's post gets no pencil.
    $html = Livewire::actingAs($visitor)->test('pages::feed')->html();
    expect(str_contains($html, 'data-test="edit-commit"'))->toBeFalse();

    // The author looking at their own post does.
    $ownHandle = $ownPost->handle;
    $owner = User::query()->where('handle', ltrim($ownHandle, '@'))->firstOrFail();
    $ownHtml = Livewire::actingAs($owner)->test('pages::feed')->html();
    expect(str_contains($ownHtml, 'data-test="edit-commit"'))->toBeTrue();
});

test('opening the editor closes the reply composer on the same row', function () {
    [$owner, $post] = shareablePost('closer');

    $instance = Livewire::actingAs($owner)->test('pages::feed');
    $instance->call('openReply', $post->id);

    expect($instance->instance()->replyTo)->toBe($post->id);

    $instance->call('openEdit', $post->id);

    // Two forms in one column would leave the reader unsure which one submits.
    expect($instance->instance()->replyTo)->toBeNull();
    expect($instance->instance()->editingId)->toBe($post->id);
});

test('reply and delete still work beside the new verbs', function () {
    [$owner, $post] = shareablePost('allverbs');

    $instance = Livewire::actingAs($owner)->test('pages::feed');

    $instance->call('openReply', $post->id);
    $instance->set('replyDraft', 'A reply to my own post.');
    $instance->call('postReply', $post->id);

    // `replies_count` is a `withCount` attribute, not a column — count the rows
    // the reply actually created, or assert against a column that is null.
    expect($post->fresh()->replies()->count())->toBe(1);

    $instance->call('deleteCommit', $post->id);
    expect($post->fresh()->deleted)->toBeTrue();

    $instance->call('undoDelete');
    expect($post->fresh()->deleted)->toBeFalse();
});

test('the two own-row verbs are flow inside one anchored group', function () {
    // The pencil and the trash are both `.dn-delete`-styled buttons. The rule
    // that positions them is absolutely anchored (`right: -6px`), so if each
    // button carried it directly they would land on the SAME pixel and the
    // later one would paint over the earlier — the pencil would be visible but
    // unclickable. They must be children of a single anchored group instead.
    $owner = User::factory()->create(['handle' => 'verbs']);
    FeedPost::factory()->create([
        'user_id' => $owner->id,
        'handle' => '@verbs',
        'author' => 'Verb Owner',
        'body' => 'Mine to edit and to delete.',
    ]);

    $html = Livewire::actingAs($owner)->test('pages::feed')->html();

    $css = file_get_contents(resource_path('css/log.css'));

    // The group is the positioned thing, and it hosts both buttons.
    expect(strpos($css, '.dn-row-verbs {'))->not->toBeFalse();
    $group = preg_match('/\.dn-row-verbs\s*\{([^}]*)\}/', $css, $m) ? $m[1] : '';
    expect($group)->toContain('position: absolute');
    expect($group)->toContain('display: flex');

    // `.dn-delete` itself must NOT be anchored any more.
    $del = preg_match('/\.dn-delete\s*\{([^}]*)\}/', $css, $m2) ? $m2[1] : '';
    expect($del)->not->toContain('position: absolute');

    // Both verbs sit inside the one group in the markup, pencil first.
    preg_match('/<span class="dn-row-verbs">(.*?)<\/span>/s', $html, $wrap);
    expect($wrap)->not->toBeEmpty();
    $inner = $wrap[1] ?? '';
    $pencilAt = strpos($inner, 'data-test="edit-commit"');
    $trashAt = strpos($inner, 'data-test="delete-commit"');
    expect($pencilAt)->not->toBeFalse();
    expect($trashAt)->not->toBeFalse();
    expect($pencilAt)->toBeLessThan($trashAt);

    // And there is exactly one group per own row — not one per button.
    expect(substr_count($html, 'dn-row-verbs'))->toBe(1);
});

test('a stranger sees no own-row verb group at all', function () {
    [, $post] = shareablePost('notmine');
    $stranger = User::factory()->create(['handle' => 'passerby']);

    $html = Livewire::actingAs($stranger)->test('pages::feed')->html();

    // The verbs live inside a group; no group means no pencil and no trash —
    // a fix that moved the buttons without moving the gate would show here.
    expect(substr_count($html, 'dn-row-verbs'))->toBe(0);
    expect($html)->not->toContain('data-test="edit-commit"');
    expect($html)->not->toContain('data-test="delete-commit"');
});
