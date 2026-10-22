<?php

namespace App\Enums;

/**
 * How far a plan has travelled from "we should do this" to "it is done".
 *
 * These are the seven stages `docs/Idea.md` names for Module B's planning object.
 * They describe commitment rather than editorial review: `Draft` means somebody is
 * still writing, `Ready` means the work is agreed but unstarted, `InProgress` means
 * it is being paid for, and `Completed` and `Cancelled` are the two ways a plan
 * legitimately stops needing attention.
 *
 * Transitions are declared here for the same reason `IdeaStatus` declares its own:
 * the plan screen, the conversion action, and the console should all obey one
 * workflow, and a later change to it should be a change in exactly one place.
 */
enum PlanStatus: string
{
    case Draft = 'draft';
    case Planning = 'planning';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Planning => __('Planning'),
            self::Ready => __('Ready'),
            self::InProgress => __('In Progress'),
            self::OnHold => __('On Hold'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * Get the Flux badge colour token for the status.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Planning => 'sky',
            self::Ready => 'amber',
            self::InProgress => 'teal',
            self::OnHold => 'slate',
            self::Completed => 'green',
            self::Cancelled => 'red',
        };
    }

    /**
     * Determine whether the plan still wants attention.
     *
     * A completed or cancelled plan is a record. It stays readable and searchable,
     * but it leaves the default list so the workspace opens on live work.
     */
    public function isOpen(): bool
    {
        return $this !== self::Completed && $this !== self::Cancelled;
    }

    /**
     * Get the statuses that may follow this one.
     *
     * Forward motion is a staircase; the only way back from a finished plan is to
     * cancel it, and the only way back from cancellation is to start over as a
     * draft, which is what makes `Completed` and `Cancelled` honest endings.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Planning, self::Ready, self::Cancelled],
            self::Planning => [self::Ready, self::InProgress, self::Cancelled],
            self::Ready => [self::InProgress, self::OnHold, self::Cancelled],
            self::InProgress => [self::OnHold, self::Completed, self::Cancelled],
            self::OnHold => [self::InProgress, self::Cancelled],
            self::Completed => [],
            self::Cancelled => [self::Draft],
        };
    }

    /**
     * Determine whether a move from this status to another is permitted.
     *
     * Staying put counts as permitted, because the edit form submits the status it
     * opened with on every save and that is not a transition.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true) || $target === $this;
    }

    /**
     * Get every status as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()])
            ->values()
            ->toArray();
    }

    /**
     * Get the statuses in the order a screen should offer them.
     *
     * Workflow order rather than alphabetical: a plan list reads as a pipeline.
     *
     * @return array<int, self>
     */
    public static function consoleCases(): array
    {
        return [
            self::Draft,
            self::Planning,
            self::Ready,
            self::InProgress,
            self::OnHold,
            self::Completed,
            self::Cancelled,
        ];
    }
}
