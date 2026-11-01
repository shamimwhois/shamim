<?php

namespace App\Data;

use App\Enums\CorrectionType;

/**
 * A single correction suggested by the copy editor.
 *
 * ## Why this carries offsets as well as text
 *
 * A suggestion has to be applied to the exact span it describes. Carrying only
 * `original`/`replacement` leaves the client to locate the span by string
 * search, which silently corrupts the document when the same phrase appears
 * more than once — "the the" collapsed in one place and not another. Offsets
 * make the edit unambiguous.
 *
 * Offsets are supplied by the model but **re-verified against the source text**
 * in {@see self::fromArray()}. A model that returns a correct-offset-pair is
 * common; one that returns a stale offset after a previous edit is also common.
 * When the offsets do not agree with the text at that span, they are
 * recomputed by search, and the suggestion is dropped entirely if it cannot be
 * placed. Trusting the model's arithmetic is what produces edits that land
 * somewhere random.
 */
readonly class TextCorrection
{
    /**
     * @param  string  $original  The exact substring to replace.
     * @param  string  $replacement  What to replace it with.
     * @param  int  $start  Byte offset of the span start in the source.
     * @param  int  $end  Byte offset of the span end in the source.
     */
    public function __construct(
        public string $original,
        public string $replacement,
        public CorrectionType $type,
        public string $explanation,
        public int $start,
        public int $end,
    ) {}

    /**
     * Build a correction from the model's structured output.
     *
     * Returns null when the suggestion cannot be placed in the source text, or
     * when it is a no-op. Both cases are routine: models occasionally return a
     * "correction" identical to the original, and an empty suggestion shown to
     * an author erodes trust in the whole list.
     *
     * Offsets from the model are accepted only when `substr($source, $start,
     * $end - $start)` actually equals `original`. Otherwise they are recomputed
     * by searching for the original text.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $source): ?self
    {
        $original = trim((string) ($data['original'] ?? ''));
        $replacement = (string) ($data['replacement'] ?? '');

        // Nothing to find, or nothing actually changed.
        if ($original === '' || $original === $replacement) {
            return null;
        }

        $type = CorrectionType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null) {
            return null;
        }

        $start = isset($data['start']) && is_numeric($data['start']) ? (int) $data['start'] : -1;
        $end = $start >= 0 && isset($data['end']) && is_numeric($data['end']) ? (int) $data['end'] : -1;

        $offsetsTrustworthy = $start >= 0
            && $end > $start
            && $end <= strlen($source)
            && substr($source, $start, $end - $start) === $original;

        if (! $offsetsTrustworthy) {
            // Recompute. `strpos` finds the first occurrence, which is the
            // right default: earlier text is more likely to be what the model
            // was reading when it made the suggestion.
            $position = strpos($source, $original);

            if ($position === false) {
                return null;
            }

            $start = $position;
            $end = $position + strlen($original);
        }

        return new self(
            original: $original,
            replacement: $replacement,
            type: $type,
            explanation: trim((string) ($data['explanation'] ?? '')),
            start: $start,
            end: $end,
        );
    }

    /**
     * Whether this suggestion is a mechanical fix rather than a judgement call.
     */
    public function isMechanical(): bool
    {
        return $this->type->isMechanical();
    }

    /**
     * A stable identity for this suggestion, used to track which ones the
     * author has accepted or dismissed across re-renders.
     */
    public function id(): string
    {
        return substr(hash('xxh128', $this->type->value.'|'.$this->start.'|'.$this->original.'|'.$this->replacement), 0, 16);
    }

    /**
     * Get this correction as an array for JSON transport.
     *
     * @return array{id: string, original: string, replacement: string, type: string, type_label: string, explanation: string, start: int, end: int, mechanical: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'original' => $this->original,
            'replacement' => $this->replacement,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'explanation' => $this->explanation,
            'start' => $this->start,
            'end' => $this->end,
            'mechanical' => $this->isMechanical(),
        ];
    }
}
