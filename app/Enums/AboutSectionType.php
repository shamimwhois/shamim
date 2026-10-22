<?php

namespace App\Enums;

use App\Models\AboutSection;

/**
 * The kind of content block an About section renders.
 *
 * The public About page is assembled from these section types rather than
 * hardcoded prose: the service reads the visible sections, groups their items
 * by type, and falls back to config defaults when a section has no rows. The
 * type is stored as a string column (never a JSON blob) so the schema stays
 * queryable and the admin CRUD surface can filter by it.
 *
 * Each case is deliberately a *rendering contract*, not a page position — the
 * ordinal and sort order on {@see AboutSection} decide placement,
 * so a new section of an existing type needs no code change here.
 */
enum AboutSectionType: string
{
    /** Intro identity block: role, tagline, location, availability, bio. */
    case Hero = 'hero';

    /** Headline metrics band (label/value pairs). */
    case Stats = 'stats';

    /** Capability areas shown in the marquee and the skills grid. */
    case Expertise = 'expertise';

    /** Reasons a client should hire the author. */
    case Reasons = 'reasons';

    /** How-I-work focus areas (label/value pairs). */
    case Focus = 'focus';

    /** Working principles list. */
    case Principles = 'principles';

    /** Employment history: employers containing roles containing highlights. */
    case Experience = 'experience';

    /** Selected work / projects. */
    case Work = 'work';

    /** Contact channels and closing call to action. */
    case Contact = 'contact';

    /** AI Workflow dashboard: Kanban tasks, agent graph nodes, pipeline metrics. */
    case AiWorkflow = 'ai_workflow';

    /**
     * Get the display label shown in the admin section picker.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hero => __('Hero / intro'),
            self::Stats => __('Stats band'),
            self::Expertise => __('Capabilities'),
            self::Reasons => __('Why hire me'),
            self::Focus => __('How I work — focus'),
            self::Principles => __('Working principles'),
            self::Experience => __('Experience'),
            self::Work => __('Selected work'),
            self::Contact => __('Contact'),
            self::AiWorkflow => __('AI Workflow'),
        };
    }

    /**
     * Whether this section's items form a nested tree instead of a flat list.
     *
     * Only Experience is hierarchical (employer → role → highlight); every
     * other type renders its items as a single flat collection.
     */
    public function isHierarchical(): bool
    {
        return $this === self::Experience;
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
}
