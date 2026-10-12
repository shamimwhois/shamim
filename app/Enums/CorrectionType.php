<?php

namespace App\Enums;

/**
 * The kinds of correction the copy editor can suggest.
 *
 * Modelled as an enum rather than free-text labels because the type drives real
 * behaviour in the UI: each type gets its own icon, its own accent colour, and
 * whether it is applied by default when "accept all" is pressed. Weighting
 * matters — a run of spelling fixes is uncontroversial, whereas a run of style
 * changes is a matter of taste, so the two must not be bundled together behind
 * one button.
 */
enum CorrectionType: string
{
    case Spelling = 'spelling';
    case Grammar = 'grammar';
    case Punctuation = 'punctuation';
    case Style = 'style';
    case Clarity = 'clarity';
    case Tone = 'tone';

    /**
     * Get the display label for this correction type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Spelling => __('Spelling'),
            self::Grammar => __('Grammar'),
            self::Punctuation => __('Punctuation'),
            self::Style => __('Style'),
            self::Clarity => __('Clarity'),
            self::Tone => __('Tone'),
        };
    }

    /**
     * Get the Flux icon for this correction type.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Spelling => 'language',
            self::Grammar => 'check-circle',
            self::Punctuation => 'document-text',
            self::Style => 'sparkles',
            self::Clarity => 'eye',
            self::Tone => 'chat-bubble-left-right',
        };
    }

    /**
     * The Tailwind colour family used for this type's badge.
     *
     * Returned as a family name rather than a full class string so the view
     * decides how to apply it and the palette stays in one place.
     */
    public function color(): string
    {
        return match ($this) {
            self::Spelling => 'red',
            self::Grammar => 'amber',
            self::Punctuation => 'orange',
            self::Style => 'violet',
            self::Clarity => 'sky',
            self::Tone => 'teal',
        };
    }

    /**
     * Whether this type is selected by default when accepting a batch.
     *
     * Mechanical corrections default on; judgement calls default off. "Accept
     * all" should never silently rewrite an author's voice.
     */
    public function isMechanical(): bool
    {
        return match ($this) {
            self::Spelling, self::Grammar, self::Punctuation => true,
            self::Style, self::Clarity, self::Tone => false,
        };
    }

    /**
     * The instruction the copy editor is given for this type.
     *
     * Lives on the enum because it describes the case it belongs to, and the
     * agent builds its list from the requested types — keeping the two
     * together is what stops the prompt from listing a category it was told
     * to ignore.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::Spelling => 'Spelling and typos, including repeated words ("the the").',
            self::Grammar => 'Grammar: subject-verb agreement, tense consistency, article errors, dangling modifiers, wrong prepositions.',
            self::Punctuation => 'Punctuation: missing or misplaced commas, apostrophes, quotation marks, sentence-final punctuation.',
            self::Clarity => 'Clarity: a sentence that has to be read twice to be understood.',
            self::Style => 'Style: passive constructions that hide the actor, nominalisations that would be plainer as verbs, wordy phrases with a shorter equivalent.',
            self::Tone => 'Tone: register that clashes with the surrounding text.',
        };
    }

    /**
     * The severity weight, used to sort suggestions so the most mechanical
     * fixes appear first. Lower sorts earlier.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Spelling => 0,
            self::Grammar => 1,
            self::Punctuation => 2,
            self::Clarity => 3,
            self::Style => 4,
            self::Tone => 5,
        };
    }

    /**
     * Get every case as a plain array for JSON and the front end.
     *
     * @return array<int, array{value: string, label: string, icon: string, color: string, mechanical: bool}>
     */
    public static function toArray(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'icon' => $case->icon(),
            'color' => $case->color(),
            'mechanical' => $case->isMechanical(),
        ], self::cases());
    }
}
