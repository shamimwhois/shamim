<?php

use App\Actions\Feed\AttachFeedMedia;
use App\Actions\Feed\DetachFeedMedia;
use App\Models\FeedAttachment;
use App\Models\FeedPost;
use Illuminate\Contracts\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| Detaching one attachment
|--------------------------------------------------------------------------
|
| The composer's remove button is the only place a single upload is taken back
| out, and the two halves of that take have to stay together: a row whose bytes
| are gone renders a broken image, and bytes whose row is gone are invisible to
| everything except the prune command. These are the cases a Livewire test
| cannot isolate, because the interesting one is a disk that fails.
|
| The failure case is the reason this file exists. The action's own comment says
| the leftover file becomes an orphan on purpose; nothing else in the suite
| proves the click still succeeds when the disk says no.
|
*/

function attachment(array $attributes = []): FeedAttachment
{
    return FeedAttachment::factory()->staged()->create($attributes);
}

it('removes the row and the bytes it points at together', function (): void {
    Storage::fake(AttachFeedMedia::DISK);

    $record = attachment();
    Storage::disk(AttachFeedMedia::DISK)->put($record->path, 'the bytes');

    expect(app(DetachFeedMedia::class)->handle($record))->toBeTrue()
        ->and(FeedAttachment::query()->whereKey($record->id)->exists())->toBeFalse()
        ->and(Storage::disk(AttachFeedMedia::DISK)->exists($record->path))->toBeFalse();
});

it('asks the disk named by the row rather than the disk the module defaults to', function (): void {
    $record = attachment();
    $record->forceFill(['disk' => 'archive'])->save();

    /**
     * The disk column is what a moved or mirrored upload is findable by. A call
     * hard-coded to `feed_media` would delete the row and leave the bytes under a
     * name nothing else reads.
     *
     * @var FilesystemAdapter&MockInterface $disk
     */
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('delete')->once()->with($record->path)->andReturnTrue();

    Storage::getFacadeRoot()->set('archive', $disk);

    expect(app(DetachFeedMedia::class)->handle($record))->toBeTrue()
        ->and(FeedAttachment::query()->whereKey($record->id)->exists())->toBeFalse();

    Storage::getFacadeRoot()->forgetDisk('archive');
});

it('lets the row go when the disk refuses, and leaves the bytes for the prune command', function (): void {
    Storage::fake(AttachFeedMedia::DISK);

    $record = attachment();
    $path = $record->path;
    Storage::disk(AttachFeedMedia::DISK)->put($path, 'the bytes');

    // Held so the real disk can be put back afterwards: forgetting it would
    // re-resolve the name from config and the fake's temporary directory with it.
    $staged = Storage::disk(AttachFeedMedia::DISK);

    /**
     * @var FilesystemAdapter&MockInterface $broken
     */
    $broken = Mockery::mock(FilesystemAdapter::class);
    $broken->shouldReceive('delete')->andThrow(new RuntimeException('disk offline'));

    Storage::getFacadeRoot()->set(AttachFeedMedia::DISK, $broken);

    // The remove click must not fail over a leftover file: the alternative is a
    // composer that cannot take an image back off a post at all.
    expect(app(DetachFeedMedia::class)->handle($record))->toBeTrue()
        ->and(FeedAttachment::query()->whereKey($record->id)->exists())->toBeFalse();

    // Put the real disk back to see what the failure actually left behind.
    Storage::getFacadeRoot()->set(AttachFeedMedia::DISK, $staged);

    expect($staged->exists($path))->toBeTrue();
});

it('says when there was no row left to delete', function (): void {
    Storage::fake(AttachFeedMedia::DISK);

    $record = attachment();

    expect(app(DetachFeedMedia::class)->handle($record))->toBeTrue()
        // The same model instance, handed to a second click after the composer
        // re-rendered: nothing is left to take away, and the caller has to be able
        // to say so instead of claiming a deletion.
        ->and(app(DetachFeedMedia::class)->handle($record))->toBeFalse();
});

it('leaves every other attachment of the same post alone', function (): void {
    Storage::fake(AttachFeedMedia::DISK);

    $post = FeedPost::factory()->create();
    $first = FeedAttachment::factory()->claimed($post, 0)->create();
    $second = FeedAttachment::factory()->claimed($post, 1)->create();

    Storage::disk(AttachFeedMedia::DISK)->put($first->path, 'one');
    Storage::disk(AttachFeedMedia::DISK)->put($second->path, 'two');

    app(DetachFeedMedia::class)->handle($first);

    expect(FeedAttachment::query()->whereKey($second->id)->exists())->toBeTrue()
        ->and($second->fresh()->feed_post_id)->toBe($post->id)
        ->and(Storage::disk(AttachFeedMedia::DISK)->exists($second->path))->toBeTrue();
});
