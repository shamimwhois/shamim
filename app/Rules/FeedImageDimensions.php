<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\FeedAttachmentTypes;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The pixel bounds a composer upload must respect — for anything with pixels.
 *
 * Laravel's own `dimensions:` rule cannot do this job here. It applies to every
 * element of the array it is declared on and it reports a file whose size it
 * cannot read as a *dimension* failure, so declared on `uploads.*` beside a
 * `mimes:` rule that now accepts documents, it refuses every PDF with "that
 * image is too small or too large" — the type rule says yes, this rule says no,
 * and the reader is told something untrue about a file that is perfectly fine.
 *
 * So this is that rule with one question asked first: **does this file have
 * pixels at all?** The answer is taken from the bytes rather than from the
 * name, because the name is attacker-chosen — measured, a 1×1 PNG renamed
 * `lie.pdf` was typed `application/pdf` by the upload layer, and a rule that
 * trusted that typing let a file the composer refuses under its real name
 * through under a false one.
 *
 * Three outcomes, and it matters that they are distinct:
 *
 *  - **The bytes decode as an image.** Measured against the bounds, and refused
 *    if they fall outside them. This is the rule's whole purpose.
 *  - **The bytes do not decode, and the file does not claim to be an image.**
 *    Not this rule's business. It does not fail, because a PDF or a spreadsheet
 *    is a file with no pixels and a bound on pixels is not a statement about
 *    it. Whether the file is acceptable is the type rule's question, and what
 *    the bytes really are is settled by the read-back on the way to the disk.
 *  - **The bytes do not decode, but the file claims to be an image.** Something
 *    is wrong with it, and it is refused. It is refused *here* rather than left
 *    to the read-back because the read-back would reject it too, and a file
 *    rejected at read-back loses the reader's upload with a message about
 *    storage rather than one about the file they chose.
 *
 * The bounds are the composer's: a 1×1 transparent PNG is a valid image and
 * useless as post media, and a 12000 px panorama will blow the card layout.
 * Both have always been refusals on the modal path; both are refusals here.
 */
final readonly class FeedImageDimensions implements ValidationRule
{
    public function __construct(
        private int $minWidth = 16,
        private int $minHeight = 16,
        private int $maxWidth = 6000,
        private int $maxHeight = 6000,
    ) {
        //
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            // Other rules own those two failures; a second message for one
            // problem would only push the first one off the panel.
            return;
        }

        $size = @getimagesizefromstring((string) $value->get());

        if ($size === false) {
            // Not an image this process can read. Refused only when the file
            // claimed to be one — otherwise it is some other kind of file and
            // the type rule is what decides whether the app wants it.
            if ($this->claimsToBeAnImage($value)) {
                $fail($this->message());
            }

            return;
        }

        // The bytes carry a decodable image, whatever the file is called. The
        // bounds apply, and a rename is not a way around them.
        [$width, $height] = $size;

        $tooSmall = $width < $this->minWidth || $height < $this->minHeight;
        $tooLarge = $width > $this->maxWidth || $height > $this->maxHeight;

        if ($tooSmall || $tooLarge) {
            $fail($this->message());
        }
    }

    /**
     * Whether the upload presents itself as an image.
     *
     * Used only to choose between silence and a refusal once the bytes have
     * already failed to decode, so the answer does not have to be trustworthy
     * in the other direction: a file that lies about being an image and *is*
     * one is caught by the decode, and a file that lies and is not is refused
     * either way — by this rule if it claims to be an image, by the read-back
     * if it claims to be a document it is not.
     *
     * A type this process cannot decode is excluded, because a failed decode
     * there says nothing about the file. Measured with no GD and no Imagick:
     * `getimagesizefromstring` returns false for a *valid* AVIF, so asking it
     * about one would refuse every AVIF while the picker went on offering the
     * type. Those are confirmed by signature on the way to the disk instead,
     * which is where the type question belongs.
     */
    private function claimsToBeAnImage(UploadedFile $file): bool
    {
        // The type the app should treat this as: the declared mime when it has
        // one, otherwise the mime the extension stands for. This mirrors how
        // the validator reached the file — `mimes:` matches on extension — so a
        // file that arrived through an image name is held to it.
        $mime = $file->getMimeType() ?? FeedAttachmentTypes::mimeForExtension(
            strtolower((string) $file->extension()),
        );

        if ($mime === null) {
            return false;
        }

        // An image the process cannot decode is not held to the decode. A
        // `false` there says nothing about the file — measured with no GD and
        // no Imagick, a *valid* AVIF fails to decode — so asking would refuse
        // the whole type while the picker kept offering it. Those are confirmed
        // by signature on the way to the disk, which is where the type question
        // belongs anyway.
        return FeedAttachmentTypes::isImage($mime) && FeedAttachmentTypes::isDecodable($mime);
    }

    /**
     * The sentence a reader is shown.
     *
     * A literal, not a translation key, and that is deliberate. Laravel resolves
     * a failed `ValidationRule` by looking the rule's *class name* up in the
     * messages array and falls back to printing the class name itself when
     * nothing matches — which was measured: a `$fail('feed_media.dimensions')`
     * key reached the reader as the words `feed_media.dimensions`, on the one
     * line the panel shows.
     *
     * A caller may still override it: a `$messages` entry keyed
     * `uploads.*.App\Rules\FeedImageDimensions` wins, because that lookup runs
     * first. Nothing does today, so the sentence lives here where it cannot be
     * orphaned from the rule that needs it.
     */
    private function message(): string
    {
        return __('That image is too small or too large to attach.');
    }
}
