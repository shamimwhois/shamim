<?php

namespace App\Enums;

/**
 * The tone the AI writer should adopt.
 *
 * Tone is a separate axis from {@see ContentCategory}: a product description
 * and an email can both be written professionally or playfully. Keeping them
 * apart means the category decides the *shape* of the output and the tone
 * decides its *voice*, so the two can be combined freely instead of every
 * category/tone pair needing its own instruction.
 *
 * Each case emits a sentence fragment that is dropped into the system prompt.
 * It is phrased as guidance rather than as an adjective list ("Write in a
 * professional register…" rather than "professional, formal, business") because
 * models follow described behaviour more reliably than they follow a string of
 * labels.
 */
enum ContentTone: string
{
    case Professional = 'professional';
    case Conversational = 'conversational';
    case Persuasive = 'persuasive';
    case Technical = 'technical';
    case Playful = 'playful';
    case Authoritative = 'authoritative';

    /**
     * Get the display label shown in the tone dropdown.
     */
    public function label(): string
    {
        return match ($this) {
            self::Professional => __('Professional'),
            self::Conversational => __('Conversational'),
            self::Persuasive => __('Persuasive'),
            self::Technical => __('Technical'),
            self::Playful => __('Playful'),
            self::Authoritative => __('Authoritative'),
        };
    }

    /**
     * Get the instruction fragment describing this tone.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::Professional => 'Write in a professional register: clear, measured and free of slang. Contractions are acceptable. Do not be stiff or bureaucratic — professional means precise, not pompous.',
            self::Conversational => 'Write conversationally, as though explaining the idea to a capable colleague. Prefer the active voice and plain words. Contractions are welcome. Avoid filler openings like "In today\'s fast-paced world".',
            self::Persuasive => 'Write persuasively. Lead with the reader\'s situation rather than the product. Earn each claim with a concrete detail, and avoid superlatives that carry no information ("revolutionary", "game-changing").',
            self::Technical => 'Write with technical precision. Name things exactly, define a term the first time it is used, and prefer the specific over the approximate. Do not simplify at the cost of accuracy.',
            self::Playful => 'Write with a light, playful voice. Wit is welcome where it does not obscure the point, but never at the reader\'s expense. Keep jokes short and rare enough to land.',
            self::Authoritative => 'Write with authority: confident, direct and unhurried. State positions plainly instead of hedging. Do not pad with qualifiers, and do not overclaim beyond what the source material supports.',
        };
    }

    /**
     * Get every case as a value => label map, for a `<select>` input.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Get every case as a plain array for JSON and the front end.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function toArray(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
