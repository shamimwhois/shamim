<?php

namespace App\Enums;

/**
 * The shape of a piece of knowledge content.
 *
 * Format answers "what kind of thing is this" — an explanatory article, a
 * step-by-step tutorial, a terse technical note. It is deliberately distinct
 * from a topic, which answers "what is this about", so a `prompting` topic can
 * contain both a glossary entry and a full tutorial without either one being
 * misfiled.
 *
 * The set comes from the upstream spec's content types. It is extensible: a new
 * case here appears in every format dropdown automatically because the options
 * are generated from `cases()`.
 */
enum AiArticleFormat: string
{
    case Article = 'article';
    case Tutorial = 'tutorial';
    case TechnicalNote = 'technical_note';
    case StudyNote = 'study_note';
    case Glossary = 'glossary';
    case CodeExample = 'code_example';
    case Architecture = 'architecture';
    case Guide = 'guide';
    case CaseStudy = 'case_study';
    case Experiment = 'experiment';

    /**
     * Get the display label for the format.
     */
    public function label(): string
    {
        return match ($this) {
            self::Article => __('Article'),
            self::Tutorial => __('Tutorial'),
            self::TechnicalNote => __('Technical Note'),
            self::StudyNote => __('Study Note'),
            self::Glossary => __('Glossary'),
            self::CodeExample => __('Code Example'),
            self::Architecture => __('Architecture'),
            self::Guide => __('Guide'),
            self::CaseStudy => __('Case Study'),
            self::Experiment => __('Experiment'),
        };
    }

    /**
     * Get the one-line description shown beside the format in the console.
     */
    public function description(): string
    {
        return match ($this) {
            self::Article => __('An explanatory piece that argues or describes.'),
            self::Tutorial => __('Steps a reader follows in order to end up with something working.'),
            self::TechnicalNote => __('A short record of a decision, quirk, or finding.'),
            self::StudyNote => __('Condensed material written for revision rather than first reading.'),
            self::Glossary => __('A term and its precise meaning, with links to usage.'),
            self::CodeExample => __('Code-first: the snippet is the content, prose frames it.'),
            self::Architecture => __('How a system is laid out and why the layout is that way.'),
            self::Guide => __('A map of a subject: what exists, where to start, what to skip.'),
            self::CaseStudy => __('A real situation, what was tried, and what happened.'),
            self::Experiment => __('A question, a method, and a measured result.'),
        };
    }

    /**
     * Get the Flux icon name representing the format.
     *
     * Every value here must be a real Heroicons v2 kebab filename; an unknown
     * name throws while the layout is rendering and takes the whole page down.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Article => 'newspaper',
            self::Tutorial => 'bookmark',
            self::TechnicalNote => 'document-text',
            self::StudyNote => 'academic-cap',
            self::Glossary => 'book-open',
            self::CodeExample => 'code-bracket',
            self::Architecture => 'queue-list',
            self::Guide => 'map',
            self::CaseStudy => 'briefcase',
            self::Experiment => 'beaker',
        };
    }

    /**
     * Get every format as a value => label map, for a `<select>` input.
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
}
