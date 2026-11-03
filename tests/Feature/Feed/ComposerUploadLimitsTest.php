<?php

use App\Actions\Feed\ComposeFeedPost;
use App\Models\FeedAttachment;
use App\Models\User;
use App\Support\UploadLimits;
use Database\Seeders\FeedPostSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * The two limits the inline composer had lost.
 *
 * The composer exists twice: the inline one on `/feed` (the live write path)
 * and the modal one (complete, but mounted nowhere). The modal has always
 * enforced a total attachment cap and a minimum image size; the inline path
 * enforced neither, so the shipped composer was more permissive than the one
 * that was never wired up.
 *
 * Both are easy to miss because each looks correct in isolation — every batch
 * is legal, every image is a real image — and only the aggregate is wrong.
 */
beforeEach(function (): void {
    Storage::fake('feed_media');
    Storage::fake('local');

    $this->seed(FeedPostSeeder::class);
    $this->reader = User::factory()->create(['handle' => 'reader', 'name' => 'Reader One']);
});

/** A real 32×32 PNG — above the composer's 16px floor. */
function uploadableImage(string $name = 'shot.png'): UploadedFile
{
    requiresGd();

    $image = imagecreatetruecolor(32, 32);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));

    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    return UploadedFile::fake()->createWithContent($name, $png);
}

/** A 1×1 PNG: a valid image, and useless as post media. Needs no GD. */
function undersizedImage(): UploadedFile
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    );

    return UploadedFile::fake()->createWithContent('pixel.png', $png);
}

it('refuses a batch that would take the post past the attachment cap', function (): void {
    /* The cap belongs to the post, not to one turn of the file picker. Before
       the fix, two legal batches of two under a cap of three produced four
       images on one post — `max:` only ever saw the batch in hand.

       The staged rows are written by the factory rather than uploaded, so this
       asserts the guard itself and needs no image codec. The guard runs before
       validation, which is the point: nothing is decoded for a batch that is
       already too big. */
    config(['feed.composer.max_attachments' => 3]);

    actingAs($this->reader);

    $component = Livewire::test('pages::feed')->set('tempKey', 'cap-test');

    FeedAttachment::factory()->count(2)->staged('cap-test')->create([
        'user_id' => $this->reader->id,
    ]);

    $component
        ->set('uploads', [
            UploadedFile::fake()->create('c.png', 4, 'image/png'),
            UploadedFile::fake()->create('d.png', 4, 'image/png'),
        ])
        ->assertHasErrors('uploads');

    expect($component->instance()->stagedMedia)->toHaveCount(2);
});

it('still accepts a second batch that fits inside the cap', function (): void {
    /* The guard must not become a one-batch limit: two plus one is three, and
       three is allowed. This one needs real images, because the batch has to
       survive validation to be staged. */
    config(['feed.composer.max_attachments' => 3]);

    actingAs($this->reader);

    $component = Livewire::test('pages::feed')
        ->set('uploads', [uploadableImage('a.png'), uploadableImage('b.png')]);

    expect($component->instance()->stagedMedia)->toHaveCount(2);

    $component->set('uploads', [uploadableImage('c.png')])
        ->assertHasNoErrors();

    expect($component->instance()->stagedMedia)->toHaveCount(3);
});

it('refuses an image below the composer floor', function (): void {
    /* A 1×1 PNG is a valid image and a useless one. The modal composer has
       always refused it; the inline path accepted it and stored a thumbnail
       that renders as a smear. The error is keyed per file, which is how
       Laravel keys an `uploads.*` rule. */
    actingAs($this->reader);

    $component = Livewire::test('pages::feed')
        ->set('uploads', [undersizedImage()])
        ->assertHasErrors('uploads.0');

    expect($component->instance()->stagedMedia)->toHaveCount(0);
});

it('refuses a file that is not an image at all', function (): void {
    actingAs($this->reader);

    Livewire::test('pages::feed')
        ->set('uploads', [UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf')])
        ->assertHasErrors('uploads.0');
});

/**
 * The staged grid is ordered, so the ordinals must be distinct.
 *
 * `stagedMedia` reads `orderBy('sort_order')->orderBy('id')`, and the card for
 * a published post reads the same column. Every staged row used to be written
 * with `sort_order = 0` — the `$post === null` branch hardcoded a literal — so
 * the order a reader saw was whatever `id` happened to be, and the second tie
 * fallback did the disambiguating. It looked right in a one-image test and
 * wrong the moment a post carried two.
 *
 * These drive {@see AttachFeedMedia} rather than the composer, so the fixture
 * only has to be *a* PNG, not a 32-pixel one: the action decodes dimensions
 * best-effort and records null when it cannot, which keeps the ordering
 * assertion running on a PHP without GD.
 */
it('numbers one batch so the grid order is the picker order', function (): void {
    actingAs($this->reader);

    $stored = app(App\Actions\Feed\AttachFeedMedia::class)->handle(
        null,
        $this->reader,
        [undersizedImage('first.png'), undersizedImage('second.png'), undersizedImage('third.png')],
        'order-test',
    );

    expect($stored->pluck('sort_order')->all())->toBe([1, 2, 3]);
});

it('continues numbering across a second batch', function (): void {
    /* One file, then two more. Before the fix the second batch restarted at
       zero, so the grid interleaved by id and a post published in an order the
       author never chose. */
    actingAs($this->reader);

    $media = app(App\Actions\Feed\AttachFeedMedia::class);

    $media->handle(null, $this->reader, [undersizedImage('one.png')], 'order-test');
    $media->handle(null, $this->reader, [undersizedImage('two.png'), undersizedImage('three.png')], 'order-test');

    $ordinals = FeedAttachment::query()
        ->where('temp_key', 'order-test')
        ->orderBy('sort_order')
        ->pluck('sort_order')
        ->all();

    expect($ordinals)->toBe([1, 2, 3]);
});

it('carries the staged order onto the published post', function (): void {
    /* The claim renumbers into the post's own sequence, so a post whose media
       was staged in two turns still renders first-to-last. */
    actingAs($this->reader);

    $media = app(App\Actions\Feed\AttachFeedMedia::class);

    $media->handle(null, $this->reader, [undersizedImage('one.png'), undersizedImage('two.png')], 'order-test');

    $post = app(ComposeFeedPost::class)->handle(
        $this->reader,
        ['body' => 'Two images, in order.', 'type' => 'note', 'topic' => null],
        null,
        'order-test',
    );

    expect($post->attachments()->orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2]);
});

/**
 * An upload the hook is handed is consumed, whatever the outcome.
 *
 * `updatedUploads` is the only place the `uploads` property is emptied, and it
 * used to empty it on the success path only. Every refusal — the cap guard,
 * a failed `image` rule, an undersized file — returned with the array still
 * holding Livewire's `TemporaryUploadedFile` objects. Those are single-use:
 * the staging copy is moved onto the media disk on success and gone by the end
 * of the request either way, so a survivor is a live object pointing at a file
 * that is no longer there.
 *
 * The damage is not the leak itself, it is what the leak does to the *next*
 * attempt. `validate()` runs against the whole property, so the dead entries
 * were re-decided on every later pick: one undersized image drew its error
 * again and again, and each try appended another key instead of replacing the
 * last. A reader who picked a bad file once could not get a clean error list
 * back by picking a good one.
 *
 * These assert the property is *null* after the hook, not merely empty, because
 * `[]` and `[null]` both survived the old code and both re-ran the rules.
 */
it('drops a refused batch instead of holding it on the property', function (): void {
    actingAs($this->reader);

    $component = Livewire::test('pages::feed')
        ->set('uploads', [undersizedImage()])
        ->assertHasErrors('uploads.0');

    expect($component->instance()->uploads)->toBeNull();
});

it('does not re-report a refusal on the next pick', function (): void {
    /* The observable damage: before the fix the dead entry survived, so the
       second attempt answered for two files and keyed a second error. The
       assertion is on the error *keys*, which is the thing a reader sees. */
    actingAs($this->reader);

    config(['feed.composer.max_attachments' => 8]);

    $component = Livewire::test('pages::feed')
        ->set('uploads', [undersizedImage()])
        ->assertHasErrors('uploads.0');

    $after = $component->set('uploads', [undersizedImage()]);

    expect(array_keys($after->instance()->getErrorBag()->toArray()))->toBe(['uploads.0']);
});

it('drops an empty batch without leaving an array behind', function (): void {
    /* A cancelled picker sets the property to `[]`. The old hook returned at
       the top on an empty batch and never touched the property, so `[]` stayed
       — which is the shape that starts the accumulation. */
    actingAs($this->reader);

    $component = Livewire::test('pages::feed')->set('uploads', []);

    expect($component->instance()->uploads)->toBeNull();
});

it('drops a batch whose every entry filtered out', function (): void {
    actingAs($this->reader);

    $component = Livewire::test('pages::feed')->set('uploads', [null, null]);

    expect($component->instance()->uploads)->toBeNull();
});

it('drops the batch that tripped the cap guard', function (): void {
    /* The cap branch already nulled the property, so this one guards the
       recalculation rather than a bug: moving the assignment above the guard
       must not leave that branch unable to clear. */
    config(['feed.composer.max_attachments' => 1]);

    actingAs($this->reader);

    $component = Livewire::test('pages::feed');

    FeedAttachment::factory()->staged('cap-guard')->create(['user_id' => $this->reader->id]);

    $component->set('tempKey', 'cap-guard')
        ->set('uploads', [
            UploadedFile::fake()->create('a.png', 4, 'image/png'),
            UploadedFile::fake()->create('b.png', 4, 'image/png'),
        ])
        ->assertHasErrors('uploads');

    expect($component->instance()->uploads)->toBeNull();
});

/**
 * A refusal the reader can see.
 *
 * The panel has exactly one place to report an upload problem:
 * `data-test="composer-media-error"`, which was rendered by `@error('uploads')`
 * — the bare key. Every per-file rule fails as `uploads.0` instead, so the two
 * most likely refusals in the whole composer (an image under the 16px floor, a
 * file that is not an image) dropped the file and rendered **nothing**. The
 * file simply vanished with no message, which is the failure mode this project
 * treats as a bug rather than an absence of feedback.
 *
 * These assert on the rendered markup, not the error bag: the bag was always
 * correct, which is exactly why the gap survived. A server-side test that only
 * checked `assertHasErrors('uploads.0')` would have kept passing.
 */
it('renders a per-file refusal in the panel', function (): void {
    actingAs($this->reader);

    $html = Livewire::test('pages::feed')
        ->set('uploads', [undersizedImage()])
        ->assertHasErrors('uploads.0')
        ->html();

    expect(str_contains($html, 'data-test="composer-media-error"'))->toBeTrue(
        'a refused file must say so in the panel; the key is uploads.0, not uploads'
    );

    expect(str_contains($html, __('That image is too small or too large to attach.')))->toBeTrue();
});

it('renders the non-image refusal in the panel', function (): void {
    actingAs($this->reader);

    $html = Livewire::test('pages::feed')
        ->set('uploads', [UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf')])
        ->assertHasErrors('uploads.0')
        ->html();

    expect(str_contains($html, 'data-test="composer-media-error"'))->toBeTrue();
});

it('renders the cap refusal in the panel', function (): void {
    /* The other key: the hook raises this one itself, on the bare `uploads`
       key. Both shapes must reach the same slot, which is what the wildcard
       plus fallback in the template is for. */
    config(['feed.composer.max_attachments' => 1]);

    actingAs($this->reader);

    Livewire::test('pages::feed')
        ->set('uploads', [
            UploadedFile::fake()->create('a.png', 4, 'image/png'),
            UploadedFile::fake()->create('b.png', 4, 'image/png'),
        ])
        ->assertHasErrors('uploads')
        ->assertSee('composer-media-error', escape: false);
});

/**
 * Every refusal names its own reason, in words a reader can act on.
 *
 * The panel is the only place an upload problem can be reported, and it shows
 * exactly one line. What it showed for the two most likely refusals was
 * Laravel's generated text, which carries the *property path* the framework
 * keys the error by — `uploads.0` — because no custom message was declared for
 * those rules. `uploads.*.image` and `uploads.*.dimensions` were declared; the
 * `mimes` and `max` rules on the same field were not.
 *
 * Measured, before the fix, for a single non-image pick:
 *
 *     key=uploads.0  msg=Only image files can be attached to a post.
 *     key=uploads.0  msg=The uploads.0 field must be a file of type: jpg, …
 *     key=uploads.0  msg=That image is too small or too large to attach.
 *
 * Three messages for one file, on one key. The panel renders the first, so the
 * reader was told the right thing *and* the other two were queued behind it;
 * anything that reordered them — or reached for a rule whose message was never
 * declared — put a raw `uploads.0` in front of a reader. And for an oversize
 * file (the case PHP's own ceiling makes most likely) the first message *was*
 * the raw one.
 *
 * These assert on the rendered line, because the error bag was always
 * "correct" — keyed `uploads.0`, as Laravel keys every rule on the field. The
 * gap is in the wording, and only rendered markup shows it.
 */
it('never shows a reader the raw uploads.N property path', function (): void {
    actingAs($this->reader);

    $cases = [
        'a non-image' => UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'),
        'an undersized image' => undersizedImage(),
    ];

    foreach ($cases as $label => $file) {
        $html = Livewire::test('pages::feed')
            ->set('uploads', [$file])
            ->html();

        expect(str_contains($html, 'uploads.0'))->toBeFalse(
            "{$label} rendered the property path a reader cannot act on",
        );
    }
});

it('names the effective size limit when a file is too large', function (): void {
    /* The oversize path had no custom message at all, so Laravel's
       `validation.max.file` rendered with the property path in it — the one
       refusal a reader is most likely to meet and the one that said least.

       The number in the message is the *effective* limit, not the advertised
       one: `UploadLimits` clamps the config value down to what the running PHP
       will accept, because a file above PHP's ceiling never reaches this
       validator and a file below it must not be refused by words that quote a
       figure the process cannot honour. So the test derives the expected text
       from the same source the message does, rather than restating a constant
       that would silently stop matching. */
    actingAs($this->reader);

    // Comfortably above whatever this process accepts, so the rule fires.
    $bytes = UploadLimits::effectiveBytes() + (1024 * 1024);

    $html = Livewire::test('pages::feed')
        ->set('uploads', [UploadedFile::fake()->createWithContent('big.png', str_repeat("\x00", $bytes))])
        ->html();

    $expected = __('That image is larger than :size, the most a post can carry.', [
        'size' => UploadLimits::human(UploadLimits::effectiveKilobytes()),
    ]);

    expect(str_contains($html, 'uploads.0'))->toBeFalse('the property path must not reach a reader');
    expect(str_contains($html, $expected))->toBeTrue('the message must name the effective limit');
});

it('tells the reader the format, not the framework rule, for a wrong type', function (): void {
    actingAs($this->reader);

    $html = Livewire::test('pages::feed')
        ->set('uploads', [UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf')])
        ->html();

    // Declared once and shown once, however many rules the field trips.
    expect(substr_count($html, __('Only image files can be attached to a post.')))->toBe(1);
    expect(str_contains($html, 'must be a file of type'))->toBeFalse();
});

/**
 * The composer tells the browser what it will actually take.
 *
 * The picker narrowed by MIME (`accept`) but said nothing about size, and the
 * three limits in the stack disagreed by an order of magnitude: PHP stops at
 * `upload_max_filesize` (2 MB in this environment), the app advertises
 * `feed.composer.max_upload_kilobytes` (8 MB), and Livewire's gate allows
 * 20 MB. A file between 2 MB and 8 MB was offered by the picker, refused by
 * PHP before Livewire ever saw it, and reported to the reader as a transfer
 * failure naming the property path.
 *
 * A client-side ceiling cannot make a large file uploadable — only PHP's own
 * setting can, and that belongs in the deployment notes rather than in a
 * template. What it *can* do is stop the reader before the wait: the field
 * carries the limit, so the browser refuses what the server would, and the
 * wait is never wasted.
 */
it('carries the effective size limit on the file field', function (): void {
    /* `data-max-bytes` is what the browser check in `stage()` reads. It is the
       effective limit, not the advertised one, and this asserts that directly:
       a config value larger than PHP allows must not move the attribute, or
       the picker would promise a transfer the process will discard. */
    config(['feed.composer.max_upload_kilobytes' => 8192]);

    actingAs($this->reader);

    $html = Livewire::test('pages::feed')->html();

    expect($html)->toContain('data-composer-picker');
    expect($html)->toContain('data-max-bytes="'.UploadLimits::effectiveBytes().'"');
});

it('clamps the advertised cap down to what PHP will accept', function (): void {
    /* The config value is an intent; the process is the ceiling. A cap set
       above PHP's limit must not be quoted to a reader, because the file would
       be dropped before any validator ran. */
    config(['feed.composer.max_upload_kilobytes' => 999999]);

    actingAs($this->reader);

    $html = Livewire::test('pages::feed')
        ->set('uploads', [undersizedImage()])
        ->html();

    $expected = __('That image is larger than :size, the most a post can carry.', [
        'size' => UploadLimits::human(UploadLimits::effectiveKilobytes()),
    ]);

    // The oversize message names the clamped limit, never the config figure.
    expect(UploadLimits::effectiveKilobytes())->toBeLessThan(999999);

    $oversize = Livewire::test('pages::feed')
        ->set('uploads', [
            UploadedFile::fake()->createWithContent(
                'big.png',
                str_repeat("\x00", UploadLimits::effectiveBytes() + 1),
            ),
        ])
        ->html();

    expect(str_contains($oversize, $expected))->toBeTrue();
    expect(str_contains($html, '999999'))->toBeFalse('the raw config figure must not reach a reader');
});

it('reads a PHP shorthand size as bytes, not as a cast', function (): void {
    /* `ini_get` returns `php.ini` text — `2M`, not `2097152` — and `(int) '2M'`
       is `2`. A limit check written with a bare cast is therefore a two-byte
       limit, which is the kind of bug that looks like a working guard until
       someone attaches a real photograph.

       The parse is tested directly rather than through the live settings,
       because `upload_max_filesize` and `post_max_size` are `PHP_INI_PERDIR`:
       `ini_set` returns `false` for both (measured), so a test cannot move them
       to exercise the arithmetic. Reaching for the private method instead would
       leave the one place a two-byte limit comes from uncovered — which is why
       the parse is public. */
    expect(UploadLimits::parse('3M'))->toBe(3 * 1024 * 1024);
    expect(UploadLimits::parse('2M'))->toBe(2097152);
    expect(UploadLimits::parse('512K'))->toBe(512 * 1024);
    expect(UploadLimits::parse('1G'))->toBe(1024 * 1024 * 1024);

    // A bare number is bytes, matching PHP's own reading of the value.
    expect(UploadLimits::parse('4096'))->toBe(4096);

    // `-1` and an empty string mean unlimited, and must not become a `min()`
    // of negative one or zero.
    expect(UploadLimits::parse('-1'))->toBe(PHP_INT_MAX);
    expect(UploadLimits::parse(''))->toBe(PHP_INT_MAX);

    // Case and surrounding space are the `php.ini` author's, not ours.
    expect(UploadLimits::parse(' 8m '))->toBe(8 * 1024 * 1024);
});

it('derives the effective limit from the live PHP settings', function (): void {
    /* The value the app quotes has to come from the process, not from a
       constant. This pins the two relationships the rest of the fix relies on:
       byte magnitude is sane, and the kilobyte figure is the floored division
       the validator's `max:` rule is built from. */
    $bytes = UploadLimits::effectiveBytes();

    expect($bytes)->toBeGreaterThan(0);
    expect($bytes)->not->toBe(PHP_INT_MAX, 'this environment has a real ceiling');
    expect(UploadLimits::effectiveKilobytes())->toBe(intdiv($bytes, 1024));
    expect(UploadLimits::effectiveBytes())->toBeLessThanOrEqual(UploadLimits::parse((string) ini_get('upload_max_filesize')));
    expect(UploadLimits::effectiveBytes())->toBeLessThanOrEqual(UploadLimits::parse((string) ini_get('post_max_size')));
});

it('renders a human size for every shape of ceiling', function (): void {
    /* Whole megabytes read as megabytes, a fractional ceiling keeps its
       fraction (1.5 MB is a real stock-PHP value and rounding it to 1 MB would
       misstate what a reader may attach), and a sub-megabyte ceiling stays in
       kilobytes. */
    expect(UploadLimits::human(2048))->toBe('2 MB');
    expect(UploadLimits::human(1024))->toBe('1 MB');
    expect(UploadLimits::human(1536))->toBe('1.5 MB');
    expect(UploadLimits::human(512))->toBe('512 KB');
});
