<?php

namespace App\Support;

/**
 * The file types a post may carry, and what the app knows about each.
 *
 * This is the single source of truth for the compositor's accepted formats. It
 * exists because that answer is asked in four places that must never disagree:
 *
 *  - the validator's `mimes:` rule, which decides what is stored;
 *  - the picker's `accept` attribute, which decides what a reader is offered;
 *  - the label of an attach control, which promises the reader what will work;
 *  - the card, which decides whether a file is drawn as a picture or as a
 *    download chip.
 *
 * When those lived apart, a mismatch was a silent bug: the picker invited a
 * type the validator refused, or the disk took a type the picker never offered.
 * A reader paid for it with a failed upload after the wait. Keeping the list in
 * one class means an added format is reachable from the UI the moment it is
 * accepted, and no sooner.
 *
 * **Two kinds, deliberately separated.** Images are drawn inline; everything
 * else is a download. The distinction is not about tidiness — an image is
 * decoded by the browser in the page, and anything else served inline is a
 * document running on the app's origin. {@see isImage()} is what the card and
 * the controller both ask before deciding, so the two can never disagree about
 * which files are safe to paint.
 */
final class FeedAttachmentTypes
{
    /**
     * Image mimes the composer accepts and decodes.
     *
     * These are the types a browser can paint inside an `<img>` without
     * executing anything, plus SVG — which *can* execute, and so is sanitized
     * on store rather than trusted. See {@see SvgSanitizer}.
     *
     * `jpg`/`jpeg` are listed under both spellings because Laravel's `mimes:`
     * rule matches on extension and a file genuinely carries either one; the
     * read-back mime normalises both to `image/jpeg`.
     */
    public const IMAGES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
        'image/bmp',
        // The ico mime the *disk* reports, which is what the read-back compares
        // against. `image/x-icon` is the older spelling some libraries use; it
        // was measured to be the wrong one here — an uploaded `.ico` reads back
        // as `image/vnd.microsoft.icon`, so listing the other spelling refused
        // every icon at read-back while the picker went on offering them.
        'image/vnd.microsoft.icon',
        'image/tiff',
        'image/svg+xml',
    ];

    /**
     * Document mimes the composer accepts, drawn as download chips.
     *
     * An allow-list rather than a deny-list, on purpose. The bytes are served
     * back to other accounts through a signed route, so the question is not
     * "what is dangerous" but "what do we vouch for". A short list of office
     * and plain-text formats is a claim this app can keep; an open door is not.
     *
     * **Every mime here is one the read-back can actually produce**, which is
     * what makes the list a promise rather than an aspiration. That bar was
     * measured rather than assumed, and it caught an entry that looked obvious
     * and was not: an uploaded `.ico` reads back as `image/vnd.microsoft.icon`,
     * not as the `image/x-icon` spelling some libraries use. The image list
     * carries the spelling this disk really reports.
     *
     * The OOXML types *are* producible, but only from bytes whose internal
     * structure says so — the detector opens the archive and looks for
     * `word/document.xml`. A `.docx` that is a zip with no Word parts inside
     * reads back as `application/zip`, which is why that mime is here too.
     *
     * **The legacy binary Office formats read back only as their *container*.**
     * `.doc`, `.xls` and `.ppt` are OLE2 compound files and the detector stops
     * at `application/x-ole-storage` — measured twice, once with a hand-built
     * structure and once with real stream names in the directory. It never
     * reports `application/msword`, so listing that mime would refuse every
     * real file. `application/x-ole-storage` *is* here, which accepts the
     * container; the thing that actually vouches for the document inside it is
     * {@see legacyOfficeKind()}, called from the read-back, which opens the
     * OLE2 directory and requires the stream that names Word, Excel or
     * PowerPoint. A bare container with none of those streams is refused.
     */
    public const DOCUMENTS = [
        'application/pdf',
        'application/rtf',
        'text/rtf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/epub+zip',
        'application/zip',
        'application/x-zip',
        'application/x-ole-storage',
        // Text and data. Every one measured to read back as a real mime.
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/json',
        'text/xml',
        'application/xml',
        'text/yaml',
        'application/x-yaml',
        'text/calendar',
        'text/x-vcalendar',
        'text/vcard',
        'text/x-vcard',
        // Source code. Accepted because a code file is a text file, and the
        // picker offering it is a convenience rather than a new risk: none of
        // these execute on this origin, because every document is served with
        // an `attachment` disposition.
        'text/css',
        'application/javascript',
        'text/javascript',
        'text/x-php',
        'application/x-httpd-php',
        'text/x-shellscript',
        'application/x-sh',
        'application/x-sql',
    ];

    /**
     * Extension groups whose bytes an OLE2 or zip container is the honest
     * description of, and which a container mime is therefore acceptable for.
     *
     * See {@see ZIP_BACKED} and {@see OLE_BACKED}. Kept together so the two
     * readers of "is this a container extension" — {@see extensionMatches()}
     * and {@see extensionFor()} — cannot drift.
     */
    public const ZIP_BACKED = [
        'docx', 'xlsx', 'pptx',
        'docm', 'xlsm', 'pptm',
        'odt', 'ods', 'odp',
        'epub',
    ];

    /**
     * Legacy binary Office extensions, whose container is OLE2.
     *
     * These are *not* interchangeable with a zip, so they get their own list:
     * the detector can tell the two containers apart perfectly well, and
     * accepting a zip for a `.doc` would be a name the bytes do not fit.
     */
    public const OLE_BACKED = [
        'doc', 'xls', 'ppt',
    ];

    /**
     * The stream a legacy document of each kind must carry to be believed.
     *
     * The one fact that separates a real Word document from *any* OLE2 file,
     * including one whose payload is a macro. Measured: the disk types every
     * OLE2 compound file as `application/x-ole-storage` regardless of what is
     * inside — a Word document, a spreadsheet and a macro container are
     * indistinguishable by mime. So the extension's promise is checked against
     * the directory instead.
     *
     * A `.doc` must hold `WordDocument`, an `.xls` a `Workbook` or `Book`, and
     * a `.ppt` the `PowerPoint Document` stream. The `CompObj`/`Package` pair
     * is accepted as a fallback because some writers emit it in place of the
     * named stream. Anything else in an OLE2 container is refused.
     */
    private const OLE_STREAMS = [
        'doc' => ['WordDocument', 'Package'],
        'xls' => ['Workbook', 'Book', 'Package'],
        'ppt' => ['PowerPoint Document', 'Package'],
    ];

    /**
     * The extension list the validator's `mimes:` rule is built from.
     *
     * Laravel matches this rule on the file's guessed extension, so the list is
     * of extensions rather than mimes. Everything here is either a mime in
     * {@see IMAGES}/{@see DOCUMENTS} or a container-backed member of
     * {@see ZIP_BACKED}/{@see OLE_BACKED}; this is the same promise spelled the
     * way the rule reads it.
     */
    public const EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'svg',
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip',
        'odt', 'ods', 'odp', 'rtf', 'epub',
        // Text and data. `vcs` is here beside `ics` because PHP's own extension
        // guesser renames the format: a reader uploading `meeting.ics` is seen
        // by the rule as `vcs`, the Symbian vCalendar spelling, so a list
        // naming only `ics` refused the very file the picker offered. Measured
        // with the rule alone — `ics` guessed `vcs`, everything else in this
        // list guessed itself. The rule matches on the guess, so the guess is
        // what has to be named.
        'txt', 'md', 'csv', 'json', 'xml', 'yml', 'yaml', 'ics', 'vcs', 'vcf', 'log', 'ini',
        // Source code
        'css', 'js', 'php', 'sh', 'sql',
    ];

    /**
     * Which mime each accepted extension stands for.
     *
     * The one place the extension↔mime mapping is written. It exists because
     * three questions arrive from three directions and all of them are really
     * this mapping:
     *
     *  - the validator's `mimes:` rule matches on extension (so the rule is
     *    built from {@see EXTENSIONS});
     *  - the disk reports a mime for the bytes (so acceptance is judged by
     *    {@see accepts()});
     *  - a *name* has to be agreed between the two, which is what
     *    {@see extensionMatches()} answers — used to decide whether an upload's
     *    claimed extension is one the bytes actually support.
     *
     * `jpg`/`jpeg` both map to `image/jpeg` because both are real extensions a
     * file carries and both are the same type.
     *
     * The zip-backed entries map to `application/zip`, which is the mime their
     * bytes genuinely produce — see {@see ZIP_BACKED}. Mapping `docx` to the
     * wordprocessing mime instead would make `extensionMatches()` say "no" for
     * every real docx, and the reconciliation would then rename it to `.zip`,
     * throwing away the one fact that makes the download open in Word.
     *
     * The OOXML mimes *are* named, though, because they are a second true
     * description of those bytes: the detector reports the application mime
     * when it finds Word parts inside the archive and `application/zip` when it
     * does not. An entry exists for each so a rename cannot be mistaken for a
     * lie — the row's stored name and the exported mime are compared through
     * this map, and a `.docx` typed as Word is not a `.zip` in disguise.
     *
     * @return array<string, string>
     */
    public static function extensionMap(): array
    {
        return [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            'ico' => 'image/vnd.microsoft.icon',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'rtf' => 'text/rtf',
            // The zip container the OOXML/ODF/EPUB families are built on.
            // `extensionMatches()` accepts either the container or the
            // application mime for these.
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            // ODF names its own container in a `mimetype` entry, but the
            // detector reports the zip it also is, so the map records the
            // application mime and `extensionMatches()` pairs it with the
            // container. See ZIP_BACKED.
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'epub' => 'application/epub+zip',
            'zip' => 'application/zip',
            // The legacy binary formats, whose container the disk reports and
            // which `legacyOfficeKind()` inspects. See OLE_BACKED.
            'doc' => 'application/x-ole-storage',
            'xls' => 'application/x-ole-storage',
            'ppt' => 'application/x-ole-storage',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'text/xml',
            'yml' => 'text/yaml',
            'yaml' => 'text/yaml',
            // Measured: the disk reports `text/calendar` for `meeting.ics` and
            // `text/x-vcalendar` for the `.vcs` spelling of the same bytes. The
            // map has to name the mime the disk *actually* reports, or
            // `extensionMatches()` says "no" for the honest file and
            // `reconcileExtension()` renames a calendar to a spelling its
            // author never chose — measured, an uploaded `sample.ics` was
            // stored as `…vcs`. Both mimes are in DOCUMENTS, so both
            // spellings stay acceptable.
            'ics' => 'text/calendar',
            // The same format under the spelling PHP's guesser produces. See
            // EXTENSIONS: the rule matches on the guessed extension, which for
            // this family is `vcs`.
            'vcs' => 'text/x-vcalendar',
            'vcf' => 'text/vcard',
            'log' => 'text/plain',
            'ini' => 'text/plain',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'php' => 'text/x-php',
            'sh' => 'text/x-shellscript',
            'sql' => 'application/x-sql',
        ];
    }

    /**
     * The mime an extension stands for, or null when the list does not know it.
     */
    public static function mimeForExtension(string $extension): ?string
    {
        return self::extensionMap()[strtolower($extension)] ?? null;
    }

    /**
     * The extension a mime should be stored and offered under.
     *
     * Derived by inverting {@see extensionMap()}, so a format added to the type
     * list is nameable with no second edit. The generic zip mime is the one
     * case the map cannot answer alone: `docx`, `xlsx`, `pptx` and `zip` all
     * share `application/zip` as their container, so the first entry to claim
     * it would swallow the rest. A container mime therefore resolves to the
     * extension that names the container itself, and only the application mimes
     * are specific enough to name a particular format.
     */
    public static function extensionFor(string $mime): ?string
    {
        // The container is the only honest name for bytes the detector could
        // not open, so it is not claimed by any one of the formats inside it.
        if (in_array($mime, self::CONTAINER_MIMES, true)) {
            return 'zip';
        }

        // The OLE2 container is shared by all three legacy formats, so it
        // cannot name one of them either. The directory decides which it is.
        if ($mime === 'application/x-ole-storage') {
            return null;
        }

        foreach (self::extensionMap() as $extension => $candidate) {
            if ($candidate === $mime) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * Whether a claimed extension is one the given mime actually supports.
     *
     * The question a download's name depends on. A PNG uploaded as `lie.pdf`
     * is stored — the bytes are what counts — but naming it `.pdf` would hand
     * the file to a PDF reader, so the name has to be derived from the type
     * rather than trusted from the upload.
     *
     * Container equivalence is the subtle half, and it runs both ways. A
     * `.docx` is a zip, and the detector reports whichever of the two it can
     * see: the OOXML mime when the archive holds Word parts, `application/zip`
     * when it does not. Both are true descriptions of the same bytes, so both
     * are accepted for a zip-backed extension — rejecting the generic one would
     * rename every `.docx` whose parts the detector could not identify to
     * `.zip`, which downloads correctly and opens in the wrong application.
     *
     * The same tolerance applies to the OLE2 family, and it is scoped by
     * container rather than shared: a `.doc` accepts `application/x-ole-storage`
     * and a `.zip` does not, because the detector can tell those two apart and
     * a name the bytes do not fit is a download that opens in nothing.
     */
    public static function extensionMatches(string $extension, ?string $mime): bool
    {
        $extension = strtolower($extension);

        // No type available means no name can be justified.
        if ($mime === null) {
            return false;
        }

        if (self::mimeForExtension($extension) === $mime) {
            return true;
        }

        // The type the extension stands for, or the container it lives in.
        if (self::extensionFor($mime) === $extension) {
            return true;
        }

        if (in_array($extension, self::ZIP_BACKED, true)
            && in_array($mime, self::CONTAINER_MIMES, true)) {
            return true;
        }

        return in_array($extension, self::OLE_BACKED, true)
            && $mime === 'application/x-ole-storage';
    }

    /**
     * Which legacy Office document an OLE2 container holds, or null.
     *
     * The read-back needs this because the *mime* cannot answer it: measured,
     * a Word document, a spreadsheet and a macro container all report
     * `application/x-ole-storage`. So the extension's promise is checked
     * against the OLE2 directory instead, which names the streams inside.
     *
     * This is what makes accepting the legacy formats safe rather than a blank
     * cheque. `application/x-ole-storage` alone would vouch for *any* compound
     * file — the same container a macro payload ships in — so a file whose
     * directory holds none of the streams Word, Excel or PowerPoint write is
     * refused, and the reader is told the file is not what they called it.
     *
     * Returns the extension the bytes support, so the caller can compare it
     * with the name the upload claimed.
     */
    public static function legacyOfficeKind(string $extension, string $bytes): ?string
    {
        $extension = strtolower($extension);

        $wanted = self::OLE_STREAMS[$extension] ?? null;

        // Only asked about the legacy extensions; anything else is not an OLE2
        // document and the question does not apply.
        if ($wanted === null) {
            return null;
        }

        $streams = self::oleStreams($bytes);

        if ($streams === []) {
            return null;
        }

        foreach ($wanted as $stream) {
            if (in_array($stream, $streams, true)) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * The stream names in an OLE2 compound file's directory.
     *
     * A deliberately narrow reader: header fields the format fixes (sector
     * size, the first directory sector), then the 128-byte directory entries,
     * taking each entry's UTF-16LE name. It parses nothing it does not need and
     * allocates nothing proportional to the file, because the input is
     * attacker-supplied — a malformed directory must yield an empty list, not
     * a traversal or a huge allocation.
     *
     * The magic number is checked first, so a file that is not OLE2 at all
     * costs one comparison.
     *
     * @return array<int, string>
     */
    private static function oleStreams(string $bytes): array
    {
        $magic = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

        // Header (512 bytes) + the fields this reader needs.
        if (strlen($bytes) < 512 || ! str_starts_with($bytes, $magic)) {
            return [];
        }

        $sectorShift = self::uint16($bytes, 30);
        $firstDirectorySector = self::uint32($bytes, 48);

        // The format allows 512- or 4096-byte sectors, named by a shift. A
        // shift outside that range is a corrupt header, not a bigger file.
        if ($sectorShift !== 9 && $sectorShift !== 12) {
            return [];
        }

        $sectorSize = 1 << $sectorShift;
        $directoryStart = ($firstDirectorySector + 1) * $sectorSize;

        // A directory sector index that lands past the file is corrupt.
        if ($directoryStart >= strlen($bytes)) {
            return [];
        }

        $names = [];

        // One sector holds 512/128 = 4 entries at the small sector size. Only
        // the first sector is read: the streams this reader looks for are
        // written there by every producer, and reading the chain would mean
        // trusting the FAT for no gain.
        for ($offset = $directoryStart; $offset + 128 <= strlen($bytes) && $offset < $directoryStart + $sectorSize; $offset += 128) {
            $entry = substr($bytes, $offset, 128);

            $nameLength = self::uint16($entry, 64);

            // The length counts the terminating NUL and is capped at the
            // 64-byte name field. Anything else is a corrupt entry.
            if ($nameLength < 2 || $nameLength > 64) {
                continue;
            }

            $name = substr($entry, 0, $nameLength - 2);

            // Converted from the UTF-16LE the format stores. A name that is not
            // plain ASCII is simply not one of the stream names this reader
            // matches on, so it is kept as-is and compared literally.
            $name = (string) @mb_convert_encoding($name, 'UTF-8', 'UTF-16LE');

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * A little-endian 16-bit field, or 0 when the bytes are too short.
     *
     * `unpack()` returns `false` rather than throwing, and offsetting that
     * `false` is a PHP warning plus a `null` this reader would then compare
     * against an int. Reading through a helper that cannot fail keeps the
     * malformed-input path a plain zero — which every caller already treats as
     * "corrupt header, nothing to read" rather than as a special case.
     */
    private static function uint16(string $bytes, int $offset): int
    {
        if (strlen($bytes) < $offset + 2) {
            return 0;
        }

        $value = unpack('v', substr($bytes, $offset, 2));

        return $value === false ? 0 : $value[1];
    }

    /**
     * A little-endian 32-bit field, or 0 when the bytes are too short.
     */
    private static function uint32(string $bytes, int $offset): int
    {
        if (strlen($bytes) < $offset + 4) {
            return 0;
        }

        $value = unpack('V', substr($bytes, $offset, 4));

        return $value === false ? 0 : $value[1];
    }

    /**
     * The mimes that describe a container rather than its contents.
     *
     * Both the zip family and the OOXML formats inside it are typed with these,
     * depending on whether the detector could open the archive. Kept as a list
     * so the two readers of the fact — the naming in {@see extensionFor()} and
     * the tolerance in {@see extensionMatches()} — cannot drift apart.
     */
    private const CONTAINER_MIMES = [
        'application/zip',
        'application/x-zip',
    ];

    /**
     * Every mime the composer accepts, images first.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(self::IMAGES, self::DOCUMENTS);
    }

    /**
     * The `accept` string an `<input type="file">` and a picker should carry.
     *
     * Built from the same lists the validator uses, so the invitation and the
     * rule are one fact written once. A reader is offered exactly what the disk
     * will take — no more, which is a false promise, and no less, which hides a
     * supported format behind a picker that cannot select it.
     */
    public static function accept(): string
    {
        return implode(',', self::all());
    }

    /**
     * The value for the validator's `mimes:` rule.
     */
    public static function mimesRule(): string
    {
        return 'mimes:'.implode(',', self::EXTENSIONS);
    }

    /**
     * Whether PHP's own decoder can read this image type.
     *
     * Asked before a content check that decodes, because the decoder's reach is
     * narrower than the browser's. Measured in this environment: no GD and no
     * Imagick, so `getimagesizefromstring()` handles PNG, JPEG, GIF, BMP, WebP,
     * TIFF, ICO and SVG — and returns `false` for AVIF, which it cannot see
     * into at all. Treating that `false` as "not an image" would have refused
     * every AVIF upload while the picker kept offering the type: a format
     * listed, offered, and impossible to store.
     *
     * So the decode check is applied only where a `false` means something. A
     * type this returns `false` for is still typed by its signature instead —
     * see {@see signatureMatches()}.
     */
    public static function isDecodable(string $mime): bool
    {
        return ! in_array($mime, self::HAND_DECODED, true);
    }

    /**
     * Image types PHP cannot decode, so a signature check stands in for one.
     *
     * Kept as a list rather than inferred, because "can this runtime decode X"
     * is not answerable from the type list — it depends on which extensions are
     * loaded, and a deployment with GD would move the answer.
     */
    private const HAND_DECODED = [
        'image/avif',
    ];

    /**
     * Whether the bytes carry the signature this mime implies.
     *
     * The fallback for a type the decoder cannot read. Only the container's
     * opening magic is checked, which is the same depth of assurance the
     * extension-based disk typing gives — the difference is that this reads the
     * bytes rather than the name, so a text file called `.avif` fails here.
     */
    public static function signatureMatches(string $mime, string $bytes): bool
    {
        $signatures = [
            // ISOBMFF: a 4-byte length, then the `ftyp` box, then a brand.
            'image/avif' => fn (string $b): bool => strlen($b) >= 12
                && substr($b, 4, 4) === 'ftyp'
                && substr($b, 8, 4) === 'avif',
            'image/jpeg' => fn (string $b): bool => str_starts_with($b, "\xFF\xD8\xFF"),
            'image/png' => fn (string $b): bool => str_starts_with($b, "\x89PNG\r\n\x1a\n"),
            'image/gif' => fn (string $b): bool => str_starts_with($b, 'GIF87a') || str_starts_with($b, 'GIF89a'),
            'image/bmp' => fn (string $b): bool => str_starts_with($b, 'BM'),
            'image/webp' => fn (string $b): bool => str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP',
            'image/tiff' => fn (string $b): bool => str_starts_with($b, 'II') || str_starts_with($b, 'MM'),
            'image/vnd.microsoft.icon' => fn (string $b): bool => str_starts_with($b, "\x00\x00\x01\x00"),
            'image/svg+xml' => fn (string $b): bool => SvgSanitizer::looksLikeSvg($b),
        ];

        $check = $signatures[$mime] ?? null;

        // A type with no signature check is not refused for that reason alone:
        // the disk typed it, the list accepts it, and inventing a rule here
        // would be a second, weaker gate for the same question.
        return $check === null || $check($bytes);
    }

    /**
     * Whether a mime is one the browser may paint inline.
     *
     * Asked by the card (to choose between a thumbnail and a chip) and by the
     * controller (to choose between an inline disposition and a download), so
     * that the two answers are always the same answer.
     */
    public static function isImage(?string $mime): bool
    {
        return $mime !== null && in_array($mime, self::IMAGES, true);
    }

    /**
     * Whether a mime is one this app accepts at all.
     */
    public static function accepts(?string $mime): bool
    {
        return $mime !== null && in_array($mime, self::all(), true);
    }

    /**
     * The human word for a file's kind, for a chip's label and spoken name.
     *
     * The extension is asked first, because for a document it is the more
     * specific truth. A `.docx` and a `.zip` are the same bytes and the same
     * mime, and the mime alone cannot tell them apart — so a chip driven by the
     * mime would call a Word document an Archive, which is true about the
     * container and useless to the reader. Where there is an extension it wins;
     * the mime is the fallback for a row that somehow has none.
     */
    public static function kind(?string $mime, ?string $extension = null): string
    {
        $byExtension = match (strtolower((string) $extension)) {
            'pdf' => 'PDF',
            'doc', 'docx', 'docm', 'odt', 'rtf' => 'Document',
            'xls', 'xlsx', 'xlsm', 'ods' => 'Spreadsheet',
            'ppt', 'pptx', 'pptm', 'odp' => 'Presentation',
            'zip' => 'Archive',
            'epub' => 'Book',
            'txt', 'md', 'csv', 'log', 'ini' => 'Text',
            'json', 'xml', 'yml', 'yaml' => 'Data',
            'ics' => 'Calendar',
            'vcf' => 'Contact',
            'css', 'js', 'php', 'sh', 'sql' => 'Code',
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'svg' => 'Image',
            default => null,
        };

        if ($byExtension !== null) {
            return __($byExtension);
        }

        if ($mime === null) {
            return __('File');
        }

        // Matched as words rather than through `__()` so the return type stays
        // a string: the translator is typed `array|string`, and an `array` can
        // never be returned here — PHPStan cannot see that from the call site,
        // and a `@return` annotation on a factory of literals would be a claim
        // about the framework, not about this method.
        $kind = match (true) {
            $mime === 'application/pdf' => 'PDF',
            str_starts_with($mime, 'image/') => 'Image',
            str_starts_with($mime, 'text/') => 'Text',
            $mime === 'application/x-ole-storage' => 'Document',
            $mime === 'application/json', $mime === 'application/xml' => 'Data',
            $mime === 'application/rtf' => 'Document',
            in_array($mime, self::CONTAINER_MIMES, true) => 'Archive',
            default => 'File',
        };

        return __($kind);
    }
}
