<?php

use App\Actions\Feed\DeleteFeedPost;
use App\Actions\Feed\ResolveFeedAudience;
use App\Actions\Feed\SaveFeedDraft;
use App\Actions\Feed\ScheduleFeedPost;
use App\Enums\CommunityKind;
use App\Enums\CommunityRole;
use App\Enums\FeedPostAudience;
use App\Enums\FeedPostStatus;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * The write side of the feed: the composer modal, its verb classes, and the
 * visibility each publishing state buys a post.
 *
 * The composer is the surface under test because it is the only path a real
 * author takes; the action classes are asserted directly where the refusal is
 * a business rule rather than a field the modal can mark invalid.
 */
function publishingAuthor(string $name = 'Wren Calloway'): User
{
    return User::factory()->create([
        'name' => $name,
        'email' => Str::slug($name).'@example.com',
    ]);
}

beforeEach(function (): void {
    $this->author = publishingAuthor();
});

it('keeps the composer entirely off the page for guests', function (): void {
    get(route('feed.index'))
        ->assertOk()
        // The modal is the only write surface; a guest must not even mount it.
        ->assertDontSee('data-test="composer-body"', false);
});

it('refuses the composer verbs to a guest who mounts it anyway', function (): void {
    $component = Livewire::test('pages::feed.composer-modal')
        ->set('body', 'A post nobody authorised.');

    // Livewire's test broker renders aborts into the response instead of
    // rethrowing them, so the refusal shows up as a 403 the harness exposes.
    $component->call('publish')->assertStatus(403);
    expect(FeedPost::query()->where('body', 'A post nobody authorised.')->exists())->toBeFalse();
});

it('publishes a plain text post in one click, owned and signed', function (): void {
    actingAs($this->author);

    Livewire::test('pages::feed.composer-modal')
        ->set('body', "Ship notes for the queue worker.\n\nBatch by two hundred.")
        ->call('publish')
        ->assertDispatched('feed-post-published');

    $post = FeedPost::query()->latest('id')->firstOrFail();

    expect($post->status)->toBe(FeedPostStatus::Published)
        ->and($post->user_id)->toBe($this->author->id)
        ->and($post->posted_at)->not->toBeNull()
        ->and($post->audience)->toBe(FeedPostAudience::Public)
        ->and($post->author)->toBe($this->author->name)
        ->and($post->handle)->toStartWith('@');

    // Title is derived from the first line when the author never typed one.
    expect($post->title)->toBe('Ship notes for the queue worker.');

    get(route('feed.index'))->assertSee('Ship notes for the queue worker.');
});

it('publishes the question, snippet, and poll shapes into the structures the cards read', function (): void {
    actingAs($this->author);

    Livewire::test('pages::feed.composer-modal')
        ->call('open', 'question')
        ->assertSet('type', 'question')
        ->set('body', 'How do you keep queue retries from double-charging?')
        ->call('publish');

    Livewire::test('pages::feed.composer-modal')
        ->call('open', 'snippet')
        ->set('body', "One query instead of N.\n")
        ->set('code', "<?php\n\nreturn FeedPost::query()->visible()->get();")
        ->set('codeFile', 'app/Actions/Feed/recent.php')
        ->call('publish');

    Livewire::test('pages::feed.composer-modal')
        ->call('open', 'poll')
        ->set('body', 'Which upload driver do you run?')
        ->set('pollOptions', [['label' => 'Local', 'votes' => 0], ['label' => 'S3', 'votes' => 0]])
        ->call('publish');

    $shapes = FeedPost::query()->where('user_id', $this->author->id)->pluck('type', 'slug');

    expect($shapes->count())->toBe(3);

    $poll = FeedPost::query()->where('type', 'poll')->latest('id')->firstOrFail();
    expect($poll->poll['options'])->toHaveCount(2)
        ->and($poll->poll['votes'])->toBe(0);

    $snippet = FeedPost::query()->where('type', 'snippet')->latest('id')->firstOrFail();
    expect($snippet->code)->toStartWith('<?php')
        ->and($snippet->code_file)->toBe('app/Actions/Feed/recent.php');
});

it('refuses a poll that cannot actually be voted on', function (): void {
    // One option is a statement, not a poll, and identical labels collapse.
    expect(fn () => app(SaveFeedDraft::class)->handle($this->author, [
        'body' => 'Only one choice here.',
        'type' => 'poll',
        'poll' => ['options' => [['label' => 'Yes', 'votes' => 0], ['label' => 'Yes', 'votes' => 0]]],
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(SaveFeedDraft::class)->handle($this->author, [
        'body' => 'No choices here.',
        'type' => 'poll',
        'poll' => ['options' => [['label' => '', 'votes' => 0], ['label' => '', 'votes' => 0]]],
    ]))->toThrow(ValidationException::class);

    expect(FeedPost::query()->where('type', 'poll')->exists())->toBeFalse();
});

it('fails an over-length body on publish and keeps the last good draft', function (): void {
    actingAs($this->author);

    $component = Livewire::test('pages::feed.composer-modal')
        ->set('body', 'A sentence worth keeping.')
        ->call('autosave');

    $draft = FeedPost::query()->latest('id')->firstOrFail();
    expect($draft->status)->toBe(FeedPostStatus::Draft);

    // Autosave silently skips an over-limit body; publish must then refuse it
    // out loud without touching the draft the author already owns.
    $component
        ->set('body', str_repeat('x', 5001))
        ->call('autosave')
        ->call('publish')
        ->assertHasErrors('body');

    expect($component->get('draftId'))->toBe($draft->id);

    $draft->refresh();
    expect($draft->body)->toBe('A sentence worth keeping.')
        ->and($draft->status)->toBe(FeedPostStatus::Draft);
});

it('addresses a post to the author alone, and nobody else reads it', function (): void {
    $stranger = publishingAuthor('Ivo Petrenko');
    $post = FeedPost::factory()->authoredBy($this->author)->private($this->author)->create([
        'title' => 'Notes I am not ready to share',
        'body' => 'A private rehearsal of the release plan.',
    ]);

    expect($post->isVisibleTo($this->author))->toBeTrue()
        ->and($post->isVisibleTo($stranger))->toBeFalse()
        ->and($post->isVisibleTo(null))->toBeFalse();

    actingAs($this->author);
    Livewire::test('pages::feed')->assertSee('Notes I am not ready to share');

    actingAs($stranger);
    Livewire::test('pages::feed')->assertDontSee('Notes I am not ready to share');

    get(route('feed.index'))
        ->assertOk()
        ->assertDontSee('Notes I am not ready to share');

    get(route('feed.show', $post))->assertNotFound();
});

it('shares a community post with members and hides it from everyone else', function (): void {
    $member = publishingAuthor('Sela Moore');
    $outsider = publishingAuthor('Tomas Reider');
    $community = communityWithMembers([$this->author, $member]);

    $post = FeedPost::factory()->inCommunity($this->author, $community)->create([
        'title' => 'Inside the reading group thread',
        'body' => 'Only the joined members read this line.',
    ]);

    expect($post->isVisibleTo($this->author))->toBeTrue()
        ->and($post->isVisibleTo($member))->toBeTrue()
        ->and($post->isVisibleTo($outsider))->toBeFalse()
        ->and($post->isVisibleTo(null))->toBeFalse();

    actingAs($outsider);
    Livewire::test('pages::feed')->assertDontSee('Inside the reading group thread');

    actingAs($member);
    Livewire::test('pages::feed')->assertSee('Inside the reading group thread');
});

it('refuses to share a post into a community the author never joined', function (): void {
    $joined = communityWithMembers([$this->author]);
    $other = communityWithMembers([publishingAuthor('Nadia Voss')]);

    expect(fn () => app(ResolveFeedAudience::class)->handle($this->author, FeedPostAudience::Community, $other->id))
        ->toThrow(ValidationException::class);

    // The community the author *is* in resolves cleanly, both values together.
    $resolved = app(ResolveFeedAudience::class)->handle($this->author, FeedPostAudience::Community, $joined->id);
    expect($resolved['audience'])->toBe(FeedPostAudience::Community)
        ->and($resolved['community_id'])->toBe($joined->id);

    // And a widened audience never keeps a stale community id.
    $widened = app(ResolveFeedAudience::class)->handle($this->author, FeedPostAudience::Public, $joined->id);
    expect($widened['community_id'])->toBeNull();
});

it('takes the preview step before publishing media or a narrowed audience', function (): void {
    actingAs($this->author);

    $component = Livewire::test('pages::feed.composer-modal')
        ->set('body', 'A diary entry for later.')
        ->set('audience', FeedPostAudience::Self->value)
        ->call('publish');

    // First click was the preview; nothing is published yet.
    expect(FeedPost::query()->count())->toBe(0);

    $component->call('publish')->assertDispatched('feed-post-published');

    $post = FeedPost::query()->firstOrFail();
    expect($post->status)->toBe(FeedPostStatus::Published)
        ->and($post->audience)->toBe(FeedPostAudience::Self);
});

it('schedules a post, keeps it dark until its minute, and the command opens the gate', function (): void {
    actingAs($this->author);

    $due = now()->addHours(2)->format('Y-m-d H:i');

    Livewire::test('pages::feed.composer-modal')
        ->set('body', 'The quarterly retrospective, on time.')
        ->set('scheduledAt', $due)
        ->call('schedule')
        ->assertDispatched('feed-post-scheduled');

    $post = FeedPost::query()->firstOrFail();
    expect($post->status)->toBe(FeedPostStatus::Scheduled)
        ->and($post->posted_at)->toBeNull();

    Livewire::test('pages::feed')->assertDontSee('The quarterly retrospective');

    // A dry run reports the due post without touching it.
    $this->travel(3)->hours();
    $this->artisan('feed:publish-scheduled', ['--dry-run' => true])->assertSuccessful();
    expect($post->refresh()->status)->toBe(FeedPostStatus::Scheduled);

    $this->artisan('feed:publish-scheduled')->assertSuccessful();
    $post->refresh();
    expect($post->status)->toBe(FeedPostStatus::Published)
        ->and($post->posted_at)->not->toBeNull();

    Livewire::test('pages::feed')->assertSee('The quarterly retrospective');

    // Running again changes nothing — the flip is idempotent.
    $this->artisan('feed:publish-scheduled')->assertSuccessful();
    expect($post->refresh()->status)->toBe(FeedPostStatus::Published);
});

it('refuses a schedule that is already due', function (): void {
    // The rule lives server-side because the browser clock is not evidence.
    expect(fn () => app(ScheduleFeedPost::class)->handle($this->author, [
        'body' => 'Yesterday, on paper.',
        'type' => 'discussion',
        'topic' => 'community',
        'audience' => FeedPostAudience::Public,
    ], now()->subHour()->format('Y-m-d H:i')))->toThrow(ValidationException::class);

    expect(FeedPost::query()->where('status', FeedPostStatus::Scheduled->value)->exists())->toBeFalse();
});

it('forbids one account from editing, deleting, or scheduling another\'s post', function (): void {
    $intruder = publishingAuthor('Bram Olsen');
    $post = FeedPost::factory()->authoredBy($this->author)->create();

    expect(Gate::forUser($intruder)->denies('update', $post))->toBeTrue()
        ->and(Gate::forUser($intruder)->denies('delete', $post))->toBeTrue()
        ->and(Gate::forUser($intruder)->denies('publish', $post))->toBeTrue();

    // A post with no author at all — the curated corpus — belongs to nobody.
    $curated = FeedPost::factory()->create();
    expect(Gate::forUser($intruder)->denies('update', $curated))->toBeTrue()
        ->and(Gate::forUser($this->author)->denies('delete', $curated))->toBeTrue();

    // Acting through the verbs raises rather than mislabels anyone else's row.
    expect(fn () => app(DeleteFeedPost::class)->handle($intruder, $post))
        ->toThrow(AuthorizationException::class);
});

it('will not load or discard another author\'s draft through the composer', function (): void {
    $intruder = publishingAuthor('Bram Olsen');
    $draft = FeedPost::factory()->draft($this->author)->create([
        'title' => 'Half an argument about caching',
        'body' => 'Private working notes that must not surface.',
    ]);

    actingAs($intruder);

    $component = Livewire::test('pages::feed.composer-modal')
        ->call('restoreDraft', $draft->id);

    // The scoped lookup finds nothing that is not the caller's, so the composer
    // stays blank instead of adopting the stranger's words.
    expect($component->get('body'))->toBe('')
        ->and($component->get('draftId'))->toBeNull();

    $component->call('discardDraftRow', $draft->id)->assertStatus(403);

    expect($draft->refresh()->deleted)->toBeFalse();
});

it('lists only the author\'s own drafts and restores one for editing', function (): void {
    $stranger = publishingAuthor('Ines Kakar');
    // The drafts panel prints the title, so the titles are what must not leak.
    $mine = FeedPost::factory()->draft($this->author)->create([
        'title' => 'My own unfinished thought.',
        'body' => 'Notes only I should be able to resume.',
    ]);
    FeedPost::factory()->draft($stranger)->create([
        'title' => 'Not mine to resume.',
        'body' => 'A stranger\'s private working notes.',
    ]);

    actingAs($this->author);

    Livewire::test('pages::feed.composer-modal')
        ->call('open')
        ->assertSee('My own unfinished thought.')
        ->assertDontSee('Not mine to resume.')
        ->call('restoreDraft', $mine->id)
        ->assertSet('draftId', $mine->id)
        ->assertSet('body', 'Notes only I should be able to resume.');
});

it('removes a post by flag, not by fire — and hides it everywhere', function (): void {
    $reader = publishingAuthor('Ola Weiss');
    $post = FeedPost::factory()->authoredBy($this->author)->withDetail()->create([
        'title' => 'A thread about to be withdrawn',
        'body' => 'This line must vanish from every surface at once.',
    ]);

    app(DeleteFeedPost::class)->handle($this->author, $post);

    // The row survives for the moderation console; the flag is the removal.
    expect(FeedPost::query()->whereKey($post->id)->exists())->toBeTrue()
        ->and($post->refresh()->deleted)->toBeTrue();

    actingAs($this->author);
    Livewire::test('pages::feed')->assertDontSee('A thread about to be withdrawn');
    get(route('feed.show', $post))->assertNotFound();

    actingAs($reader);
    Livewire::test('pages::feed')->assertDontSee('A thread about to be withdrawn');

    get(route('feed.index'))->assertDontSee('A thread about to be withdrawn');

    // Deleting twice reports the same state instead of erroring.
    expect(app(DeleteFeedPost::class)->handle($this->author, $post)->deleted)->toBeTrue();
});

it('never renders a draft on the public feed, even to its own author', function (): void {
    actingAs($this->author);

    Livewire::test('pages::feed.composer-modal')
        ->set('body', 'Fifteen minutes of unfinished policy notes.')
        ->call('saveDraft')
        ->assertDispatched('feed-draft-saved');

    $draft = FeedPost::query()->firstOrFail();
    expect($draft->status)->toBe(FeedPostStatus::Draft);

    Livewire::test('pages::feed')->assertDontSee('Fifteen minutes of unfinished policy notes');
    get(route('feed.index'))->assertDontSee('Fifteen minutes of unfinished policy notes');
    get(route('feed.show', $draft))->assertNotFound();
});

it('refuses the same post twice inside the double-click window', function (): void {
    actingAs($this->author);

    Livewire::test('pages::feed.composer-modal')
        ->set('body', 'One announcement, sent by an itchy click.')
        ->call('publish')
        ->set('body', 'One announcement, sent by an itchy click.')
        ->call('publish')
        ->assertHasErrors('body');

    expect(FeedPost::query()->where('body', 'One announcement, sent by an itchy click.')->count())->toBe(1);
});

it('lets an author publish five posts a minute and asks for patience on the sixth', function (): void {
    actingAs($this->author);

    foreach (range(1, 5) as $n) {
        Livewire::test('pages::feed.composer-modal')
            ->set('body', "Rapid-fire dispatch number {$n}.")
            ->call('publish')
            ->assertHasNoErrors();
    }

    Livewire::test('pages::feed.composer-modal')
        ->set('body', 'Rapid-fire dispatch number 6.')
        ->call('publish')
        ->assertHasErrors('body');

    expect(FeedPost::query()->where('user_id', $this->author->id)->count())->toBe(5);
});

it('lifts the publishing throttle for an administrator', function (): void {
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    foreach (range(1, 6) as $n) {
        Livewire::test('pages::feed.composer-modal')
            ->set('body', "Admin bulletin number {$n}.")
            ->call('publish')
            ->assertHasNoErrors();
    }

    expect(FeedPost::query()->where('user_id', $admin->id)->count())->toBe(6);
});

it('keeps drafts invisible to everybody and restorable only by their author', function (): void {
    $stranger = publishingAuthor('Vera Lindqvist');
    $draft = FeedPost::factory()->draft($this->author)->create(['body' => 'Notes nobody has approved yet.']);

    actingAs($stranger);
    Livewire::test('pages::feed')
        ->assertDontSee('Notes nobody has approved yet')
        ->assertOk();

    expect($draft->isVisibleTo($stranger))->toBeFalse()
        ->and($draft->isVisibleTo($this->author))->toBeFalse()
        ->and($draft->isPublishedNow())->toBeFalse();
});

/**
 * A community with the given members, built directly — there are no factories
 * for these tables and the membership row is three columns wide.
 *
 * @param  array<int, User>  $members
 */
function communityWithMembers(array $members): Community
{
    $community = Community::query()->create([
        'slug' => 'test-'.Str::lower(Str::random(8)),
        'name' => 'Test Reading Group',
        'description' => 'A community built for audience assertions.',
        'topic' => 'community',
        'glyph' => 'RG',
        'kind' => CommunityKind::Open,
        'rules' => null,
    ]);

    foreach ($members as $member) {
        CommunityMembership::query()->create([
            'community_id' => $community->id,
            'user_id' => $member->id,
            'role' => CommunityRole::Member,
            'joined_at' => now(),
        ]);
    }

    return $community;
}
