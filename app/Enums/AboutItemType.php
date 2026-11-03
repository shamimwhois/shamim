<?php

namespace App\Enums;

/**
 * The row type of an item belonging to an About section.
 *
 * Most sections use flat {@see self::Entry} rows. The Experience section is
 * the exception: its rows form a tree (employer → role → highlight) linked by
 * a self-referential parent id, and this enum is what distinguishes the levels
 * without a JSON column or a table per level.
 *
 * Stored as a string column and cast back to this enum on the model, matching
 * the schema convention used across the application.
 */
enum AboutItemType: string
{
    /** A generic, single-level row (reason, principle, project, stat, …). */
    case Entry = 'entry';

    /** Experience tree: a company/employer node. */
    case Employer = 'employer';

    /** Experience tree: a role within an employer. */
    case Role = 'role';

    /** Experience tree: a highlight bullet within a role. */
    case Highlight = 'highlight';

    /** AI Workflow board: one task card (identifier, intent, stage, agent). */
    case WorkflowTask = 'workflow_task';

    /** AI Workflow graph: one agent node (name, description, slot). */
    case WorkflowAgent = 'workflow_agent';

    /** AI Workflow telemetry: one label/value counter band. */
    case WorkflowMetric = 'workflow_metric';

    /** Thinking Explorer: one thinking mode (System 1 / System 2). */
    case ThinkingMode = 'thinking_mode';

    /** Thinking Explorer: one knowledge category orbiting the brain. */
    case ThinkingCategory = 'thinking_category';

    /** Thinking Explorer: one child concept inside a category's mind map. */
    case ThinkingNode = 'thinking_node';

    /**
     * Get the display label shown in the admin item editor.
     */
    public function label(): string
    {
        return match ($this) {
            self::Entry => __('Entry'),
            self::Employer => __('Employer'),
            self::Role => __('Role'),
            self::Highlight => __('Highlight'),
            self::WorkflowTask => __('Workflow task'),
            self::WorkflowAgent => __('Workflow agent'),
            self::WorkflowMetric => __('Workflow metric'),
            self::ThinkingMode => __('Thinking mode'),
            self::ThinkingCategory => __('Thinking category'),
            self::ThinkingNode => __('Knowledge node'),
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
}
