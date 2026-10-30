<?php

use App\Models\FeedPost;
use App\Models\FeedStar;
use App\Models\LogNotification;
use App\Models\Remote;
use App\Models\RemoteMessage;
use App\Models\SshKey;
use App\Models\User;
use App\Models\UserBlock;
use Database\Seeders\FeedPostSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * The surfaces that share the log's frame.
 *
 * Each page is asserted twice: that it renders inside the frame (so the layout
 * cannot drift page by page), and that its one write actually writes. The guard
 * is asserted too — every personal surface sends a guest to the sign-in dialog
 * rather than rendering an empty page.
 */
beforeEach(function (): void {
    $this->seed(FeedPostSeeder::class);
    $this->reader = User::factory()->create(['handle' => 'reader']);
});

it('sends a guest from every personal surface to sign in', function (string $route): void {
    get(route($route))->assertRedirect(route('feed.index', ['signin' => 'required']));
})->with(['notifications.index', 'remotes.index', 'starred.index']);

it('renders a profile at its handle', function (): void {
    get(route('profile.show', ['handle' => 'reader']))
        ->assertOk()
        ->assertSee('data-test="profile"', false)
        ->assertSee('@reader');
});

it('answers 404 for a handle that does not exist', function (): void {
    get(route('profile.show', ['handle' => 'nobody-here']))->assertNotFound();
});

it('draws the inbox on the rail and marks what it showed as read', function (): void {
    $post = FeedPost::query()->visible()->firstOrFail();
    $actor = User::factory()->create(['handle' => 'priya-test']);

    $notification = LogNotification::query()->create([
        'user_id' => $this->reader->id,
        'actor_id' => $actor->id,
        'type' => LogNotification::Starred,
        'feed_post_id' => $post->id,
    ]);

    actingAs($this->reader);

    get(route('notifications.index'))
        ->assertOk()
        ->assertSee('data-test="notification"', false)
        ->assertSee('starred your post');

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('filters the inbox by type', function (): void {
    actingAs($this->reader);

    $post = FeedPost::query()->visible()->firstOrFail();
    $actor = User::factory()->create(['handle' => 'kenji-test']);

    LogNotification::query()->create([
        'user_id' => $this->reader->id,
        'actor_id' => $actor->id,
        'type' => LogNotification::Replied,
        'feed_post_id' => $post->id,
    ]);

    get(route('notifications.index', ['type' => 'stars']))
        ->assertOk()
        ->assertSee('fatal: no stars yet');
});

it('lists the commits a reader starred', function (): void {
    $post = FeedPost::query()->visible()->firstOrFail();

    FeedStar::query()->create([
        'feed_post_id' => $post->id,
        'user_id' => $this->reader->id,
    ]);

    actingAs($this->reader);

    get(route('starred.index'))
        ->assertOk()
        ->assertSee('data-test="commit"', false)
        ->assertSee(route('feed.show', ['slug' => $post->slug]), false);
});

it('opens a remote and pushes a message into it', function (): void {
    $other = User::factory()->create(['handle' => 'rayhan-test']);

    $remote = Remote::query()->create(['kind' => 'direct', 'created_by' => $other->id]);
    $remote->participants()->attach([$this->reader->id, $other->id]);

    actingAs($this->reader);

    Livewire::test('pages::remotes')
        ->assertSet('remoteId', $remote->id)
        ->set('draft', 'Pushed from the test.')
        ->call('push')
        ->assertSet('draft', '');

    expect(RemoteMessage::query()->where('remote_id', $remote->id)->count())->toBe(1);
});

it('will not open a remote the reader is not part of', function (): void {
    $stranger = User::factory()->create(['handle' => 'stranger']);
    $other = User::factory()->create(['handle' => 'other']);

    $remote = Remote::query()->create(['kind' => 'direct', 'created_by' => $other->id]);
    $remote->participants()->attach([$stranger->id, $other->id]);

    actingAs($this->reader);

    Livewire::test('pages::remotes', ['remote' => (string) $remote->id])
        ->assertSet('remoteId', null);
});

it('registers and revokes a terminal key', function (): void {
    actingAs($this->reader);

    Livewire::test('pages::settings.terminal')
        ->set('name', 'work laptop')
        ->set('fingerprint', 'SHA256:abcdefghijklmnopqrstuvwxyz012345')
        ->call('addKey')
        ->assertHasNoErrors();

    $key = SshKey::query()->firstOrFail();

    expect($key->user_id)->toBe($this->reader->id);

    Livewire::test('pages::settings.terminal')->call('revokeKey', $key->id);

    expect(SshKey::query()->count())->toBe(0);
});

it('refuses a fingerprint that is not one', function (): void {
    actingAs($this->reader);

    Livewire::test('pages::settings.terminal')
        ->set('name', 'nope')
        ->set('fingerprint', 'hello')
        ->call('addKey')
        ->assertHasErrors('fingerprint');

    expect(SshKey::query()->count())->toBe(0);
});

it('silences a developer by handle', function (): void {
    User::factory()->create(['handle' => 'loud']);

    actingAs($this->reader);

    Livewire::test('pages::settings.safety')
        ->set('handle', '@loud')
        ->set('muted', true)
        ->call('silence')
        ->assertHasNoErrors();

    expect(UserBlock::query()->where('user_id', $this->reader->id)->count())->toBe(1);
});

it('refuses to silence a handle that does not exist', function (): void {
    actingAs($this->reader);

    Livewire::test('pages::settings.safety')
        ->set('handle', 'ghost')
        ->call('silence')
        ->assertHasErrors('handle');
});

it('renders the settings screens inside the log frame', function (string $route, string $marker): void {
    actingAs($this->reader);

    get(route($route))
        ->assertOk()
        ->assertSee('data-test="settings"', false)
        ->assertSee($marker);
})->with([
    ['profile.edit', 'Profile'],
    ['appearance.edit', 'Appearance'],
    ['settings.terminal', 'Terminal'],
    ['settings.safety', 'Safety'],
    ['settings.devices', 'Devices'],
]);

it('renders the marketplace inside the log frame', function (): void {
    get(route('marketplace.index'))
        ->assertOk()
        ->assertSee('data-test="marketplace-catalogue"', false)
        ->assertSee('data-nav-item="marketplace"', false);
});
