<?php

use App\Actions\Feed\AttachFeedMedia;
use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;

/*
 * The janitor that closes the window the two-step upload leaves open.
 *
 * FeedMediaTest covers the same command, but it builds its fixtures with
 * `TemporaryUploadedFile::fake()->image()`, which needs PHP's GD extension; on a
 * machine without it those cases skip and this sweep has no executing coverage
 * at all. Everything here is written against the attachment factory instead —
 * the rows are what the command actually reads, and the bytes on the faked disk
 * are just placeholders — so the rules that decide what is garbage hold on any
 * machine.
 */

beforeEach(function (): void {
    Storage::fake(AttachFeedMedia::DISK);
});

/**
 * Put placeholder bytes on the fake disk at the path a row claims.
 */
function bytesFor(FeedAttachment $attachment): FeedAttachment
{
    Storage::disk(AttachFeedMedia::DISK)->put($attachment->path, 'not really a image');

    return $attachment;
}

function staleStagedUpload(int $hours = 25): FeedAttachment
{
    $attachment = FeedAttachment::factory()->staged()->create();

    $attachment->created_at = now()->subHours($hours);
    $attachment->save();

    return bytesFor($attachment);
}

it('removes a staged upload whose composer never came back for it', function () {
    $stale = staleStagedUpload();

    $this->artisan('feed:prune-media')->assertSuccessful();

    expect(FeedAttachment::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(Storage::disk(AttachFeedMedia::DISK)->exists($stale->path))->toBeFalse();
});

it('leaves a staged upload alone while the composer may still be open', function () {
    $fresh = bytesFor(FeedAttachment::factory()->staged()->create());

    $this->artisan('feed:prune-media')
        ->assertSuccessful()
        ->expectsOutputToContain('Nothing to prune');

    expect(FeedAttachment::query()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(Storage::disk(AttachFeedMedia::DISK)->exists($fresh->path))->toBeTrue();
});

it('never touches media a published post claims', function () {
    $post = FeedPost::factory()->create();
    $claimed = bytesFor(FeedAttachment::factory()->claimed($post)->create());

    // Backdated far past the grace period: the row is old, not abandoned.
    $claimed->created_at = now()->subDays(30);
    $claimed->save();

    $this->artisan('feed:prune-media')->assertSuccessful();

    expect(FeedAttachment::query()->whereKey($claimed->id)->exists())->toBeTrue()
        ->and(Storage::disk(AttachFeedMedia::DISK)->exists($claimed->path))->toBeTrue()
        ->and(FeedPost::query()->whereKey($post->id)->exists())->toBeTrue();
});

it('finds a file under the posts folder that no row describes any more', function () {
    bytesFor(FeedAttachment::factory()->claimed()->create());

    // The residue of a deletion that removed the row but missed the bytes.
    Storage::disk(AttachFeedMedia::DISK)->put('posts/4041/orphan.jpg', 'leftover');

    $this->artisan('feed:prune-media')
        ->assertSuccessful()
        ->expectsOutputToContain('orphan  posts/4041/orphan.jpg');

    expect(Storage::disk(AttachFeedMedia::DISK)->exists('posts/4041/orphan.jpg'))->toBeFalse();
});

it('does not hunt for orphans outside the folder the composer writes into', function () {
    Storage::disk(AttachFeedMedia::DISK)->put('temp/deadbeef/stray.jpg', 'leftover');

    $this->artisan('feed:prune-media')
        ->assertSuccessful()
        ->expectsOutputToContain('Nothing to prune');

    // The sweep only walks posts/, so a stray under temp/ survives the run. This
    // is the documented limit of the disk walk, not an accident: temp paths are
    // reclaimed through their row, and a row that is gone cannot be found by
    // guessing which key its file sat under.
    expect(Storage::disk(AttachFeedMedia::DISK)->exists('temp/deadbeef/stray.jpg'))->toBeTrue();
});

it('reports a stale row once, as staged rather than also as an orphan', function () {
    // A staged PNG under a path the posts sweep would also see if the two lists
    // were not diffed against each other before the query.
    $stale = FeedAttachment::factory()->staged('old-key')->png()->create([
        'path' => 'posts/77/late.png',
    ]);
    $stale->created_at = now()->subHours(25);
    $stale->save();
    bytesFor($stale);

    $this->artisan('feed:prune-media')
        ->assertSuccessful()
        ->expectsOutputToContain('staged  posts/77/late.png')
        ->doesntExpectOutputToContain('orphan  posts/77/late.png')
        ->expectsOutputToContain('Removed 1 file');

    expect(FeedAttachment::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(Storage::disk(AttachFeedMedia::DISK)->exists($stale->path))->toBeFalse();
});

it('removes nothing on a dry run and says what it would have taken', function () {
    $stale = staleStagedUpload();

    $this->artisan('feed:prune-media', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Would remove 1 file');

    expect(FeedAttachment::query()->whereKey($stale->id)->exists())->toBeTrue()
        ->and(Storage::disk(AttachFeedMedia::DISK)->exists($stale->path))->toBeTrue();
});

it('takes the grace period from the command line when the operator names one', function () {
    $twoHoursOld = staleStagedUpload(2);

    // The default window is a day; the override is what makes a longer
    // accumulation of abandoned staging reclaimable in one run.
    $this->artisan('feed:prune-media', ['--hours' => '1'])->assertSuccessful();

    expect(FeedAttachment::query()->whereKey($twoHoursOld->id)->exists())->toBeFalse();

    $another = staleStagedUpload(2);

    $this->artisan('feed:prune-media')->assertSuccessful()->expectsOutputToContain('Nothing to prune');

    expect(FeedAttachment::query()->whereKey($another->id)->exists())->toBeTrue();
});

it('keeps the row when the disk refuses to give the file up', function () {
    $stale = staleStagedUpload();

    /**
     * A disk that lists nothing and cannot delete anything. The row must survive
     * this: the record is the only thing that knows where the bytes are, and
     * deleting it first would turn a transient error into a permanent orphan.
     *
     * @var FilesystemAdapter&MockInterface $broken
     */
    $broken = Mockery::mock(FilesystemAdapter::class);
    $broken->shouldReceive('directoryExists')->andReturnFalse();
    $broken->shouldReceive('delete')->andThrow(new RuntimeException('read-only mount'));

    Storage::getFacadeRoot()->set(AttachFeedMedia::DISK, $broken);

    $this->artisan('feed:prune-media')
        ->assertSuccessful()
        ->expectsOutputToContain('could not delete');

    expect(FeedAttachment::query()->whereKey($stale->id)->exists())->toBeTrue();
});

it('is idempotent, so a second run costs nothing', function () {
    staleStagedUpload();

    $this->artisan('feed:prune-media')->assertSuccessful();

    $this->artisan('feed:prune-media')
        ->assertSuccessful()
        ->expectsOutputToContain('Nothing to prune');

    expect(FeedAttachment::query()->count())->toBe(0);
});

it('sweeps stale staging for every account without favouring one', function () {
    $first = staleStagedUpload();
    $second = staleStagedUpload();

    expect($first->user_id)->not->toBe($second->user_id);

    $this->artisan('feed:prune-media')
        ->assertSuccessful()
        ->expectsOutputToContain('Removed 2 files');

    expect(FeedAttachment::query()->count())->toBe(0)
        ->and(User::query()->whereKey($first->user_id)->exists())->toBeTrue();
});
