<?php

use App\Models\Remote;
use App\Models\RemoteMessage;
use App\Models\RemoteMessageReaction;
use App\Models\User;
use App\Models\UserBlock;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Remotes, beyond send-and-read.
 *
 * Reactions, pins, mute, archive and the read receipt all arrive as ids and
 * emoji from the client, so each one is asserted twice: that it writes, and
 * that it refuses a push or a conversation the reader has no claim on.
 */
beforeEach(function (): void {
    $this->reader = User::factory()->create(['handle' => 'reader']);
    $this->other = User::factory()->create(['handle' => 'rayhan']);

    $this->remote = Remote::query()->create(['kind' => 'direct', 'created_by' => $this->other->id]);
    $this->remote->participants()->attach([$this->reader->id, $this->other->id]);

    $this->theirs = RemoteMessage::query()->create([
        'remote_id' => $this->remote->id,
        'user_id' => $this->other->id,
        'body' => 'The N+1 was in the accessor.',
    ]);

    $this->mine = RemoteMessage::query()->create([
        'remote_id' => $this->remote->id,
        'user_id' => $this->reader->id,
        'body' => 'Found it, thanks.',
    ]);

    actingAs($this->reader);
});

/** The reader's own participant row on a conversation. */
function pivotOf(Remote $remote, User $user): mixed
{
    return $remote->participants()->where('users.id', $user->id)->first()?->pivot;
}

it('toggles a reaction on a push', function (): void {
    Livewire::test('pages::remotes')
        ->call('react', $this->theirs->id, '👍')
        ->assertOk();

    expect(RemoteMessageReaction::query()->count())->toBe(1);

    Livewire::test('pages::remotes')
        ->call('react', $this->theirs->id, '👍')
        ->assertOk();

    // A second click is a delete, not a second row: the unique index would
    // refuse it anyway, so the toggle has to be the delete.
    expect(RemoteMessageReaction::query()->count())->toBe(0);
});

it('counts a reaction from both sides once each', function (): void {
    RemoteMessageReaction::query()->create([
        'remote_message_id' => $this->theirs->id,
        'user_id' => $this->reader->id,
        'emoji' => '🔥',
    ]);
    RemoteMessageReaction::query()->create([
        'remote_message_id' => $this->theirs->id,
        'user_id' => $this->other->id,
        'emoji' => '🔥',
    ]);

    $thread = Livewire::test('pages::remotes')->instance()->thread;

    $chip = collect($thread)->firstWhere('id', $this->theirs->id)['reactions'][0];

    expect($chip['emoji'])->toBe('🔥')
        ->and($chip['count'])->toBe(2)
        ->and($chip['mine'])->toBeTrue();
});

it('ignores an emoji that is not on the menu', function (): void {
    Livewire::test('pages::remotes')
        ->call('react', $this->theirs->id, '💩')
        ->assertOk();

    expect(RemoteMessageReaction::query()->count())->toBe(0);
});

it('will not react to a push in a conversation the reader is not part of', function (): void {
    $stranger = User::factory()->create(['handle' => 'stranger']);
    $theirRemote = Remote::query()->create(['kind' => 'direct', 'created_by' => $stranger->id]);
    $theirRemote->participants()->attach([$stranger->id, $this->other->id]);

    $theirs = RemoteMessage::query()->create([
        'remote_id' => $theirRemote->id,
        'user_id' => $stranger->id,
        'body' => 'Not for you.',
    ]);

    Livewire::test('pages::remotes', ['remote' => (string) $theirRemote->id])
        ->call('react', $theirs->id, '👍')
        ->assertOk();

    expect(RemoteMessageReaction::query()->count())->toBe(0);
});

it('pins a push and unpins it', function (): void {
    Livewire::test('pages::remotes')
        ->call('pin', $this->theirs->id)
        ->assertOk();

    expect($this->theirs->fresh()?->isPinned())->toBeTrue();

    Livewire::test('pages::remotes')
        ->call('pin', $this->theirs->id)
        ->assertOk();

    expect($this->theirs->fresh()?->isPinned())->toBeFalse();
});

it('shows pinned pushes in a strip above the thread', function (): void {
    $this->theirs->pinned_at = now();
    $this->theirs->save();

    Livewire::test('pages::remotes')
        ->assertSee('data-test="remote-pinned"', false)
        ->assertSee('The N+1 was in the accessor.');
});

/*
|--------------------------------------------------------------------------
| Mute
|--------------------------------------------------------------------------
|
| A mute is a duration, not a flag. "For one hour" has to release itself, so
| the column is an end and not a switch — and the nav badge has to read it as
| "still silenced", which is the part a boolean would get wrong.
|
*/

it('mutes a remote for the reader only', function (): void {
    $component = Livewire::test('pages::remotes')
        ->call('mute', $this->remote->id, 'forever');

    expect($component->instance()->muted)->toBeTrue()
        ->and(pivotOf($this->remote, $this->reader)?->getAttribute('muted_until_at'))->not->toBeNull()
        // The other side's row is untouched: muting is a decision about your copy.
        ->and(pivotOf($this->remote, $this->other)?->getAttribute('muted_until_at'))->toBeNull();
});

it('releases a mute once its hour is up', function (): void {
    Livewire::test('pages::remotes')->call('mute', $this->remote->id, 'hour');

    expect(pivotOf($this->remote, $this->reader)?->getAttribute('muted_until_at'))->not->toBeNull();

    // The end is a moment, so travelling past it is the same as unmuting.
    $this->travel(61)->minutes();

    expect(Livewire::test('pages::remotes')->instance()->muted)->toBeFalse();
});

it('unmutes on request', function (): void {
    $component = Livewire::test('pages::remotes')
        ->call('mute', $this->remote->id, 'forever')
        ->call('mute', $this->remote->id, 'off');

    expect($component->instance()->muted)->toBeFalse()
        ->and(pivotOf($this->remote, $this->reader)?->getAttribute('muted_until_at'))->toBeNull();
});

it('will not mute a conversation the reader is not part of', function (): void {
    $stranger = User::factory()->create(['handle' => 'stranger']);
    $theirRemote = Remote::query()->create(['kind' => 'direct', 'created_by' => $stranger->id]);
    $theirRemote->participants()->attach([$stranger->id, $this->other->id]);

    Livewire::test('pages::remotes')->call('mute', $theirRemote->id, 'forever');

    expect(pivotOf($theirRemote, $this->reader))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The three tabs
|--------------------------------------------------------------------------
|
| Which tab a conversation lands in is derived, never stored: a thread somebody
| else opened that this reader has never answered is a request, filing it away
| stashes it, and everything else is Open.
|
*/

it('puts a conversation somebody else opened in Fetched until the reader answers', function (): void {
    $stranger = User::factory()->create(['handle' => 'stranger']);
    $request = Remote::query()->create(['kind' => 'direct', 'created_by' => $stranger->id]);
    $request->participants()->attach([$this->reader->id, $stranger->id]);

    RemoteMessage::query()->create([
        'remote_id' => $request->id,
        'user_id' => $stranger->id,
        'body' => 'Could you look at my PR?',
    ]);

    $component = Livewire::test('pages::remotes');

    expect(collect($component->instance()->rows)->pluck('id')->all())->toBe([$this->remote->id]);

    $component->call('showTab', 'fetched');

    expect(collect($component->instance()->rows)->pluck('id')->all())->toBe([$request->id]);
});

it('merges a fetched request by answering it', function (): void {
    $stranger = User::factory()->create(['handle' => 'stranger']);
    $request = Remote::query()->create(['kind' => 'direct', 'created_by' => $stranger->id]);
    $request->participants()->attach([$this->reader->id, $stranger->id]);

    RemoteMessage::query()->create([
        'remote_id' => $request->id,
        'user_id' => $stranger->id,
        'body' => 'Could you look at my PR?',
    ]);

    Livewire::test('pages::remotes')
        ->call('open', $request->id)
        ->set('draft', 'Sure, link it.')
        ->call('push');

    // Answering is the merge: there is no second action for it.
    $rows = collect(Livewire::test('pages::remotes')->instance()->rows)->pluck('id')->all();

    expect($rows)->toHaveCount(2)
        ->and($rows)->toContain($request->id);
});

it('lets a first push straight into Open when screening is off', function (): void {
    $this->reader->forceFill(['remote_requests' => false])->save();

    $stranger = User::factory()->create(['handle' => 'stranger']);
    $request = Remote::query()->create(['kind' => 'direct', 'created_by' => $stranger->id]);
    $request->participants()->attach([$this->reader->id, $stranger->id]);

    RemoteMessage::query()->create([
        'remote_id' => $request->id,
        'user_id' => $stranger->id,
        'body' => 'Could you look at my PR?',
    ]);

    expect(collect(Livewire::test('pages::remotes')->instance()->rows)->pluck('id')->all())
        ->toContain($request->id);
});

it('files a remote away and brings it back', function (): void {
    $component = Livewire::test('pages::remotes')
        ->call('archive', $this->remote->id)
        ->assertSet('tab', 'open');

    expect(pivotOf($this->remote, $this->reader)?->getAttribute('archived_at'))->not->toBeNull();

    // It left the inbox, so it cannot stay selected.
    expect($component->instance()->rows)->toBeEmpty()
        ->and($component->instance()->remoteId)->toBeNull();

    $component->call('showTab', 'stashed');

    expect($component->instance()->rows)->toHaveCount(1);

    $component->call('archive', $this->remote->id);

    expect(pivotOf($this->remote, $this->reader)?->getAttribute('archived_at'))->toBeNull();
});

it('keeps a pinned conversation at the top of the list', function (): void {
    $third = User::factory()->create(['handle' => 'third']);
    $newer = Remote::query()->create(['kind' => 'direct', 'created_by' => $third->id]);
    $newer->participants()->attach([$this->reader->id, $third->id]);

    // An explicit stamp, because both conversations are made in the same second
    // and a tie would make the ordering assertion a coin flip. `created_at` is
    // not fillable, so it is set after the insert.
    RemoteMessage::query()->create([
        'remote_id' => $newer->id,
        'user_id' => $this->reader->id,
        'body' => 'The newer conversation.',
    ])->forceFill(['created_at' => now()->addMinute()])->save();

    // Newer wins on recency, so the pin is the only reason the old one leads.
    expect(collect(Livewire::test('pages::remotes')->instance()->rows)->pluck('id')->all())
        ->toBe([$newer->id, $this->remote->id]);

    Livewire::test('pages::remotes')->call('pinRemote', $this->remote->id);

    expect(collect(Livewire::test('pages::remotes')->instance()->rows)->pluck('id')->all())
        ->toBe([$this->remote->id, $newer->id]);
});

/*
|--------------------------------------------------------------------------
| Receipts
|--------------------------------------------------------------------------
*/

it('marks the newest push of yours as pulled once the other side reads it', function (): void {
    // The other participant's marker is the receipt.
    $this->remote->participants()->updateExistingPivot($this->other->id, [
        'last_read_at' => now()->addMinute(),
    ]);

    $thread = Livewire::test('pages::remotes')->instance()->thread;

    $last = collect($thread)->filter(fn (array $row): bool => $row['isMine'])->last();

    expect($last['status'])->toBe('pulled');
});

it('shows no receipt while the other side has not pulled', function (): void {
    $this->remote->participants()->updateExistingPivot($this->other->id, [
        'last_read_at' => null,
    ]);

    $thread = Livewire::test('pages::remotes')->instance()->thread;

    $mine = collect($thread)->filter(fn (array $row): bool => $row['isMine'])->last();

    expect($mine['status'])->toBe('pushed');
});

it('stops sharing receipts when the reader turns them off', function (): void {
    $this->remote->participants()->updateExistingPivot($this->other->id, [
        'last_read_at' => now()->addMinute(),
    ]);

    $this->reader->forceFill(['remote_pull_receipts' => false])->save();

    $thread = Livewire::test('pages::remotes')->instance()->thread;

    // Sharing is mutual: with the switch off nobody's pull is reported.
    expect(collect($thread)->pluck('status')->filter()->unique()->values()->all())->toBe(['pushed']);
});

/*
|--------------------------------------------------------------------------
| Quotes
|--------------------------------------------------------------------------
*/

it('quotes a push when one is being answered', function (): void {
    Livewire::test('pages::remotes')
        ->call('reply', $this->theirs->id)
        ->assertSet('replyTo', $this->theirs->id)
        ->set('draft', 'Which accessor?')
        ->call('push');

    $reply = RemoteMessage::query()->where('body', 'Which accessor?')->first();

    expect($reply?->reply_to_id)->toBe($this->theirs->id);

    $thread = Livewire::test('pages::remotes')->instance()->thread;
    $quoted = collect($thread)->firstWhere('id', $reply?->id);

    expect($quoted['reply']['body'])->toContain('N+1');
});

it('drops a quote that does not belong to the open thread', function (): void {
    $stranger = User::factory()->create(['handle' => 'stranger']);
    $theirRemote = Remote::query()->create(['kind' => 'direct', 'created_by' => $stranger->id]);
    $theirRemote->participants()->attach([$stranger->id, $this->other->id]);

    $theirs = RemoteMessage::query()->create([
        'remote_id' => $theirRemote->id,
        'user_id' => $stranger->id,
        'body' => 'Not for you.',
    ]);

    Livewire::test('pages::remotes')
        ->set('replyTo', $theirs->id)
        ->set('draft', 'Quoting something I cannot see.')
        ->call('push');

    expect(RemoteMessage::query()->where('body', 'Quoting something I cannot see.')->first()?->reply_to_id)
        ->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Blocking
|--------------------------------------------------------------------------
*/

it('blocks the other side and hides the conversation', function (): void {
    $component = Livewire::test('pages::remotes')
        ->call('block', $this->remote->id);

    expect(UserBlock::query()->where('user_id', $this->reader->id)->where('blocked_id', $this->other->id)->exists())
        ->toBeTrue()
        ->and($component->instance()->rows)->toBeEmpty()
        ->and($component->instance()->blockedAccounts)->toHaveCount(1);
});

it('brings the conversation back on unblock', function (): void {
    Livewire::test('pages::remotes')->call('block', $this->remote->id);

    // It was stashed by the block, so it comes back to the stash and not to Open.
    Livewire::test('pages::remotes')
        ->call('unblock', $this->other->id)
        ->call('showTab', 'stashed');

    expect(UserBlock::query()->count())->toBe(0)
        ->and(collect(Livewire::test('pages::remotes')->call('showTab', 'stashed')->instance()->rows)->pluck('id')->all())
        ->toBe([$this->remote->id]);
});

/*
|--------------------------------------------------------------------------
| Starting a conversation
|--------------------------------------------------------------------------
*/

it('starts a direct remote with somebody the reader has never spoken to', function (): void {
    $newcomer = User::factory()->create(['handle' => 'newcomer', 'name' => 'Nadia Newcomer']);

    $component = Livewire::test('pages::remotes')
        ->call('pick')
        ->set('search', 'Nadia')
        ->call('startWith', $newcomer->id);

    $created = Remote::query()
        ->where('created_by', $this->reader->id)
        ->whereHas('participants', fn ($query) => $query->where('users.id', $newcomer->id))
        ->first();

    expect($created)->not->toBeNull()
        ->and($component->instance()->remoteId)->toBe($created?->id)
        ->and($created?->participants()->count())->toBe(2);
});

it('opens the existing thread instead of making a second one', function (): void {
    Livewire::test('pages::remotes')
        ->call('pick')
        ->set('search', 'rayhan')
        ->call('startWith', $this->other->id);

    expect(Remote::query()->count())->toBe(1);
});

it('offers a developer in the search results', function (): void {
    $newcomer = User::factory()->create(['handle' => 'newcomer', 'name' => 'Nadia Newcomer']);

    $candidates = Livewire::test('pages::remotes')
        ->set('search', 'Nadia')
        ->instance()
        ->candidates;

    expect(collect($candidates)->pluck('id')->all())->toBe([$newcomer->id]);
});

it('never offers a blocked developer', function (): void {
    Livewire::test('pages::remotes')->call('block', $this->remote->id);

    $component = Livewire::test('pages::remotes')
        ->call('pick')
        ->call('startWith', $this->other->id);

    // A block is a refusal at the door, not just a hidden row: pressing the
    // name must not reopen the thread either.
    expect($component->instance()->remoteId)->not->toBe($this->remote->id)
        ->and(Remote::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The list as a search result
|--------------------------------------------------------------------------
|
| The search page links here with `?q=`, so the list has to understand the
| term: which conversations survive it, what the row previews, and — the part
| that matters — that the term is still scoped to conversations the reader is
| in. The last test pins the link the search page builds.
|
*/

it('narrows the list to conversations whose pushes match a term', function (): void {
    $third = User::factory()->create(['handle' => 'third']);

    $elsewhere = Remote::query()->create(['kind' => 'direct', 'created_by' => $third->id]);
    $elsewhere->participants()->attach([$this->reader->id, $third->id]);

    RemoteMessage::query()->create([
        'remote_id' => $elsewhere->id,
        'user_id' => $third->id,
        'body' => 'Something else entirely.',
    ]);

    $rows = Livewire::test('pages::remotes')
        ->set('search', 'accessor')
        ->instance()
        ->rows;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($this->remote->id);
});

it('previews the line that matched, not the newest push', function (): void {
    // A later push that does not match: the row must still quote the hit, or
    // the reader lands on a thread and cannot see why it was listed.
    RemoteMessage::query()->create([
        'remote_id' => $this->remote->id,
        'user_id' => $this->other->id,
        'body' => 'A later line that says nothing about it.',
    ]);

    $rows = Livewire::test('pages::remotes')
        ->set('search', 'accessor')
        ->instance()
        ->rows;

    expect($rows[0]['preview'])->toContain('N+1')
        ->and($rows[0]['preview'])->not->toContain('later line');
});

it('says so when no conversation matches the term', function (): void {
    // `assertSee` escapes its needle, so the quoted term cannot be matched
    // literally — the prefix is what the test is about anyway.
    Livewire::test('pages::remotes')
        ->set('search', 'zzz-no-such-line')
        ->assertSee('fatal: nothing matches')
        ->assertSee('zzz-no-such-line');
});

it('never surfaces a conversation the reader is not part of', function (): void {
    $stranger = User::factory()->create(['handle' => 'stranger']);
    $third = User::factory()->create(['handle' => 'third']);

    $theirs = Remote::query()->create(['kind' => 'direct', 'created_by' => $stranger->id]);
    $theirs->participants()->attach([$stranger->id, $third->id]);

    RemoteMessage::query()->create([
        'remote_id' => $theirs->id,
        'user_id' => $stranger->id,
        'body' => 'A private accessor note that is not yours.',
    ]);

    $component = Livewire::test('pages::remotes')->set('search', 'accessor');

    expect($component->instance()->rows)->toHaveCount(1);
    $component->assertDontSee('A private accessor note that is not yours.');
});

it('takes the term from the query string the search page builds', function (): void {
    $this->get(route('remotes.index', ['q' => 'accessor']))
        ->assertOk()
        ->assertSee('The N+1 was in the accessor.')
        ->assertDontSee('Something else entirely.');
});

it('counts the pushes the reader has not pulled yet', function (): void {
    // A conversation the reader has answered sorts first and so is the one that
    // opens; the original keeps its untouched marker, which is what the badge
    // counts. The newer one needs a push from the reader to reach Open at all —
    // a thread they never answered is a request, not an unread conversation.
    $third = User::factory()->create(['handle' => 'third']);
    $newer = Remote::query()->create(['kind' => 'direct', 'created_by' => $third->id]);
    $newer->participants()->attach([$this->reader->id, $third->id]);

    RemoteMessage::query()->create([
        'remote_id' => $newer->id,
        'user_id' => $this->reader->id,
        'body' => 'Opening this one myself.',
    ]);

    RemoteMessage::query()->create([
        'remote_id' => $newer->id,
        'user_id' => $third->id,
        'body' => 'The newer conversation.',
    ])->forceFill(['created_at' => now()->addMinute()])->save();

    $component = Livewire::test('pages::remotes');
    $rows = collect($component->instance()->rows)->keyBy('id');

    // Opening a conversation pulls it: its marker is moved past everything
    // already in it, which is why only the other row still has a badge.
    expect($component->instance()->remoteId)->toBe($newer->id)
        ->and($rows[$this->remote->id]['ahead'])->toBe(1)
        ->and(pivotOf($newer, $this->reader)?->getAttribute('last_read_at'))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
*/

it('turns remotes off and back on', function (): void {
    $component = Livewire::test('pages::remotes')->call('togglePreference', 'enabled');

    expect($this->reader->fresh()?->remotes_enabled)->toBeFalse();

    $component->assertSee('fatal: remotes are off');

    $component->call('togglePreference', 'enabled');

    expect($this->reader->fresh()?->remotes_enabled)->toBeTrue();
});

it('ignores a preference that is not one of the three switches', function (): void {
    Livewire::test('pages::remotes')->call('togglePreference', 'is_admin');

    expect($this->reader->fresh()?->remotes_enabled)->toBeTrue()
        ->and($this->reader->fresh()?->remote_requests)->toBeTrue();
});
