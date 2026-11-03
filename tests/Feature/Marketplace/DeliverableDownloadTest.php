<?php

use App\Actions\Marketplace\PublishRelease;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Releases and the deliverable a buyer downloads
|--------------------------------------------------------------------------
|
| A release is the only place a listing's actual product lives, so the two
| things worth pinning down are the shape of the release history and who may
| pull bytes out of it:
|
|   - exactly one release is current, and the current one is what a buyer gets.
|     That invariant is stated once, in `PublishRelease`, so a screen cannot be
|     the only thing enforcing it.
|   - the download door opens for the buyer of an entitling order and for nobody
|     else, and it answers 404 rather than 403 so a stranger cannot walk the ids
|     and learn which orders were paid for.
|
| The package fixtures are gzip streams rather than fake random bytes, because
| what the row records is the mime the *disk* reports, and a file of random bytes
| is not an archive to libmagic no matter what it was named.
|
*/

beforeEach(function (): void {
    Storage::fake((string) config('marketplace.deliverable.disk', 'marketplace_media'));
});

function packageFile(string $name = 'release-1.0.0.tar.gz'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) gzencode('the shipped package', 9));
}

/**
 * A paid order for a listing whose current release carries a real file.
 *
 * @return array{User, MarketplaceProduct, MarketplaceOrder, MarketplaceProductVersion}
 */
function paidOrderWithPackage(array $orderState = []): array
{
    $seller = User::factory()->seller()->create();
    $buyer = User::factory()->create();

    $listing = MarketplaceProduct::factory()->published()->create(['user_id' => $seller->id]);

    $release = app(PublishRelease::class)->handle($seller, $listing, [
        'version' => '1.0.0',
        'release_notes' => 'First shipped release.',
        'is_current' => true,
    ], packageFile());

    $order = MarketplaceOrder::factory()->forBuyer($buyer)->forProduct($listing)->paid()->create($orderState);

    return [$buyer, $listing, $order, $release];
}

function bytesOnDisk(MarketplaceProductVersion $release): string
{
    return (string) gzencode('the shipped package', 9);
}

test('a release records the package as the disk describes it', function () {
    $seller = User::factory()->seller()->create();
    $listing = MarketplaceProduct::factory()->published()->create(['user_id' => $seller->id]);

    $release = app(PublishRelease::class)->handle($seller, $listing, [
        'version' => '2.1.0',
        'release_notes' => "What's new.",
    ], packageFile('renamed-but-really-gzip.gz'));

    expect(in_array($release->mime, (array) config('marketplace.deliverable.accepted_mimes'), true))->toBeTrue()
        ->and($release->size)->toBe(strlen(bytesOnDisk($release)))
        ->and($release->hasDeliverable())->toBeTrue()
        ->and(Storage::disk($release->disk)->exists($release->path))->toBeTrue()
        ->and($release->original_name)->toBe('renamed-but-really-gzip.gz');
});

test('a release may carry no file at all', function () {
    $seller = User::factory()->seller()->create();
    $listing = MarketplaceProduct::factory()->create(['user_id' => $seller->id]);

    $release = app(PublishRelease::class)->handle($seller, $listing, ['version' => '1.0.0']);

    expect($release->hasDeliverable())->toBeFalse()
        ->and($release->path)->toBeNull()
        ->and($release->disk)->toBeNull();
});

test('exactly one release is current and the listing advertises it', function () {
    $seller = User::factory()->seller()->create();
    $listing = MarketplaceProduct::factory()->create(['user_id' => $seller->id, 'version' => '1.0.0']);

    app(PublishRelease::class)->handle($seller, $listing, ['version' => '1.0.0']);
    $second = app(PublishRelease::class)->handle($seller, $listing, ['version' => '1.1.0']);

    expect($listing->refresh()->currentVersion?->id)->toBe($second->id)
        ->and($listing->refresh()->version)->toBe('1.1.0')
        ->and($listing->versions()->where('is_current', true)->count())->toBe(1);

    // Back-dating a release must not roll the listing's version backwards.
    $older = app(PublishRelease::class)->handle($seller, $listing, [
        'version' => '0.9.0',
        'released_at' => now()->subYear()->toDateString(),
        'is_current' => false,
    ]);

    expect($older->is_current)->toBeFalse()
        ->and($listing->refresh()->currentVersion?->id)->toBe($second->id);
});

test('a version already published for the listing is refused', function () {
    $seller = User::factory()->seller()->create();
    $listing = MarketplaceProduct::factory()->create(['user_id' => $seller->id]);

    app(PublishRelease::class)->handle($seller, $listing, ['version' => '1.0.0']);

    expect(fn () => app(PublishRelease::class)->handle($seller, $listing, ['version' => '1.0.0']))
        ->toThrow(InvalidArgumentException::class)
        ->and($listing->versions()->count())->toBe(1);
});

test('only the author may publish a release for a listing', function () {
    $listing = MarketplaceProduct::factory()->create(['user_id' => User::factory()->seller()->create()->id]);
    $intruder = User::factory()->seller()->create();

    expect(fn () => app(PublishRelease::class)->handle($intruder, $listing, ['version' => '9.9.9']))
        ->toThrow(InvalidArgumentException::class)
        ->and($listing->versions()->count())->toBe(0);
});

test('a file that is not one of the accepted archives is refused and leaves nothing behind', function () {
    $seller = User::factory()->seller()->create();
    $listing = MarketplaceProduct::factory()->create(['user_id' => $seller->id]);

    $script = UploadedFile::fake()->createWithContent('install.sh', "#!/bin/sh\nrm -rf /\n");

    expect(fn () => app(PublishRelease::class)->handle($seller, $listing, ['version' => '1.0.0'], $script))
        ->toThrow(ValidationException::class)
        ->and($listing->versions()->count())->toBe(0)
        // The rejection deletes the file it just wrote; the folder it landed in
        // stays, so the claim to check is that no byte of the script survived.
        ->and(Storage::disk((string) config('marketplace.deliverable.disk'))->allFiles('deliverables'))->toBe([]);
});

test('the seller’s editor publishes a release from the form', function () {
    $seller = User::factory()->seller()->create();
    $listing = MarketplaceProduct::factory()->create(['user_id' => $seller->id, 'version' => '1.0.0']);

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->assertSee('Releases')
        ->set('releaseVersion', '1.2.0')
        ->set('releaseNotes', 'Fixes the export timeout.')
        ->set('releaseFile', packageFile('export-fix.tar.gz'))
        ->call('publish')
        ->assertHasNoErrors();

    /** @var MarketplaceProductVersion $release */
    $release = $listing->refresh()->currentVersion;

    expect($release->version)->toBe('1.2.0')
        ->and($release->release_notes)->toBe('Fixes the export timeout.')
        ->and($release->deliverableName())->toBe('export-fix.tar.gz')
        ->and($listing->refresh()->version)->toBe('1.2.0');
});

test('a blank version number is refused by the form before anything is written', function () {
    $seller = User::factory()->seller()->create();
    $listing = MarketplaceProduct::factory()->create(['user_id' => $seller->id]);

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->set('releaseVersion', '')
        ->call('publish')
        ->assertHasErrors(['releaseVersion' => 'required']);

    expect($listing->versions()->count())->toBe(0);
});

test('a deliverable over the size cap is refused by the form', function () {
    $seller = User::factory()->seller()->create();
    $listing = MarketplaceProduct::factory()->create(['user_id' => $seller->id]);

    // Real bytes, because the cap is measured on what landed on the disk. The
    // ceiling is lowered rather than the file enlarged so the assertion stays
    // about the rule and not about how long a twenty megabyte write takes.
    config(['marketplace.deliverable.max_upload_kilobytes' => 8]);

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->set('releaseVersion', '1.0.0')
        ->set('releaseFile', UploadedFile::fake()->createWithContent('enormous.zip', str_repeat('a', 9 * 1024)))
        ->call('publish')
        ->assertHasErrors(['releaseFile' => 'max']);

    expect($listing->versions()->count())->toBe(0);
});

test('swapping a release’s file replaces the bytes and forgets the old ones', function () {
    $seller = User::factory()->seller()->create();
    $listing = MarketplaceProduct::factory()->create(['user_id' => $seller->id]);

    $release = app(PublishRelease::class)->handle($seller, $listing, ['version' => '1.0.0'], packageFile());
    $previous = $release->path;

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->set('releaseFile', UploadedFile::fake()->createWithContent('second.tar.gz', (string) gzencode('repacked', 9)))
        ->call('replaceDeliverable', $release->id)
        ->assertHasNoErrors();

    $release->refresh();

    expect($release->path)->not->toBe($previous)
        ->and($release->original_name)->toBe('second.tar.gz')
        ->and(Storage::disk($release->disk)->exists($previous))->toBeFalse()
        ->and(Storage::disk($release->disk)->exists($release->path))->toBeTrue();
});

test('an entitled buyer streams the current release’s package', function () {
    [$buyer, , $order] = paidOrderWithPackage();

    $response = $this->actingAs($buyer)->get(route('purchases.download', $order));

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="release-1.0.0.tar.gz"')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertStreamedContent(bytesOnDisk($order->product->currentVersion));
});

test('a download does not move the listing’s purchase counter', function () {
    [$buyer, $listing, $order] = paidOrderWithPackage();

    $before = $listing->refresh()->download_count;

    $this->actingAs($buyer)->get(route('purchases.download', $order))->assertOk();

    expect($listing->refresh()->download_count)->toBe($before);
});

test('an order that has not been paid cannot download', function () {
    [$buyer, , $order] = paidOrderWithPackage(['status' => OrderStatus::Pending, 'paid_at' => null]);

    $this->actingAs($buyer)->get(route('purchases.download', $order))->assertNotFound();
});

test('a refunded order no longer entitles', function () {
    [$buyer, , $order] = paidOrderWithPackage();

    $order->forceFill(['status' => OrderStatus::Refunded, 'refunded_at' => now()])->save();

    $this->actingAs($buyer)->get(route('purchases.download', $order))->assertNotFound();
});

test('a partial refund still leaves the buyer entitled', function () {
    [$buyer, , $order] = paidOrderWithPackage();

    $order->forceFill(['status' => OrderStatus::PartiallyRefunded, 'refunded_at' => now()])->save();

    $this->actingAs($buyer)->get(route('purchases.download', $order))->assertOk();
});

test('another account cannot download a purchase it does not own', function () {
    [, , $order] = paidOrderWithPackage();

    $this->actingAs(User::factory()->create())->get(route('purchases.download', $order))->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->get(route('purchases.download', $order))->assertNotFound();
})->group('security');

test('a guest is bounced to sign in before the order is looked up', function () {
    $this->get(route('purchases.download', 1))
        ->assertRedirect(route('home', ['signin' => 'required']));
});

test('a release with no file has no download, and the order screen says so', function () {
    $seller = User::factory()->seller()->create();
    $buyer = User::factory()->create();
    $listing = MarketplaceProduct::factory()->published()->create(['user_id' => $seller->id]);

    app(PublishRelease::class)->handle($seller, $listing, ['version' => '1.0.0']);

    $order = MarketplaceOrder::factory()->forBuyer($buyer)->forProduct($listing)->paid()->create();

    $this->actingAs($buyer)->get(route('purchases.download', $order))->assertNotFound();

    $this->actingAs($buyer)
        ->get(route('purchases.show', $order))
        ->assertOk()
        ->assertSee('has not attached a file')
        ->assertDontSee('order-download');
});

test('a listing withdrawn after the sale still delivers', function () {
    [$buyer, $listing, $order] = paidOrderWithPackage();

    $listing->delete();

    $this->actingAs($buyer)->get(route('purchases.download', $order))->assertOk();
});

test('a buyer sees the download on the order screen while the file exists', function () {
    [$buyer, , $order] = paidOrderWithPackage();

    $this->actingAs($buyer)
        ->get(route('purchases.show', $order))
        ->assertOk()
        ->assertSee('release-1.0.0.tar.gz')
        ->assertSee('purchases/'.$order->id.'/download');
});

test('the download link does not appear while the listing has no release', function () {
    $buyer = User::factory()->create();
    $listing = MarketplaceProduct::factory()->published()->create();

    $order = MarketplaceOrder::factory()->forBuyer($buyer)->forProduct($listing)->paid()->create();

    $this->actingAs($buyer)
        ->get(route('purchases.show', $order))
        ->assertOk()
        ->assertDontSee('purchases/'.$order->id.'/download');
});

test('a withdrawn listing stops being editable but keeps its release history', function () {
    $seller = User::factory()->seller()->create();
    $listing = MarketplaceProduct::factory()->create(['user_id' => $seller->id]);

    app(PublishRelease::class)->handle($seller, $listing, ['version' => '1.0.0']);

    $listing->forceFill(['status' => ProductStatus::Archived])->save();

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->assertOk()
        ->assertSee('1.0.0')
        ->call('save')
        ->assertForbidden();
});
