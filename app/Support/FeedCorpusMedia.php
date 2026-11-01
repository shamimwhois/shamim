<?php

namespace App\Support;

use App\Actions\Feed\AttachFeedMedia;
use App\Models\FeedAttachment;
use App\Models\FeedPost;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The images a seeded post carries.
 *
 * The corpus is text on purpose — canonical content, no binaries in the
 * repository. But a feed whose image grid can only be seen after someone
 * uploads something is a grid nobody can review, so the seeder paints its own
 * placeholders: solid panels in the log's lane colours, generated at seed time
 * from a few dozen bytes of PNG the encoder below writes.
 *
 * The encoder is deliberately hand-rolled rather than GD. The install may not
 * have GD, and a seed that quietly produces no images because an extension is
 * missing is a seed that lies about what it did. A PNG is a container around
 * zlib-deflated scanlines plus two checksums, which is all this needs to be.
 */
final class FeedCorpusMedia
{
    /** The panels are painted from the log's own author lanes, so the images read as part of the graph rather than as stock art. */
    private const PALETTE = [
        [217, 72, 15],
        [47, 158, 68],
        [156, 54, 181],
        [12, 133, 153],
        [194, 37, 92],
        [183, 121, 31],
    ];

    private const WIDTH = 800;

    private const HEIGHT = 500;

    /**
     * Give a post the number of images the corpus asked for.
     *
     * A post that already carries media is left alone: re-running the seed
     * corrects content without multiplying screenshots.
     */
    public static function attachTo(FeedPost $post, int $count): void
    {
        if ($count < 1 || $post->user_id === null || $post->attachments()->exists()) {
            return;
        }

        for ($offset = 0; $offset < $count; $offset++) {
            $bytes = self::solidPng(
                self::WIDTH,
                self::HEIGHT,
                self::PALETTE[($post->id + $offset) % count(self::PALETTE)],
            );

            $path = 'posts/'.$post->id.'/'.Str::random(16).'.png';

            Storage::disk(AttachFeedMedia::DISK)->put($path, $bytes);

            FeedAttachment::query()->create([
                'feed_post_id' => $post->id,
                'user_id' => $post->user_id,
                'disk' => AttachFeedMedia::DISK,
                'path' => $path,
                'mime' => 'image/png',
                'size' => strlen($bytes),
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
                'alt_text' => null,
                'sort_order' => $offset,
                'temp_key' => null,
            ]);
        }
    }

    /**
     * A solid-colour PNG, written by hand.
     *
     * Truecolour (colour type 2), one filter byte per scanline, the whole
     * image in a single IDAT. Nineteen hundred bytes for a full panel.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public static function solidPng(int $width, int $height, array $rgb): string
    {
        // Width, height, bit depth, colour type, compression, filter, interlace.
        $header = pack('N2C5', $width, $height, 8, 2, 0, 0, 0);

        $pixel = pack('C3', ...$rgb);
        $scanline = "\x00".str_repeat($pixel, $width);
        $stream = zlib_encode(str_repeat($scanline, $height), ZLIB_ENCODING_DEFLATE, 9);

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', $header)
            .self::chunk('IDAT', (string) $stream)
            .self::chunk('IEND', '');
    }

    /**
     * One PNG chunk: length, type, data, CRC over type and data.
     */
    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
