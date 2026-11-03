<?php

namespace App\Support;

/**
 * Read the upload ceilings the running PHP process actually enforces.
 *
 * An upload in this app is bounded by three settings that live in three
 * different places, and only one of them is under the application's control:
 *
 *   - `upload_max_filesize` — PHP. The real ceiling. A file above it never
 *     reaches a controller or a Livewire component; PHP discards it and the
 *     request arrives with an empty file slot, or with an error the framework
 *     can only report as a failed transfer.
 *   - `post_max_size` — PHP. Bounds the whole request body, so a file can be
 *     under `upload_max_filesize` and still be lost when it rides alongside
 *     other fields. It is the stricter of the two for a multi-file pick.
 *   - `feed.composer.max_upload_kilobytes` — the application's advertised cap.
 *     This is the only one a reader is ever told about, which is why it is the
 *     one that must not exceed the other two.
 *
 * Nothing here changes a limit; PHP settings are deployment concerns and
 * `ini_set` cannot raise an upload ceiling for the request that is already
 * parsing it. What this class does is make the *effective* limit readable, so
 * the number quoted in a validation message and attached to the file picker is
 * one the process will genuinely honour. A message that names a limit larger
 * than PHP's is worse than no message: it tells a reader to retry something
 * that cannot succeed.
 *
 * Values arrive from `ini_get()` in PHP's shorthand (`2M`, `512K`, `1G`) and
 * are parsed the way PHP itself parses them. `-1` means unlimited and is
 * reported as such.
 */
final class UploadLimits
{
    /** The largest body PHP will accept, or PHP_INT_MAX when unlimited. */
    public static function effectiveBytes(): int
    {
        return min(
            self::parse((string) ini_get('upload_max_filesize')),
            self::parse((string) ini_get('post_max_size')),
        );
    }

    /**
     * The effective limit as the number of kilobytes a `max:` rule expects.
     *
     * Laravel's file `max` rule counts kilobytes, while PHP counts bytes, so
     * the two can never be compared without a conversion. This is that
     * conversion, in one place, so a caller never has to remember which side
     * of it they are on.
     */
    public static function effectiveKilobytes(): int
    {
        $bytes = self::effectiveBytes();

        return $bytes === PHP_INT_MAX ? PHP_INT_MAX : (int) floor($bytes / 1024);
    }

    /**
     * A limit in kilobytes rendered the way a person reads it.
     *
     * Whole megabytes are shown as megabytes; anything else keeps one decimal,
     * because `1.5 MB` is a real ceiling on a stock PHP build and rounding it
     * to `1 MB` would misstate what a reader may attach.
     */
    public static function human(int $kilobytes): string
    {
        if ($kilobytes >= 1024 && $kilobytes % 1024 === 0) {
            return ($kilobytes / 1024).' MB';
        }

        if ($kilobytes >= 1024) {
            return rtrim(rtrim(number_format($kilobytes / 1024, 1, '.', ''), '0'), '.').' MB';
        }

        return $kilobytes.' KB';
    }

    /**
     * Parse a PHP shorthand size into bytes.
     *
     * Public because it is the part with the trap in it and the part a test can
     * actually reach: `upload_max_filesize` and `post_max_size` are
     * `PHP_INI_PERDIR`, so a test cannot move them at runtime to check the
     * arithmetic through {@see effectiveBytes()}. Testing the parse directly is
     * the only honest option, and leaving it private would mean the unit
     * handling — the one place a two-byte limit would come from — had no test
     * at all.
     *
     * `ini_get` returns the raw `php.ini` text, not a number, so `2M` has to be
     * read rather than cast — `(int) '2M'` is `2`. An unlimited value (`-1`) is
     * reported as `PHP_INT_MAX` so `min()` against it is a no-op rather than a
     * ceiling of negative one.
     */
    public static function parse(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        // PHP treats a bare number as bytes and recognises K, M and G.
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
