<?php

use App\Actions\Feed\AttachFeedMedia;
use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\User;
use App\Support\FeedAttachmentTypes;
use App\Support\LogRow;
use App\Support\SvgSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * What the composer takes, and what it does with it on the way in.
 *
 * The disk used to accept four image types. It now accepts images *and*
 * documents, which turns this file into the record of a widening rather than a
 * restatement of a narrow rule. Three properties are what it exists to hold:
 *
 * 1. **The list and the door agree.** `FeedAttachmentTypes` is asked by the
 *    validator, the picker and the card. A format it names must be storable; a
 *    format it does not name must be refused. Neither half alone is enough —
 *    a list that over-promises gives a reader a failed upload, and one that
 *    under-offers hides a format behind a picker that cannot select it.
 * 2. **What the bytes are beats what the file says it is.** Every fixture here
 *    carries real bytes with a real magic number, because the interesting half
 *    of this path is that the type is *read back* rather than trusted. A
 *    mismatched name is not a curiosity: measured, a 1×1 PNG sent as `lie.pdf`
 *    cleared the type rule and the size rule before either was made
 *    content-aware.
 * 3. **SVG is rewritten, not trusted.** It is the one accepted image that can
 *    execute, and the bytes are served from this app's own origin. The tests
 *    below are the reason the sanitizer exists, and each one names the
 *    construct it is about.
 *
 * The fixtures are hand-built rather than generated with GD. That is not a
 * stylistic choice: this environment has no GD, so a fixture that needed it
 * would skip rather than assert, and the checks below are ones that have to
 * run.
 */
beforeEach(function (): void {
    Storage::fake('feed_media');
    Storage::fake('local');

    $this->reader = User::factory()->create([
        'handle' => 'media'.fake()->unique()->numerify('###'),
        'name' => 'Media Author',
    ]);
});

/** A real 32×32 PNG, hand-encoded so the fixture needs no GD. */
function realPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAAKklEQVR4nGPQqDhBU8Qw'
        .'asGoBaMWjFowasGoBaMWjFowasGoBaMWDBULAIuXoEzkdmPIAAAAAElFTkSuQmCC'
    );
}

/** A 1×1 PNG: valid, decodable, and below the composer's 16px floor. */
function tinyPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
}

/**
 * Some bytes that look like the format named, well enough to be typed by it.
 *
 * Every fixture is real enough for the checks it has to survive: an image has
 * to *decode*, because the read-back confirms an image claim by decoding it and
 * a stub with the right header would be refused — a fixture failure that would
 * read as a product one.
 */
function mediaBytesFor(string $extension): string
{
    return match ($extension) {
        'png' => realPng(),
        'jpg', 'jpeg' => realJpeg(),
        'gif' => "GIF89a\x28\x00\x28\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x28\x00\x28\x00\x00\x02\x1a\x04\x8f\xa9\xcb\xed\x0f\xa1\xb2\xd8\xdc\xfe\xf0\x98\x83\x14\x05\x00;",
        'webp' => base64_decode('UklGRjQAAABXRUJQVlA4ICgAAAAAAACdASogACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'),
        'bmp' => 'BM'.pack('V', 70)."\x00\x00\x00\x00".pack('V', 54).pack('V', 40).pack('V', 40).pack('V', 40)."\x01\x00\x18\x00".str_repeat("\x00", 24),
        'ico' => "\x00\x00\x01\x00\x01\x00"."\x20\x20\x00\x00\x01\x00\x20\x00".pack('V', 40).pack('V', 22).str_repeat("\x00", 40),
        'tif', 'tiff' => realTiff(),
        // AVIF is the one accepted image PHP cannot decode in this environment
        // (no GD, no Imagick), so its fixture is confirmed by signature rather
        // than by the decoder — which is exactly the path under test.
        'avif' => pack('N', 24).'ftypavif'.pack('N', 0).'avifmif1'.pack('N', 8).'mdat',
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#09f"/></svg>',
        'pdf' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
        'rtf' => "{\\rtf1\\ansi\\deff0 a document}\n",
        // The OOXML family as real archives. The detector reads the zip's table
        // of contents to tell a `.docx` from a `.zip`, so a fixture with a bare
        // PK header is typed `application/octet-stream` and refused — a fixture
        // failure that reads as a product one. `officeArchive()` builds the
        // whole structure, with the part that names the application inside it.
        'docx' => officeArchive('word/document.xml'),
        'xlsx' => officeArchive('xl/workbook.xml'),
        'pptx' => officeArchive('ppt/presentation.xml'),
        // ODF and EPUB are zips too, and the detector reports exactly that.
        'odt' => officeArchive('mimetype'),
        'ods' => officeArchive('mimetype'),
        'odp' => officeArchive('mimetype'),
        'epub' => officeArchive('META-INF/container.xml'),
        'zip' => officeArchive('some/plain/file.txt'),
        // Text and data.
        'txt', 'md' => "a plain text file with a newline\n",
        'csv' => "name,qty\nwidget,3\n",
        'log', 'ini' => "a line\na second line\n",
        'json' => '{"name":"widget","qty":3}',
        'xml' => '<?xml version="1.0"?><root><item qty="3"/></root>',
        'yml', 'yaml' => "name: widget\nqty: 3\n",
        'ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n",
        'vcf' => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Widget\r\nEND:VCARD\r\n",
        // Source code.
        'css' => "body { color: red; }\n",
        'js' => "export const qty = 3;\n",
        'php' => "<?php\n\nreturn 3;\n",
        'sh' => "#!/bin/sh\necho 3\n",
        'sql' => "SELECT 3;\n",
        // The legacy formats are OLE2 compound files, and each has to carry the
        // stream that names its application: the read-back opens the directory
        // and requires it, because the *mime* cannot tell a Word document from
        // any other compound file. See `oleCompoundFile()`.
        'doc' => oleCompoundFile(['WordDocument', '1Table', 'SummaryInformation']),
        'xls' => oleCompoundFile(['Workbook', 'SummaryInformation']),
        'ppt' => oleCompoundFile(['PowerPoint Document', 'Current User']),
        default => str_repeat('x', 64),
    };
}

/**
 * A structurally valid zip holding one named entry.
 *
 * The entry name is the whole point: the disk's detector opens the archive and
 * reads its table of contents, and the path of the first entry is what tells a
 * `.docx` from a `.xlsx` from a plain `.zip`. A fixture that only had the `PK`
 * signature would be typed as unknown bytes and the test would report a
 * product failure that is really a fixture one — which is exactly what
 * happened before this helper existed.
 *
 * Built by hand because `ZipArchive` is not guaranteed to be present, and a
 * fixture that skipped would leave the OOXML paths unasserted.
 */
function officeArchive(string $entry): string
{
    $data = 'the contents of the entry';
    $crc = crc32($data);

    $local = "PK\x03\x04".pack('v', 20).pack('v', 0).pack('v', 0).pack('v', 0).pack('v', 0)
        .pack('V', $crc).pack('V', strlen($data)).pack('V', strlen($data))
        .pack('v', strlen($entry)).pack('v', 0).$entry.$data;

    $central = "PK\x01\x02".pack('v', 20).pack('v', 20).pack('v', 0).pack('v', 0)
        .pack('v', 0).pack('v', 0).pack('V', $crc).pack('V', strlen($data)).pack('V', strlen($data))
        .pack('v', strlen($entry)).pack('v', 0).pack('v', 0).pack('v', 0).pack('v', 0).pack('V', 0)
        .pack('V', 0).$entry;

    $end = "PK\x05\x06".pack('v', 0).pack('v', 0).pack('v', 1).pack('v', 1)
        .pack('V', strlen($central)).pack('V', strlen($local)).pack('v', 0);

    return $local.$central.$end;
}

/**
 * A structurally valid OLE2 compound file naming the given streams.
 *
 * The stream names are the whole point, and a fixture that omitted them would
 * fail for the wrong reason: the read-back refuses an OLE2 container whose
 * directory does not name the application the extension promises, because that
 * check is the only thing separating a Word document from *any* compound file —
 * measured, the disk types them all identically.
 *
 * Laid out per the format: a 512-byte header, then data sector 0 holding the
 * FAT and data sector 1 holding the directory. `firstDir = 1` is the data-sector
 * index, which is what the spec's `(firstSector + 1) * sectorSize` addressing
 * expects — getting this wrong puts the directory past the end of the file and
 * every fixture is refused, which is exactly how a fixture bug reads as a
 * product one.
 *
 * @param  array<int, string>  $streams
 */
function oleCompoundFile(array $streams = []): string
{
    $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 16);
    $header .= pack('v', 0x003E).pack('v', 0x0003).pack('v', 0xFFFE).pack('v', 9).pack('v', 6);
    $header .= str_repeat("\x00", 6);
    $header .= pack('V', 1)   // number of directory sectors
        .pack('V', 1)         // number of FAT sectors
        .pack('V', 1)         // first directory sector (data-sector index)
        .pack('V', 0);
    $header .= pack('V', 4096).pack('V', 0xFFFFFFFE).pack('V', 0).pack('V', 0xFFFFFFFE).pack('V', 0);
    $header .= str_repeat("\x00", 109 * 4);

    $directory = '';

    // The root entry is always present; the streams follow it.
    foreach (array_merge(['Root Entry'], $streams) as $name) {
        $utf16 = mb_convert_encoding($name."\x00", 'UTF-16LE', 'UTF-8');

        $directory .= substr(str_pad($utf16, 64, "\x00"), 0, 64)
            .pack('v', strlen($utf16))   // name length, terminating NUL included
            .str_repeat("\x00", 62);     // type, colour, siblings, size, times
    }

    return str_pad($header, 512, "\x00")   // header sector
        .str_repeat("\x00", 512)            // data sector 0: FAT
        .str_pad($directory, 512, "\x00");  // data sector 1: directory
}

/** A minimal TIFF header claiming 32×32, hand-built so the fixture needs no GD. */
function realTiff(): string
{
    return 'II'.pack('v', 42).pack('V', 8)
        .pack('v', 2)
        .pack('v', 0x0100).pack('v', 3).pack('V', 1).pack('V', 32)
        .pack('v', 0x0101).pack('v', 3).pack('V', 1).pack('V', 32)
        .pack('V', 0);
}

/**
 * A real JPEG, hand-encoded so the fixture needs no GD.
 *
 * A JPEG the decoder will actually accept, because the read-back confirms an
 * image claim by decoding it — a stub with a JPEG header and no scan data is
 * refused, and the failure would look like a product bug rather than a fixture
 * one. This is a 32×32 solid-colour baseline image.
 */
function realJpeg(): string
{
    return base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
        .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAAgACABAREA/8QAHwAAAQUBAQEB'
        .'AQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1Fh'
        .'ByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZ'
        .'WmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXG'
        .'x8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/APn+iiigD//Z'
    );
}

/**
 * Stage one upload through the real component and report what happened.
 *
 * The bytes are named by the file's extension in the ordinary case, and can be
 * given explicitly when the whole point of the case is that the name and the
 * contents disagree.
 */
function stageFile(string $name, ?string $bytes = null): array
{
    $contents = $bytes ?? mediaBytesFor(strtolower(pathinfo($name, PATHINFO_EXTENSION)));

    $component = Livewire::test('pages::feed')
        ->set('uploads', [UploadedFile::fake()->createWithContent($name, $contents)]);

    return [
        'errors' => $component->errors()->toArray(),
        'row' => FeedAttachment::query()->latest('id')->first(),
        'html' => $component->html(),
    ];
}

it('stores every type the shared list names', function (): void {
    actingAs($this->reader);

    // Walked from the list rather than written out, so a format added to
    // `FeedAttachmentTypes` is covered by this test the day it is added. That
    // is the point of the shared list: the coverage follows the contract.
    foreach (FeedAttachmentTypes::EXTENSIONS as $extension) {
        $result = stageFile("sample.{$extension}");

        expect($result['errors'])->toBe(
            [],
            "[.{$extension}] was refused by the validator, but the shared list names it",
        );

        expect($result['row'])->not->toBeNull(
            "[.{$extension}] staged no row, so it was accepted and then discarded",
        );

        // The row's type is what the disk reported, and it is a type the list
        // accepts — the round trip closes.
        expect(FeedAttachmentTypes::accepts($result['row']->mime))->toBeTrue(
            "[.{$extension}] was stored as [{$result['row']->mime}], which the list does not accept",
        );

        // And the stored name still identifies the format. This is the half a
        // container type would otherwise lose: a `.docx` reads back as
        // `application/zip`, and a reconciliation that trusted the mime alone
        // would rename it to `.zip` — downloading correctly and opening in the
        // wrong application.
        //
        // **The property is asked through `extensionMatches()`, not through an
        // equality on `mimeForExtension()`.** That equality was the first
        // spelling of this check and it cannot hold for a container-backed
        // format, which is what the `.odt` case measured: the map records the
        // *application* mime (`application/vnd.oasis.opendocument.text`)
        // because that is the name the format has, while the bytes honestly
        // report the *container* (`application/zip`) because that is what they
        // are. Neither is wrong, and the equivalence between them is exactly
        // what `extensionMatches()` is — the same question the reconciliation
        // asks. Asserting the equality instead would fail the honest case and
        // pass only a map that lied about the container.
        //
        // Not an equality on the extension either: `jpeg`/`jpg` and
        // `tiff`/`tif` are the same type under two spellings, and normalising
        // to one canonical form is right. What must hold is that the suffix
        // *names* the type — the property the reconciliation exists to
        // guarantee, and the one a container type would break.
        $storedExtension = strtolower(pathinfo($result['row']->path, PATHINFO_EXTENSION));

        expect(FeedAttachmentTypes::extensionMatches($storedExtension, $result['row']->mime))->toBeTrue(
            "[.{$extension}] was stored as [{$result['row']->mime}] under a .{$storedExtension} name, "
            .'so a download would be handed to the wrong application',
        );

        expect(Storage::disk('feed_media')->exists($result['row']->path))->toBeTrue(
            "[.{$extension}] left no bytes on the disk",
        );

        // One pick per component instance; the temp key differs each time, so
        // the staged list would not accumulate — but the rows would, and the
        // next iteration's `latest('id')` would read the wrong one.
        FeedAttachment::query()->delete();
        Storage::disk('feed_media')->delete(Storage::disk('feed_media')->allFiles());
    }
});

it('refuses a type the shared list does not name', function (): void {
    actingAs($this->reader);

    /*
     * The list is wide now — text, source code and office formats are all in
     * it — so the refused set is chosen for what it *is* rather than for being
     * absent by accident. Two things it has to keep covering:
     *
     *  - **Executables.** The bytes are served back through a signed route, and
     *    an ELF or PE binary is the one thing a reader must never be handed as
     *    an attachment on this origin.
     *  - **Types the picker offers nothing for.** `.html` is the subtlest one:
     *    it is text, it is a document, and a browser will *run* it. Serving it
     *    with an `attachment` disposition makes it inert, so it is not the
     *    same class of risk as a binary — but it is still not a format this
     *    app vouches for, and the list is an allow-list, so it stays out.
     *
     * Each fixture carries real bytes with the magic number its format is known
     * by, so a refusal cannot be an accident of an empty fixture. (`run.sh`,
     * `data.json`, `app.js` used to be here and are now *accepted* — they moved
     * to the storing test with the rest of the list.)
     */
    $refused = [
        'setup.exe' => 'MZ'.str_repeat("\x00", 60),
        'lib.so' => str_repeat("\x7fELF", 16),
        'page.html' => '<!DOCTYPE html><html><body>hi</body></html>',
        'module.py' => "print('hi')\n",
        'notes.diff' => "--- a/x\n+++ b/x\n",
    ];

    foreach ($refused as $name => $bytes) {

        $component = Livewire::test('pages::feed')
            ->set('uploads', [UploadedFile::fake()->createWithContent($name, $bytes)]);

        $errors = $component->errors()->toArray();

        expect($errors)->not->toBe([], "[{$name}] was accepted, but the list does not name it");
        expect(FeedAttachment::query()->count())->toBe(0, "[{$name}] left a row after being refused");
        expect(Storage::disk('feed_media')->allFiles())->toBe([], "[{$name}] left bytes behind");

        // The refusal is a sentence, not a property path. The error array is
        // keyed `uploads.0` because that is how every rule on a wildcard field
        // is keyed — the *message* is what must not name it, which is what the
        // rendered-line tests in the limits file check. Here the check is on
        // the message text itself, one step earlier.
        expect(implode(' ', $errors['uploads.0'] ?? []))->not->toContain('uploads.0');
        expect(implode(' ', $errors['uploads.0'] ?? []))->not->toContain('must be a file of type');
    }
});

it('refuses an OLE2 container that does not name the legacy document its suffix claims', function (): void {
    actingAs($this->reader);

    /*
     * **Why this test exists, and what it is not.**
     *
     * Accepting the legacy Office formats means accepting
     * `application/x-ole-storage`, and that one mime is a *blank cheque*: it is
     * the same container a Word document, a spreadsheet, and macro malware all
     * report. Measured three ways — a bare header, a structurally complete
     * container, and real per-application stream sets — the detector never
     * says `application/msword`; every one of them is `x-ole-storage`. So the
     * mime cannot be the thing that vouches for the document. The directory
     * can, which is what {@see FeedAttachmentTypes::legacyOfficeKind()} reads.
     *
     * Driven through `handle()` because that is the layer that owns the
     * question. A composer-level test would pass for the wrong reason here:
     * the composer's rules run first and could refuse the same bytes for a
     * reason of their own, so the two outcomes are indistinguishable from
     * outside. The store path is where the container check lives, so it is the
     * store path that is asked.
     */
    $cases = [
        // A container naming a stream that is not the one a `.doc` promises: a
        // spreadsheet renamed to look like a Word document.
        'spreadsheet.doc' => oleCompoundFile(['Workbook', 'SummaryInformation']),
        // A deck renamed to a workbook.
        'deck.xls' => oleCompoundFile(['PowerPoint Document', 'Current User']),
        // A Word document renamed to a deck.
        'letter.ppt' => oleCompoundFile(['WordDocument', '1Table']),
        // A macro-only container: structurally valid OLE2, no document stream
        // at all. This is the case a mime-only check would have admitted.
        'macro.doc' => oleCompoundFile(['VBA', 'PROJECT', 'PROJECTwm']),
        // The bare header with no directory worth reading.
        'truncated.doc' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 500),
        // Not an OLE2 file at all, wearing a legacy suffix.
        'notes.xls' => "just plain text\n",
    ];

    foreach ($cases as $name => $bytes) {
        $tempKey = 'ole-'.str_replace(['.', '/'], '-', $name);

        $stored = refuseThroughAction($name, $bytes, $tempKey);

        expect($stored)->toHaveCount(0, "[{$name}] was stored, but its directory does not name the document its suffix claims");
        expect(Storage::disk('feed_media')->allFiles())->toBe([], "[{$name}] left bytes behind after being refused");
    }

    expect(FeedAttachment::query()->count())->toBe(0);
});

it('keeps a legacy document whose directory does name it', function (): void {
    actingAs($this->reader);

    /*
     * The other half, and the one that stops the guard above from being a wall.
     * A refusal test alone would pass against an implementation that refused
     * every OLE2 container — which would mean the legacy formats were listed,
     * offered by the picker, and impossible to store. Each real document is
     * asserted, so the guard is proven to *discriminate* rather than to block.
     */
    foreach (['doc' => 'WordDocument', 'xls' => 'Workbook', 'ppt' => 'PowerPoint Document'] as $extension => $stream) {
        $result = stageFile("real.{$extension}");

        expect($result['errors'])->toBe([], "[.{$extension}] was refused, but its directory names [{$stream}]");
        expect($result['row'])->not->toBeNull("[.{$extension}] staged no row");
        expect($result['row']->mime)->toBe('application/x-ole-storage');
        expect($result['row']->path)->toEndWith(".{$extension}");

        FeedAttachment::query()->delete();
        Storage::disk('feed_media')->delete(Storage::disk('feed_media')->allFiles());
    }
});

it('keeps the suffix a calendar upload chose rather than renaming it', function (): void {
    actingAs($this->reader);

    /*
     * Measured: an uploaded `sample.ics` was stored as `…vcs`. PHP's extension
     * guesser renames the format (`ics` → `vcs`), and the disk reports
     * `text/calendar` for the file while the type map named `text/x-vcalendar`
     * — so `extensionMatches()` said "no", and `reconcileExtension()` moved the
     * file to the one spelling that *did* match. The reader's calendar was
     * stored under a name they never chose, from a format that was otherwise
     * accepted and offered.
     *
     * The invariant is the one the whole reconciliation exists for: a suffix
     * that names the type the bytes are must be *kept*. Asserted for both
     * spellings so a fix for one cannot break the other.
     */
    foreach (['meeting.ics', 'legacy.vcs'] as $name) {
        $result = stageFile($name);

        expect($result['errors'])->toBe([], "[{$name}] was refused");
        expect($result['row'])->not->toBeNull("[{$name}] staged no row");

        $stored = strtolower(pathinfo($result['row']->path, PATHINFO_EXTENSION));

        expect(FeedAttachmentTypes::extensionMatches($stored, $result['row']->mime))->toBeTrue(
            "[{$name}] was stored as [{$result['row']->mime}] under a .{$stored} name",
        );

        // And the *author's* extension is the one kept: the reconciliation had
        // no reason to move it, because the mime the disk reported for it is
        // the one the map now names.
        expect($stored)->toBe(pathinfo($name, PATHINFO_EXTENSION));

        FeedAttachment::query()->delete();
        Storage::disk('feed_media')->delete(Storage::disk('feed_media')->allFiles());
    }
});

it('types a file by its bytes when the name disagrees', function (): void {
    actingAs($this->reader);

    /*
     * The defect this pins, measured: a 1×1 PNG named `lie.pdf` passed the type
     * rule (which matches on the extension) and the dimension rule (which read
     * `getMimeType()`, guessed from the same name), so a file the composer
     * refuses as `pixel.png` was accepted as `pixel.pdf`. A rename must not be
     * able to lift a rule.
     */
    $result = stageFile('pixel.pdf', tinyPng());

    expect($result['errors'])->not->toBe(
        [],
        'a 1×1 PNG renamed .pdf was accepted; the size rule was bypassed by the name',
    );
    expect($result['row'])->toBeNull();

    // And a genuine image that *renames* cleanly is stored under the suffix its
    // contents imply, not the one it claimed. A download named `.pdf` holding
    // PNG bytes is handed to a PDF reader and fails to open.
    $good = stageFile('photo.pdf', realPng());

    expect($good['errors'])->toBe([]);
    expect($good['row'])->not->toBeNull();
    expect($good['row']->mime)->toBe('image/png');
    expect($good['row']->path)->toEndWith('.png');

    // No file is left under the old name — the move, not a copy.
    expect(Storage::disk('feed_media')->allFiles())->toHaveCount(1);
});

it('refuses a file whose bytes are not the image its name claims', function (): void {
    actingAs($this->reader);

    /*
     * The disk reports a mime from the *path*, not from the contents — measured:
     * plain text written to a `.png` path reads back as `image/png`. So a claim
     * of "image" has to be confirmed against the bytes, or a text file would be
     * stored as an image and painted into an `<img>` that can never render.
     *
     * **Driven through the action rather than the composer, and that is the
     * point of the test.** Measured by mutation: the composer never lets this
     * file near the read-back, because `FeedImageDimensions` decodes the bytes
     * first and refuses them there. A composer-level test therefore passed with
     * the read-back check removed — it was asserting the *rule's* behaviour and
     * wearing this check's name. `handle()` is the layer that owns the
     * question, so it is the layer asked.
     */
    $stored = refuseThroughAction('note.png', "this is not a png at all\n", 'bytes-test');

    expect($stored)->toHaveCount(0, 'a text file named .png was stored as an image');
    expect(FeedAttachment::query()->count())->toBe(0);
    expect(Storage::disk('feed_media')->allFiles())->toBe([]);
});

it('refuses a file whose bytes are not the avif its name claims', function (): void {
    actingAs($this->reader);

    /*
     * The same guard, on the one type the decode check cannot cover. AVIF is
     * confirmed by signature instead — so this is the test that a signature
     * check exists at all, and it is the half a "does it decode" implementation
     * of the guard would leave open: a text file named `.avif` decodes to
     * `false`, which under the decodable branch would read as "not an image"
     * rather than as "not an avif".
     */
    $stored = refuseThroughAction('heic.avif', "this is not an avif\n", 'avif-bytes-test');

    expect($stored)->toHaveCount(0, 'a text file named .avif was stored as an image');
    expect(Storage::disk('feed_media')->allFiles())->toBe([]);
});

it('tells the reader when an image they chose is not one', function (): void {
    actingAs($this->reader);

    /*
     * The other half of the read-back guard, and the one the suite was missing.
     *
     * A file the read-back refuses is a file the *app* says no to. The reader
     * picked a `.png` and the app turned it away at the disk, which is a fact
     * about storage rather than about their file. `FeedImageDimensions` refuses
     * it earlier and says so in the reader's terms — "that image is too small
     * or too large" is the honest sentence for bytes that decode to nothing
     * while claiming to be a picture.
     *
     * **This test exists because a mutation survived without it.** Deleting the
     * rule's claim gate left the whole 1820-test suite green, and the reader
     * lost more than a message: measured, the file was quietly stored as a
     * `.txt` row with `errors: []`, so the composer showed a staged attachment
     * that was not the picture they chose and never said a word about it. A
     * silent substitution is worse than a refusal, and nothing was asserting
     * that the refusal happens at all.
     */
    $component = Livewire::test('pages::feed')
        ->set('uploads', [UploadedFile::fake()->createWithContent('holiday.png', "this is not a png at all\n")]);

    // The reader is told, in words about their file rather than about storage.
    expect($component->errors()->toArray())->not->toBe(
        [],
        'a .png that is not an image was accepted silently',
    );
    expect(implode(' ', $component->errors()->get('uploads.0')))
        ->toContain('too small or too large');

    // And nothing was staged, so the composer cannot show a file the app
    // quietly re-typed as text.
    expect($component->instance()->stagedMedia)->toHaveCount(0);
    expect(FeedAttachment::query()->count())->toBe(0);
});

/**
 * Push one file through the store path and assert it was turned away.
 *
 * The action refuses by *throwing* a `ValidationException` rather than by
 * returning an empty set — that is how a Livewire upload failure reaches the
 * panel — so a test that only inspects the return value would report "stored
 * nothing" for a refusal and for a silent no-op alike. This asks for the
 * refusal explicitly and hands back the (empty) collection so the caller can
 * keep asserting on the disk.
 *
 * The temp key is per-call so two of these in one test cannot adopt each
 * other's staged rows.
 */
function refuseThroughAction(string $name, string $bytes, string $tempKey): Collection
{
    try {
        app(AttachFeedMedia::class)->handle(
            null,
            test()->reader,
            [UploadedFile::fake()->createWithContent($name, $bytes)],
            $tempKey,
        );
    } catch (ValidationException $exception) {
        return collect();
    }

    return collect([['accepted']]);
}

it('strips the executable constructs out of an SVG before storing it', function (): void {
    actingAs($this->reader);

    /*
     * An SVG is an image and a program at once. Because the bytes are served
     * back to other accounts from this app's own origin, an unsanitised one is
     * stored XSS with a file picker for an entry point. Each case below is a
     * distinct route into the origin, and each is asserted separately so a
     * sanitizer that closed one and missed another would be caught by the
     * test named for the one it missed.
     */
    $hostile = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="40" height="40" onload="alert(1)">
          <script>alert('script tag')</script>
          <rect width="40" height="40" fill="#09f" onclick="alert('handler')"/>
          <a xlink:href="javascript:alert('xlink')"><text x="2" y="20" font-size="12">link</text></a>
          <image href="https://evil.example/pixel.png" width="10" height="10"/>
          <foreignObject width="10" height="10"><body xmlns="http://www.w3.org/1999/xhtml">hi</body></foreignObject>
          <!-- a comment that must not survive -->
          <?php echo 'a processing instruction'; ?>
        </svg>
        SVG;

    $component = Livewire::test('pages::feed')
        ->set('uploads', [UploadedFile::fake()->createWithContent('art.svg', $hostile)]);

    expect($component->errors()->toArray())->toBe([]);

    $row = FeedAttachment::query()->firstOrFail();
    $stored = (string) Storage::disk('feed_media')->get($row->path);

    expect($row->mime)->toBe('image/svg+xml');

    // Each route, named. `onload` is the attribute that makes an SVG a script
    // the moment it is rendered; `<script>` is the obvious one; the `on*`
    // handler on a shape is the one a deny-list would forget; `javascript:`
    // under `xlink:href` is the one a name check alone would miss, because
    // `xlink:href` and `href` are the same attribute to a browser and
    // different strings to `in_array`.
    expect($stored)->not->toContain('<script');
    expect($stored)->not->toContain('alert');
    expect($stored)->not->toContain('onload');
    expect($stored)->not->toContain('onclick');
    expect($stored)->not->toContain('javascript:');
    expect($stored)->not->toContain('evil.example');
    expect($stored)->not->toContain('<image');
    expect($stored)->not->toContain('foreignObject');
    expect($stored)->not->toContain('<!--');

    // And the picture itself survives. A sanitizer that dropped everything
    // would pass every assertion above and destroy the file.
    expect($stored)->toContain('<svg');
    expect($stored)->toContain('<rect');
    expect($stored)->toContain('#09f');
});

it('refuses an SVG it cannot parse rather than storing it unread', function (): void {
    actingAs($this->reader);

    // The one outcome worth failing an upload to avoid: bytes this class could
    // not make safe, stored anyway. A document that claims to be SVG by name
    // and does not parse is exactly that.
    $component = Livewire::test('pages::feed')
        ->set('uploads', [UploadedFile::fake()->createWithContent('broken.svg', '<svg><unclosed>')]);

    expect($component->errors()->toArray())->not->toBe([]);
    expect(FeedAttachment::query()->count())->toBe(0);
    expect(Storage::disk('feed_media')->allFiles())->toBe([]);
});

it('refuses an XML document that is not an svg root', function (): void {
    actingAs($this->reader);

    /*
     * `clean()` returns null when the document element is not `<svg>`. Without
     * that check a well-formed XML document of any kind would be stored and
     * then served as `image/svg+xml` — the parser is happy, the type is a lie,
     * and the route has promised the browser an image it is not.
     */
    $component = Livewire::test('pages::feed')
        ->set('uploads', [UploadedFile::fake()->createWithContent('payload.svg', '<?xml version="1.0"?><html><body>x</body></html>')]);

    expect($component->errors()->toArray())->not->toBe([]);
    expect(FeedAttachment::query()->count())->toBe(0);
});

it('serves documents as downloads and images inline', function (): void {
    actingAs($this->reader);

    /*
     * The disposition is the security-relevant half of this change. An image
     * painted in the page is an `<img>`; a PDF rendering in the app's own
     * origin is a document running inside it. The route is the one place the
     * private bytes surface, so the rule is stated there and asserted here.
     */
    $image = stageFile('shot.png');
    expect($image['row'])->not->toBeNull();

    $imageResponse = $this->get($image['row']->url());
    $imageResponse->assertOk();
    expect($imageResponse->headers->get('Content-Disposition'))->toStartWith('inline');

    FeedAttachment::query()->delete();
    Storage::disk('feed_media')->delete(Storage::disk('feed_media')->allFiles());

    $document = stageFile('report.pdf');
    expect($document['row'])->not->toBeNull();

    $documentResponse = $this->get($document['row']->url());
    $documentResponse->assertOk();
    expect($documentResponse->headers->get('Content-Disposition'))->toStartWith('attachment');
    expect($documentResponse->headers->get('Content-Type'))->toBe('application/pdf');

    // The two headers that make a same-origin file inert are on both, because
    // they guard the response rather than the kind of file in it.
    foreach ([$imageResponse, $documentResponse] as $response) {
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
    }
});

it('names a download after the row rather than after the upload', function (): void {
    actingAs($this->reader);

    $result = stageFile('My Report (final) v2.pdf');
    $row = $result['row'];

    expect($row)->not->toBeNull();

    // The uploaded name is attacker-chosen text. The stored path is a UUID, so
    // the original never reaches the filesystem; the chip's name is rebuilt
    // from the row, and the disposition filename comes from the same source.
    expect($row->displayName())->not->toContain('My Report');
    expect($row->displayName())->not->toContain('(');
    expect($row->displayName())->toMatch('/^[a-z0-9-]+-\d+\.pdf$/');

    $response = $this->get($row->url());
    expect($response->headers->get('Content-Disposition'))->toContain($row->displayName());
});

it('renders a document as a chip and an image as a thumbnail', function (): void {
    actingAs($this->reader);

    $author = $this->reader;
    $post = FeedPost::factory()->authoredBy($author)->create(['body' => 'Shipped with an attachment.']);

    $document = app(AttachFeedMedia::class)
        ->handle($post, $author, [UploadedFile::fake()->createWithContent('report.pdf', mediaBytesFor('pdf'))])
        ->firstOrFail();

    $html = Livewire::test('pages::feed')->html();

    // The chip carries the name and the size, and links to the download.
    expect($html)->toContain('data-test="commit-media-file"');
    expect($html)->toContain($document->displayName());
    expect($html)->toContain($document->humanSize());

    // A document is never an `<img>` on the card, whatever else is there.
    expect($html)->not->toContain('src="'.$document->url().'"');

    // The row payload is where the decision is made, so the card is not
    // re-deriving it — one flag, taken from the mime the disk reported. The
    // relation has to be loaded for `attachments()` to read it, which is what
    // the row builder does on the real path.
    $post->load('attachments');

    $row = LogRow::make($post);
    $attachments = $row['attachments'] ?? [];

    expect($attachments)->not->toBeEmpty();
    expect($attachments[0]['image'])->toBeFalse();
    expect($attachments[0]['name'])->toBe($document->displayName());
    expect($attachments[0]['kind'])->toBe('PDF');
});

it('falls back to a kind-aware alt when an image has none', function (): void {
    actingAs($this->reader);

    // The old fallback said "Image attached to a community post" for every
    // file. Said of a spreadsheet, a screen reader describes the wrong thing
    // entirely — and a blank alt strands the reader with no description at all,
    // so the branch cannot simply be removed.
    $image = new FeedAttachment(['mime' => 'image/png', 'path' => 'x/y.png', 'size' => 10]);
    $document = new FeedAttachment(['mime' => 'application/pdf', 'path' => 'x/y.pdf', 'size' => 10, 'id' => 1]);

    expect($image->alt())->toBe('Image attached to a community post');
    expect($document->alt())->toBe('File attached to a community post');
});

it('keeps a name the bytes support even when a twin spelling is canonical', function (): void {
    // Two spellings of one type, and only one of them can win the inverse
    // lookup: `extensionFor('image/jpeg')` answers `jpg`, so `jpeg` matches
    // *nothing* by inversion. It matches by the direct map instead, and that
    // branch was unguarded — measured by mutation, deleting it left the whole
    // suite green while `jpeg` and `tiff` silently stopped being reconcilable.
    //
    // The consequence of losing it is not cosmetic. `reconcileExtension()` asks
    // this question to decide whether a suffix fits the bytes; answering "no"
    // for a `jpeg` sends the file through the fallback, which strips the suffix
    // entirely — so an image uploads correctly, downloads as a file with no
    // extension, and opens in nothing.
    expect(FeedAttachmentTypes::extensionMatches('jpeg', 'image/jpeg'))->toBeTrue();
    expect(FeedAttachmentTypes::extensionMatches('tiff', 'image/tiff'))->toBeTrue();

    // And the canonical twins still match, through the inverse lookup.
    expect(FeedAttachmentTypes::extensionMatches('jpg', 'image/jpeg'))->toBeTrue();
    expect(FeedAttachmentTypes::extensionMatches('tif', 'image/tiff'))->toBeTrue();

    // A suffix whose type is genuinely different is still refused — the exact
    // case a looser implementation would let through.
    expect(FeedAttachmentTypes::extensionMatches('jpeg', 'image/png'))->toBeFalse();
    expect(FeedAttachmentTypes::extensionMatches('pdf', 'image/png'))->toBeFalse();
});

it('keeps the two halves of the list disjoint and complete', function (): void {
    // A mime in both halves would make `isImage()` and `accepts()` answer the
    // same question two ways; a mime in neither would be a type the validator
    // takes and the card cannot classify.
    expect(array_intersect(FeedAttachmentTypes::IMAGES, FeedAttachmentTypes::DOCUMENTS))->toBe([]);
    expect(FeedAttachmentTypes::all())->toHaveCount(
        count(FeedAttachmentTypes::IMAGES) + count(FeedAttachmentTypes::DOCUMENTS),
    );

    // Every extension maps to a mime the list actually names. A typo in the map
    // would make `extensionMatches()` answer "no" for every honest file, which
    // is the failure that would silently strip every suffix.
    foreach (FeedAttachmentTypes::extensionMap() as $extension => $mime) {
        expect(in_array($extension, FeedAttachmentTypes::EXTENSIONS, true))->toBeTrue(
            "[{$extension}] is in the mime map but not in the extension list",
        );
        expect(FeedAttachmentTypes::accepts($mime))->toBeTrue(
            "[{$extension}] maps to [{$mime}], which the list does not accept",
        );
    }

    // And the reverse: every accepted extension is nameable.
    foreach (FeedAttachmentTypes::EXTENSIONS as $extension) {
        expect(FeedAttachmentTypes::mimeForExtension($extension))->not->toBeNull(
            "[{$extension}] is accepted but has no mime, so no file could ever be named with it",
        );
    }
});

it('has a sanitizer that refuses what it cannot make safe', function (): void {
    // The component tests above exercise the sanitizer through the upload path.
    // These are the unit-level properties of it, where a failure is easier to
    // read than a stored-file diff.
    expect(SvgSanitizer::clean('not xml at all'))->toBeNull();
    expect(SvgSanitizer::clean(''))->toBeNull();
    expect(SvgSanitizer::clean('<html><body>x</body></html>'))->toBeNull();
    expect(SvgSanitizer::clean('<svg><rect/></svg>'))->not->toBeNull();

    // A DOCTYPE with an external entity is a network fetch on a shared host.
    // Nothing in the parser is allowed to reach out.
    $xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY e SYSTEM "file:///etc/passwd">]>'
        .'<svg xmlns="http://www.w3.org/2000/svg"><text>&e;</text></svg>';

    $cleaned = SvgSanitizer::clean($xxe);
    expect($cleaned)->not->toBeNull();
    expect($cleaned)->not->toContain('SYSTEM');
    expect($cleaned)->not->toContain('/etc/passwd');

    // The sniff that decides whether to sanitize at all.
    expect(SvgSanitizer::looksLikeSvg('<svg xmlns="http://www.w3.org/2000/svg"></svg>'))->toBeTrue();
    expect(SvgSanitizer::looksLikeSvg("  \n  <SVG>"))->toBeTrue();
    expect(SvgSanitizer::looksLikeSvg('plain text'))->toBeFalse();
    expect(SvgSanitizer::looksLikeSvg('<html><body>no svg here</body></html>'))->toBeFalse();

    // Namespace declarations are stripped because a `xmlns` on the root is the
    // one thing the allow-list would otherwise have to special-case; the
    // document renders identically without it.
    expect(SvgSanitizer::stripNamespaceDeclarations('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="4"/>'))
        ->toBe('<svg width="4"/>');
});
