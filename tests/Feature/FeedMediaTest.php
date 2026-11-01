<?php

use App\Actions\Feed\AttachFeedMedia;
use App\Actions\Feed\ComposeFeedPost;
use App\Enums\FeedPostAudience;
use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * The media side of the composer: staging onto the private disk, the signed
 * stream that is the only door to the bytes, and the janitor that closes the
 * window an abandoned upload leaves open.
 *
 * `feed_media` is faked for every test here, so assertions about "the file
 * exists" mean the fake disk recorded the write the real code performed —
 * mime, size, and dimensions included, because those are read back from the
 * disk rather than taken from the upload.
 */
function mediaAuthor(string $name = 'Mira Kovač'): User
{
    return User::factory()->create(['name' => $name, 'email' => 'mira-'.Str::slug($name).'@example.com']);
}

/**
 * Attach one generated image through the real action, so the row under test
 * was written by the same code the composer runs.
 */
function attachImage(?FeedPost $post, User $uploader, string $name = 'photo.png', ?string $tempKey = null): FeedAttachment
{
    requiresGd();

    $file = TemporaryUploadedFile::fake()->image($name, 320, 240);

    return app(AttachFeedMedia::class)->handle($post, $uploader, [$file], $tempKey)->firstOrFail();
}

beforeEach(function (): void {
    Storage::fake('feed_media');
    Storage::fake('local');
    $this->author = mediaAuthor();
});

it('stages an upload onto the private disk owned by the uploader before any post exists', function (): void {
    requiresGd();

    actingAs($this->author);

    $component = Livewire::test('pages::feed.composer-modal')
        ->set('uploads', [TemporaryUploadedFile::fake()->image('diagram.png', 640, 480)]);

    $row = FeedAttachment::query()->firstOrFail();

    expect($row->disk)->toBe(AttachFeedMedia::DISK)
        ->and($row->feed_post_id)->toBeNull()
        ->and($row->user_id)->toBe($this->author->id)
        ->and($row->temp_key)->not->toBeNull()
        // Server truth, not the client's claim: the mime and the size were
        // read back from the bytes the disk actually holds.
        ->and($row->mime)->toBe('image/png')
        ->and($row->width)->toBe(640)
        ->and($row->height)->toBe(480)
        ->and($row->size)->toBeGreaterThan(0);

    expect($component->get('tempKey'))->toBe($row->temp_key);

    expect(Storage::disk('feed_media')->exists($row->path))->toBeTrue();
});

it('publishing claims the staged image and the signed route streams it to any reader of a public post', function (): void {
    requiresGd();

    actingAs($this->author);

    // Staged media makes Preview a step, so publishing takes two clicks.
    Livewire::test('pages::feed.composer-modal')
        ->set('body', 'Latency numbers from the last load test.')
        ->set('uploads', [TemporaryUploadedFile::fake()->image('latency.png', 800, 600)])
        ->call('publish')
        ->call('publish')
        ->assertDispatched('feed-post-published');

    $post = FeedPost::query()->latest('id')->firstOrFail();
    $attachment = $post->attachments()->firstOrFail();

    expect($attachment->feed_post_id)->toBe($post->id)
        ->and($attachment->temp_key)->toBeNull();

    // A guest reading a public post may load its image through the signed door.
    get($attachment->url())
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

    // And the card renders the stream route, not a bare disk path.
    get(route('feed.index'))->assertSee('feed/attachments', false);
});

it('refuses a signed URL once it has expired or been tampered with', function (): void {
    $post = FeedPost::factory()->authoredBy($this->author)->create();
    $attachment = attachImage($post, $this->author);

    $url = $attachment->url();
    $this->travel(11)->minutes();
    get($url)->assertStatus(400);

    $tampered = str_replace('signature=', 'signature=deadbeefdeadbeef', $attachment->url());
    get($tampered)->assertStatus(400);
});

it('hides a self-addressed post\'s image from everyone but its author', function (): void {
    $stranger = User::factory()->create(['name' => 'Ada Renner']);
    $post = FeedPost::factory()->authoredBy($this->author)->private($this->author)->create();
    $attachment = attachImage($post, $this->author);

    // The signature is valid; the reader simply is not entitled. A private
    // post's file is indistinguishable from one that does not exist.
    get($attachment->url())->assertNotFound();

    actingAs($stranger);
    get($attachment->url())->assertNotFound();

    actingAs($this->author);
    get($attachment->url())->assertOk();
});

it('shows a staged preview to its uploader alone before the post exists', function (): void {
    $stranger = User::factory()->create(['name' => 'Bo Lindgren']);
    $staged = attachImage(null, $this->author, 'work-in-progress.png', (string) Str::uuid());

    get($staged->url())->assertNotFound();

    actingAs($stranger);
    get($staged->url())->assertNotFound();

    actingAs($this->author);
    get($staged->url())->assertOk();
});

it('refuses a file that is not an image before it reaches the disk', function (): void {
    actingAs($this->author);

    Livewire::test('pages::feed.composer-modal')
        ->set('uploads', [UploadedFile::fake()->create('script.php', 12, 'text/x-php')])
        ->assertHasErrors(['uploads.0']);

    expect(FeedAttachment::query()->count())->toBe(0);
});

it('refuses an image below the minimum dimensions', function (): void {
    requiresGd();

    actingAs($this->author);

    Livewire::test('pages::feed.composer-modal')
        ->set('uploads', [TemporaryUploadedFile::fake()->image('tiny.png', 8, 8)])
        ->assertHasErrors(['uploads.0']);

    expect(FeedAttachment::query()->count())->toBe(0);
});

it('refuses an upload that busts the size cap', function (): void {
    actingAs($this->author);

    Livewire::test('pages::feed.composer-modal')
        ->set('uploads', [UploadedFile::fake()->create('huge.png', 8200, 'image/png')])
        ->assertHasErrors(['uploads.0']);

    expect(FeedAttachment::query()->count())->toBe(0);
});

it('refuses more images than a post can carry', function (): void {
    requiresGd();

    actingAs($this->author);

    $component = Livewire::test('pages::feed.composer-modal')
        ->set('uploads', [TemporaryUploadedFile::fake()->image('first.png', 64, 64)]);

    expect($component->get('stagedMedia'))->toHaveCount(1);

    $overflow = array_map(
        fn (int $n) => TemporaryUploadedFile::fake()->image("extra-{$n}.png", 64, 64),
        range(1, 8),
    );

    $component->set('uploads', $overflow)->assertHasErrors('uploads');

    // The cap rejected the batch wholesale; only the first image survived.
    expect(FeedAttachment::query()->count())->toBe(1);
});

it('deletes both the row and the bytes when the author removes an image', function (): void {
    requiresGd();

    actingAs($this->author);

    $component = Livewire::test('pages::feed.composer-modal')
        ->set('uploads', [TemporaryUploadedFile::fake()->image('mistake.png', 64, 64)]);

    $row = FeedAttachment::query()->firstOrFail();
    $path = $row->path;

    $component->call('removeMedia', $row->id);

    expect(FeedAttachment::query()->whereKey($row->id)->exists())->toBeFalse();
    expect(Storage::disk('feed_media')->exists($path))->toBeFalse();
});

it('keeps a stranger out of someone else\'s media', function (): void {
    $post = FeedPost::factory()->authoredBy($this->author)->create();
    $attachment = attachImage($post, $this->author);

    $intruder = User::factory()->create(['name' => 'Piotr Nowak']);
    actingAs($intruder);

    // Livewire's test broker renders the abort into the response rather than
    // rethrowing it, so the refusal surfaces as a 403 the harness exposes.
    Livewire::test('pages::feed.composer-modal')
        ->call('removeMedia', $attachment->id)
        ->assertStatus(403);

    expect(FeedAttachment::query()->whereKey($attachment->id)->exists())->toBeTrue();
});

it('stores alt text against the image and renders it on the feed card', function (): void {
    actingAs($this->author);

    $attachment = attachImage(null, $this->author, 'chart.png', $tempKey = (string) Str::uuid());

    $component = Livewire::test('pages::feed.composer-modal')
        ->set('tempKey', $tempKey)
        ->set("alts.{$attachment->id}", 'A chart of queue latency over a week.');

    expect($attachment->refresh()->alt_text)->toBe('A chart of queue latency over a week.');

    $post = app(ComposeFeedPost::class)->handle($this->author, [
        'body' => 'Load test results, at last.',
        'type' => 'discussion',
        'topic' => 'community',
        'audience' => FeedPostAudience::Public,
    ], null, $tempKey);

    expect($post->attachments()->firstOrFail()->alt_text)->toBe('A chart of queue latency over a week.');

    get(route('feed.index'))->assertSee('A chart of queue latency over a week.');
});

it('surfaces a storage failure as an actionable error without losing the body', function (): void {
    requiresGd();

    // Swap only the feed_media disk on the freshly-faked manager for one that
    // refuses to write. Everything else — including the temp copy Livewire
    // staged on the local disk — keeps working.

    /** @var FilesystemAdapter&MockInterface $broken */
    $broken = Mockery::mock(FilesystemAdapter::class);
    $broken->shouldReceive('put')->andThrow(new RuntimeException('disk full'));
    $broken->shouldReceive('delete')->andReturnTrue();

    Storage::getFacadeRoot()->set(AttachFeedMedia::DISK, $broken);

    actingAs($this->author);

    Livewire::test('pages::feed.composer-modal')
        ->set('body', 'Text that must survive a failed upload.')
        ->set('uploads', [TemporaryUploadedFile::fake()->image('huge.png', 800, 600)])
        ->assertHasErrors(['uploads'])
        ->assertSet('body', 'Text that must survive a failed upload.');

    expect(FeedAttachment::query()->count())->toBe(0);
});

it('reclaims abandoned staging and orphaned files, and leaves everything else alone', function (): void {
    $cutoffAuthor = mediaAuthor('Nils Bergström');

    $stale = attachImage(null, $cutoffAuthor, 'abandoned.png', 'stale-key');
    $stale->created_at = now()->subHours(25);
    $stale->save();

    $fresh = attachImage(null, $cutoffAuthor, 'mid-flight.png', 'fresh-key');
    $attached = attachImage(FeedPost::factory()->authoredBy($cutoffAuthor)->create(), $cutoffAuthor, 'published.png');

    // A file with no row at all — the residue of a deletion that missed bytes.
    Storage::disk('feed_media')->put('posts/orphan/ghost.png', 'leftover');

    // A dry run reports and touches nothing.
    $this->artisan('feed:prune-media', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Would remove');

    expect(FeedAttachment::query()->whereKey($stale->id)->exists())->toBeTrue();

    $this->artisan('feed:prune-media')->assertSuccessful();

    expect(FeedAttachment::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(FeedAttachment::query()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(FeedAttachment::query()->whereKey($attached->id)->exists())->toBeTrue();

    expect(Storage::disk('feed_media')->exists($stale->path))->toBeFalse();
    expect(Storage::disk('feed_media')->exists($fresh->path))->toBeTrue();
    expect(Storage::disk('feed_media')->exists($attached->path))->toBeTrue();
    expect(Storage::disk('feed_media')->exists('posts/orphan/ghost.png'))->toBeFalse();

    // Running again finds nothing — the sweep is idempotent.
    $this->artisan('feed:prune-media')
        ->assertSuccessful()
        ->expectsOutputToContain('Nothing to prune');
});

it('honours an explicit grace period shorter than the default', function (): void {
    $recent = attachImage(null, $this->author, 'two-hours-old.png', 'recent-key');
    $recent->created_at = now()->subHours(2);
    $recent->save();

    // Inside the default 24-hour window the file is still someone's in-flight
    // upload; with a one-hour grace it is already garbage.
    $this->artisan('feed:prune-media')->assertSuccessful();
    expect(FeedAttachment::query()->whereKey($recent->id)->exists())->toBeTrue();

    $this->artisan('feed:prune-media', ['--hours' => '1'])->assertSuccessful();
    expect(FeedAttachment::query()->whereKey($recent->id)->exists())->toBeFalse();
});
