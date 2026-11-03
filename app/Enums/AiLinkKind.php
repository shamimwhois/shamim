<?php

namespace App\Enums;

/**
 * What an external link attached to a hub article actually points at.
 *
 * A bare URL list is weak content: the reader cannot tell a citation from a
 * sales page. Labelling each link with its kind lets the article page group
 * references separately from code and papers, and lets the console warn when a
 * "repository" link goes to a domain that is not a code host — a check that is
 * only possible because the intent is recorded rather than inferred.
 */
enum AiLinkKind: string
{
    case Reference = 'reference';
    case Documentation = 'documentation';
    case Repository = 'repository';
    case Paper = 'paper';
    case Demo = 'demo';

    /**
     * Get the display label for the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Reference => __('Reference'),
            self::Documentation => __('Documentation'),
            self::Repository => __('Repository'),
            self::Paper => __('Paper'),
            self::Demo => __('Demo'),
        };
    }

    /**
     * Get the short note shown under the link field in the console.
     */
    public function description(): string
    {
        return match ($this) {
            self::Reference => __('A specification, article, or page the content depends on.'),
            self::Documentation => __('Official docs for a tool or library named in the content.'),
            self::Repository => __('Source code — the reader can run what the page describes.'),
            self::Paper => __('A research paper or preprint.'),
            self::Demo => __('A hosted example, playground, or interactive model.'),
        };
    }

    /**
     * Get the Flux icon name representing the kind.
     *
     * Values must be real Heroicons v2 kebab filenames.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Reference => 'link',
            self::Documentation => 'book-open',
            self::Repository => 'code-bracket',
            self::Paper => 'document-text',
            self::Demo => 'play-circle',
        };
    }

    /**
     * Get every kind as a value => label map, for a `<select>` input.
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
