<?php

use App\Actions\Marketplace\AttachProductScreenshot;
use App\Actions\Marketplace\DetachProductScreenshot;
use App\Enums\ProductStatus;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Listing screenshots
|--------------------------------------------------------------------------
|
| The gallery is the first place in the marketplace where attacker-chosen bytes
| are stored and handed back out again, so the tests here are about the two
| doors rather than about pictures:
|
|   - what the row claims must be what the disk says. The mime a browser sent is
|     discarded, the bytes are read back, and a file that does not report as an
|     image is deleted instead of recorded.
|   - the bytes are only reachable through a signed link to a listing the reader
|     may see. A draft's screenshot must not be servable to a stranger, and a
|     tampered link must not be servable to anyone.
|
| The uploads here are assembled byte by byte — see
| {@see screenshotBytes()} — rather than drawn by Laravel's image factory, which
| needs GD and is therefore not a fixture every machine can make. Nothing in
| this file depends on GD: the decoders that read the pictures are core PHP.
|
*/

beforeEach(function (): void {
    Storage::fake((string) config('marketplace.media.disk', AttachProductScreenshot::DISK));
});

/**
 * A seller's saved listing — the only shape a gallery can attach to.
 */
function mediaListing(?User $seller = null): MarketplaceProduct
{
    return MarketplaceProduct::factory()->create([
        'user_id' => ($seller ?? User::factory()->seller()->create())->id,
    ]);
}

/**
 * A real PNG of the size asked for, optionally padded to `length` bytes.
 *
 * Assembled by hand rather than drawn because the factory that draws sample
 * bitmaps needs GD, which is not installed on every machine that runs this
 * suite, and because a limit is only worth testing against bytes that actually
 * exist: an image fake that merely *reports* a bigger number is a fixture the
 * `max:` rule never sees. The signature and the IHDR chunk are all any reader —
 * `getimagesize`, fileinfo, the read-back in {@see AttachProductScreenshot} —
 * needs in order to agree on what the picture is.
 */
function screenshotBytes(int $width = 640, int $height = 480, int $length = 0): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data))
        .$type.$data.pack('N', crc32($type.$data));

    // Filter byte 0, then truecolour RGB — the cheapest picture that decodes.
    $rows = str_repeat("\x00".str_repeat("\xff", $width * 3), $height);

    $png = "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('N2', $width, $height).chr(8).chr(2).chr(0).chr(0).chr(0))
        .$chunk('IDAT', gzcompress($rows))
        .$chunk('IEND', '');

    return $png.str_repeat("\x00", max(0, $length - strlen($png)));
}

/**
 * A screenshot upload, as the browser would hand it over.
 */
function screenshotFile(string $name = 'shot.png', int $width = 640, int $height = 480): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, screenshotBytes($width, $height));
}

test('the editor offers the gallery only once the listing exists', function () {
    $seller = User::factory()->seller()->create();

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit')
        ->assertOk()
        ->assertDontSee('Screenshots');

    Livewire::actingAs($seller)
        ->test('pages::seller.product-edit', ['product' => mediaListing($seller)])
        ->assertOk()
        ->assertSee('Screenshots');
});

test('an upload is recorded as the disk describes it, not as the browser claimed', function () {
    $listing = mediaListing();

    Livewire::actingAs($listing->creator)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->set('uploads', [screenshotFile('forged.php.png', 320, 200)]);

    /** @var MarketplaceProductMedia $media */
    $media = $listing->media()->sole();

    expect($media->mime)->toBe('image/png')
        ->and($media->width)->toBe(320)
        ->and($media->height)->toBe(200)
        ->and($media->size)->toBe(Storage::disk($media->disk)->size($media->path))
        ->and($media->sort_order)->toBe(1);

    expect(Storage::disk($media->disk)->exists($media->path))->toBeTrue();
});

test('the stored name is generated rather than taken from the upload', function () {
    $listing = mediaListing();

    Livewire::actingAs($listing->creator)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->set('uploads', [screenshotFile('../../evil.png')]);

    /** @var MarketplaceProductMedia $media */
    $media = $listing->media()->sole();

    expect($media->path)->not->toContain('evil')
        ->and($media->path)->not->toContain('..')
        ->toStartWith('listings/'.$listing->id.'/');
});

test('a file that is not an image is refused and leaves nothing on the disk', function () {
    $listing = mediaListing();

    Livewire::actingAs($listing->creator)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->set('uploads', [UploadedFile::fake()->create('script.php', 12, 'text/x-php')])
        ->assertHasErrors(['uploads.0']);

    expect($listing->media()->count())->toBe(0)
        ->and(Storage::disk((string) config('marketplace.media.disk'))->files('listings'))->toBe([]);
});

test('an image over the size cap is refused before anything is recorded', function () {
    $listing = mediaListing();

    // The cap is measured on the bytes that landed, so the fixture is enlarged
    // in proportion and the ceiling lowered: the same rule, a fraction of the write.
    config(['marketplace.media.max_upload_kilobytes' => 1]);

    Livewire::actingAs($listing->creator)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->set('uploads', [UploadedFile::fake()->createWithContent('huge.png', screenshotBytes(16, 16, 2048))])
        ->assertHasErrors(['uploads.0' => 'max']);

    expect($listing->media()->count())->toBe(0);
});

test('the gallery stops at the cap the config sets', function () {
    $cap = (int) config('marketplace.media.max_per_listing', 8);
    $listing = mediaListing();

    MarketplaceProductMedia::factory()->count($cap)->create(['marketplace_product_id' => $listing->id]);

    Livewire::actingAs($listing->creator)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->set('uploads', [screenshotFile()])
        ->assertHasErrors('uploads');

    expect($listing->media()->count())->toBe($cap);
});

test('alt text is stored against the image it describes and read back as its description', function () {
    $listing = mediaListing();
    /** @var MarketplaceProductMedia $media */
    $media = MarketplaceProductMedia::factory()->create(['marketplace_product_id' => $listing->id]);

    Livewire::actingAs($listing->creator)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->set("alts.{$media->id}", 'The dashboard the package builds.');

    expect($media->refresh()->alt_text)->toBe('The dashboard the package builds.')
        ->and($media->alt())->toBe('The dashboard the package builds.');

    $media->update(['alt_text' => null]);

    expect($media->alt())->toBe('Screenshot of '.$listing->name);
});

test('alt text cannot be written onto an image belonging to another listing', function () {
    $mine = mediaListing();
    /** @var MarketplaceProductMedia $foreign */
    $foreign = MarketplaceProductMedia::factory()->create(['marketplace_product_id' => mediaListing()->id]);

    Livewire::actingAs($mine->creator)
        ->test('pages::seller.product-edit', ['product' => $mine])
        ->set("alts.{$foreign->id}", 'Not mine to describe.');

    expect($foreign->refresh()->alt_text)->toBeNull();
});

test('removing a screenshot takes the row and the bytes together', function () {
    $listing = mediaListing();

    Livewire::actingAs($listing->creator)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->call('removeScreenshot', 999999)
        ->assertNotFound();

    /** @var MarketplaceProductMedia $media */
    $media = MarketplaceProductMedia::factory()->create(['marketplace_product_id' => $listing->id]);
    Storage::disk($media->disk)->put($media->path, 'bytes');

    Livewire::actingAs($listing->creator)
        ->test('pages::seller.product-edit', ['product' => $listing])
        ->call('removeScreenshot', $media->id);

    expect(MarketplaceProductMedia::query()->whereKey($media->id)->exists())->toBeFalse()
        ->and(Storage::disk($media->disk)->exists($media->path))->toBeFalse();
});

test('one seller may not attach a file to another seller’s listing', function () {
    $listing = mediaListing();
    $intruder = User::factory()->seller()->create();

    expect(fn () => app(AttachProductScreenshot::class)->handle($listing, $intruder, [screenshotFile()]))
        ->toThrow(ValidationException::class);

    expect($listing->media()->count())->toBe(0);

    /** @var MarketplaceProductMedia $media */
    $media = MarketplaceProductMedia::factory()->create(['marketplace_product_id' => $listing->id]);

    expect(fn () => app(DetachProductScreenshot::class)->handle($intruder, $media))
        ->toThrow(InvalidArgumentException::class)
        ->and(MarketplaceProductMedia::query()->whereKey($media->id)->exists())->toBeTrue();
});

test('a published listing’s screenshot is readable by a reader who is signed in', function () {
    $listing = publishedMarketplaceProduct();
    /** @var MarketplaceProductMedia $media */
    $media = MarketplaceProductMedia::factory()->create(['marketplace_product_id' => $listing->id]);
    Storage::disk($media->disk)->put($media->path, 'picture bytes');

    $response = $this->get($media->url());

    $response->assertOk()
        ->assertHeader('Content-Type', $media->mime)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertStreamedContent('picture bytes');
});

test('a signed link to the wrong listing is not served', function () {
    $listing = publishedMarketplaceProduct();
    /** @var MarketplaceProductMedia $media */
    $media = MarketplaceProductMedia::factory()->create(['marketplace_product_id' => $listing->id]);
    Storage::disk($media->disk)->put($media->path, 'picture bytes');

    $other = publishedMarketplaceProduct();

    $url = str_replace(
        '/marketplace/products/'.$listing->id.'/media/',
        '/marketplace/products/'.$other->id.'/media/',
        $media->url(),
    );

    // Rewriting the listing in the path invalidates the signature before the
    // pairing is ever consulted, which is the cheaper of the two refusals.
    $this->get($url)->assertStatus(400);
});

test('a tampered signature is refused', function () {
    $listing = publishedMarketplaceProduct();
    /** @var MarketplaceProductMedia $media */
    $media = MarketplaceProductMedia::factory()->create(['marketplace_product_id' => $listing->id]);
    Storage::disk($media->disk)->put($media->path, 'picture bytes');

    $this->get(str_replace('signature=', 'signature=deadbeef', $media->url()))->assertStatus(400);
});

test('an expired link is refused', function () {
    $listing = publishedMarketplaceProduct();
    /** @var MarketplaceProductMedia $media */
    $media = MarketplaceProductMedia::factory()->create(['marketplace_product_id' => $listing->id]);
    Storage::disk($media->disk)->put($media->path, 'picture bytes');

    $url = $media->url();

    $this->travel(11)->minutes();

    $this->get($url)->assertStatus(400);
});

test('a draft listing’s screenshot answers 404 to everyone but its author', function () {
    $listing = mediaListing();
    /** @var MarketplaceProductMedia $media */
    $media = MarketplaceProductMedia::factory()->create(['marketplace_product_id' => $listing->id]);
    Storage::disk($media->disk)->put($media->path, 'picture bytes');

    $this->actingAs(User::factory()->create())->get($media->url())->assertNotFound();
    $this->get($media->url())->assertNotFound();
    $this->actingAs($listing->creator)->get($media->url())->assertOk();
});

test('a withdrawn listing stops serving its screenshots to strangers', function () {
    $listing = publishedMarketplaceProduct();
    /** @var MarketplaceProductMedia $media */
    $media = MarketplaceProductMedia::factory()->create(['marketplace_product_id' => $listing->id]);
    Storage::disk($media->disk)->put($media->path, 'picture bytes');

    $listing->forceFill(['status' => ProductStatus::Archived])->save();

    $this->get($media->url())->assertNotFound();
});

test('the storefront gallery shows what the seller attached', function () {
    $listing = publishedMarketplaceProduct();
    MarketplaceProductMedia::factory()->count(2)->create(['marketplace_product_id' => $listing->id]);

    $this->get(route('marketplace.show', $listing))
        ->assertOk()
        ->assertSee('listing-shot-', false)
        ->assertSee('marketplace/products/'.$listing->id.'/media/', false);
});

test('a listing with no screenshots falls back to the category tile on its card', function () {
    $bare = publishedMarketplaceProduct();
    $shotted = publishedMarketplaceProduct();
    MarketplaceProductMedia::factory()->create(['marketplace_product_id' => $shotted->id]);

    $this->get(route('marketplace.index'))
        ->assertOk()
        ->assertSee('marketplace/products/'.$shotted->id.'/media/', false)
        ->assertDontSee('marketplace/products/'.$bare->id.'/media/', false);
});
