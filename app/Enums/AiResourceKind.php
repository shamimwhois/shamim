<?php

namespace App\Enums;

/**
 * What kind of external thing a hub resource is.
 *
 * The resources shelf is the only part of the hub that points outside the app,
 * so the value of each entry comes from being able to say what it *is* before
 * the reader clicks: a tool they might install, a course they might commit time
 * to, or a paper they might cite. The kind also drives the helper text that
 * reminds readers the hub does not control third-party content.
 */
enum AiResourceKind: string
{
    case Tool = 'tool';
    case Library = 'library';
    case Dataset = 'dataset';
    case Course = 'course';
    case Paper = 'paper';
    case Video = 'video';
    case Documentation = 'documentation';

    /**
     * Get the display label for the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Tool => __('Tool'),
            self::Library => __('Library'),
            self::Dataset => __('Dataset'),
            self::Course => __('Course'),
            self::Paper => __('Paper'),
            self::Video => __('Video'),
            self::Documentation => __('Documentation'),
        };
    }

    /**
     * Get the short note shown beside the resource in the console.
     */
    public function description(): string
    {
        return match ($this) {
            self::Tool => __('Something the reader runs — an app, CLI, or service.'),
            self::Library => __('Code the reader imports into a project.'),
            self::Dataset => __('Data the reader can download and experiment with.'),
            self::Course => __('Structured teaching that costs the reader real time.'),
            self::Paper => __('A published piece of research.'),
            self::Video => __('Recorded or live media.'),
            self::Documentation => __('Reference material maintained by the tool\'s authors.'),
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
            self::Tool => 'wrench',
            self::Library => 'square-2-stack',
            self::Dataset => 'table-cells',
            self::Course => 'academic-cap',
            self::Paper => 'document-text',
            self::Video => 'play-circle',
            self::Documentation => 'book-open',
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
