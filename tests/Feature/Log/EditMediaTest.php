<?php

use App\Actions\Feed\AttachFeedMedia;
use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Images on the inline editor.
 *
 * The editor shipped text-only: a row drew its pictures and the form that
 * rewrote the row could not touch them. A post whose image is wrong is a post
 * that is wrong, so the editor now carries the same media controls the composer
 * does — and shares its single write path rather than growing a second one.
 *
 * The property that needs pinning hardest is the one that is easy to get wrong
 * and invisible when it is: **the destination**. The upload hook reads where a
 * pick should land from whether an editor is open, so a bug here does not fail
 * loudly, it files a reader's image against the wrong post — or against no post
 * at all, leaving it staged and unreachable.
 */
beforeEach(function (): void {
    Storage::fake('feed_media');
    Storage::fake('local');

    $this->owner = User::factory()->create(['handle' => 'editimg-owner']);
    $this->post = FeedPost::factory()->create([
        'user_id' => $this->owner->id,
        'handle' => '@editimg-owner',
        'author' => 'Owner Person',
        'body' => 'A post that will gain a picture.',
    ]);
});

/**
 * A real 32×32 PNG, generated without GD.
 *
 * The byte buffer is built here rather than with `imagecreatetruecolor` because
 * the test environment has no GD and `requiresGd()` would skip every test in
 * this file. It has to be a *valid, large enough* image and not the 1×1 the
 * limits test uses: the composer refuses anything under 16px, so a 1×1 fixture
 * would fail validation and quietly report a working feature as broken.
 */
function editableImage(string $name = 'shot.png'): UploadedFile
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAL0lEQVR4nO3OIQEAAAgDMOIQkYi0ghg3E/Ornr2kEhAQEBAQEBAQEBAQEBAQSAcesCiciKcA8YUAAAAASUVORK5CYII=',
    );

    return UploadedFile::fake()->createWithContent($name, $png);
}

/** A post attachment, written the way the action writes one. */
function attachedImage(FeedPost $post, User $user, string $name = 'one.png'): FeedAttachment
{
    $stored = app(AttachFeedMedia::class)->handle($post, $user, [editableImage($name)]);

    return $stored->first();
}

it('attaches a picked image to the row the editor has open', function (): void {
    /* The core of the feature. The pick lands on the post being edited and not
       on the composer's staging area — which is what the `temp_key` filter
       proves: a staged row carries a key, a claimed one does not. */
    actingAs($this->owner);

    $component = Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->set('uploads', [editableImage()]);

    expect($component->instance()->editingMedia)->toHaveCount(1);

    $attachment = $component->instance()->editingMedia->first();

    expect($attachment->feed_post_id)->toBe($this->post->id);
    expect($attachment->temp_key)->toBeNull();
});

it('files nothing away as staged when the editor takes the upload', function (): void {
    actingAs($this->owner);

    Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->set('uploads', [editableImage()]);

    expect(FeedAttachment::query()->whereNull('feed_post_id')->count())->toBe(0);
});

it('still stages a pick when no editor is open', function (): void {
    /* The other half of the branch: with nothing open the same property must
       behave exactly as it did before, or the fix for editing would break
       posting. */
    actingAs($this->owner);

    $component = Livewire::test('pages::feed')->set('uploads', [editableImage()]);

    expect($component->instance()->stagedMedia)->toHaveCount(1);
    expect($component->instance()->editingMedia)->toHaveCount(0);

    expect(FeedAttachment::query()->whereNull('feed_post_id')->count())->toBe(1);
});

it('counts a row\'s existing images against the cap when editing', function (): void {
    /* The cap is post-wide. An edit reads the *post's* attachments, not the
       staged list — reading the staged list would let a row already at the cap
       take another full cap on top, which is the same class of bug the cap
       guard was written for in the first place. */
    config(['feed.composer.max_attachments' => 2]);

    actingAs($this->owner);

    attachedImage($this->post, $this->owner, 'a.png');
    attachedImage($this->post, $this->owner, 'b.png');

    $component = Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->set('uploads', [editableImage('c.png')])
        ->assertHasErrors('uploads');

    expect($component->instance()->editingMedia)->toHaveCount(2);
});

it('drops a refused pick instead of leaving it on the property', function (): void {
    /* The leak fixed for the composer applies identically here, and the thing
       that regressed most easily while adding the branch was exactly this: a
       new early return added above the `finally`. */
    actingAs($this->owner);

    $component = Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->set('uploads', [
            UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'),
        ])
        ->assertHasErrors('uploads.0');

    expect($component->instance()->uploads)->toBeNull();
});

it('renders the refusal in the editor', function (): void {
    /* A per-file rule fails as `uploads.0`. A slot written as `@error('uploads')`
       renders nothing for the likeliest refusal of all, and the file vanishes
       with no explanation. */
    actingAs($this->owner);

    $html = Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->set('uploads', [UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf')])
        ->html();

    expect(str_contains($html, 'data-test="edit-media-error"'))->toBeTrue();
});

it('removes an image from the row and its bytes from the disk', function (): void {
    actingAs($this->owner);

    $attachment = attachedImage($this->post, $this->owner);
    $path = $attachment->path;

    Storage::disk('feed_media')->assertExists($path);

    $component = Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->call('removeEditMedia', $attachment->id);

    expect($component->instance()->editingMedia)->toHaveCount(0);
    expect(FeedAttachment::query()->find($attachment->id))->toBeNull();

    Storage::disk('feed_media')->assertMissing($path);
});

it('will not remove an image that belongs to a different post', function (): void {
    /* The attachment id arrives from the browser, so it is not trusted to imply
       its own parent. A caller naming an id that is not on the open row must
       reach nothing. */
    actingAs($this->owner);

    $other = FeedPost::factory()->create(['user_id' => $this->owner->id]);
    $foreign = attachedImage($other, $this->owner, 'other.png');

    $component = Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->call('removeEditMedia', $foreign->id);

    expect(FeedAttachment::query()->find($foreign->id))->not->toBeNull();
    expect(Storage::disk('feed_media')->exists($foreign->path))->toBeTrue();
});

it('will not let a stranger attach an image through an open editor', function (): void {
    /* Every wire action is a public endpoint. A stranger who names somebody
       else's row must not be able to add media to it. */
    $stranger = User::factory()->create(['handle' => 'editimg-stranger']);

    actingAs($stranger);

    Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->set('uploads', [editableImage()]);

    expect(FeedAttachment::query()->where('feed_post_id', $this->post->id)->count())->toBe(0);
});

it('will not let a stranger remove another author\'s image', function (): void {
    $attachment = attachedImage($this->post, $this->owner);

    $stranger = User::factory()->create(['handle' => 'editimg-thief']);
    actingAs($stranger);

    Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->call('removeEditMedia', $attachment->id);

    expect(FeedAttachment::query()->find($attachment->id))->not->toBeNull();
});

it('closes the editor without a pending upload landing on the next row', function (): void {
    /* The destination is read from `editingId` at the moment the hook runs, so
       an upload still in flight when the editor closes would land on whichever
       row is edited next. Cancelling drops the pending batch. */
    actingAs($this->owner);

    $component = Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->set('uploads', [editableImage('pending.png')]);

    expect($component->instance()->editingMedia)->toHaveCount(1);

    $component->call('cancelEdit');

    expect($component->instance()->uploads)->toBeNull();
    expect($component->instance()->editingMedia)->toHaveCount(0);
});

it('draws the editor\'s media controls on an own row', function (): void {
    actingAs($this->owner);

    $html = Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->html();

    expect(str_contains($html, 'data-test="edit-media"'))->toBeTrue();
    expect(str_contains($html, 'data-test="edit-add-media"'))->toBeTrue();

    // The add control asks for the composer's picker rather than owning a file
    // field — a second `type="file"` anywhere would be a second write path.
    expect(substr_count($html, 'type="file"'))->toBe(1);
});

it('draws a remove control for each image the row already carries', function (): void {
    actingAs($this->owner);

    attachedImage($this->post, $this->owner, 'a.png');
    attachedImage($this->post, $this->owner, 'b.png');

    $html = Livewire::test('pages::feed')
        ->call('openEdit', $this->post->id)
        ->html();

    expect(substr_count($html, 'data-test="edit-remove-media"'))->toBe(2);
});
