<?php

namespace App\Enums;

/**
 * The thinking stage an idea has reached.
 *
 * These are the five states `docs/Idea.md` names for Module B. They describe an
 * idea rather than a document under editorial review: `Inbox` is the capture
 * state an account lands in, `Approved` is the only one that may be published or
 * converted into a plan, and `Archived` is a deliberate dead end that an
 * explicitly permitted restore is the only way out of.
 *
 * Transitions are declared here so that the create screen, the workspace, the
 * conversion action and the admin console all obey one workflow, and a later
 * change to it is a change in exactly one place.
 */
enum IdeaStatus: string
{
    case Inbox = 'inbox';
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Archived = 'archived';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Inbox => __('Inbox'),
            self::Draft => __('Draft'),
            self::UnderReview => __('Under Review'),
            self::Approved => __('Approved'),
            self::Archived => __('Archived'),
        };
    }

    /**
     * Determine whether an idea in this status may be published or converted.
     *
     * The gate is on the status rather than on the publish checkbox so that an
     * idea cannot reach the public site while it is still an unread inbox entry.
     */
    public function isPublishable(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Determine whether an idea in this status is finished thinking.
     *
     * The console treats archived work as read-mostly so an operator cannot
     * quietly rewrite something the account has deliberately closed.
     */
    public function isLocked(): bool
    {
        return $this === self::Archived;
    }

    /**
     * Get the Flux badge colour token for the status.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Inbox => 'zinc',
            self::Draft => 'amber',
            self::UnderReview => 'sky',
            self::Approved => 'green',
            self::Archived => 'slate',
        };
    }

    /**
     * Get the statuses that may follow this one.
     *
     * Capture moves forward to draft or straight to review; review can be
     * rejected back to draft or accepted; anything may be archived, and an
     * archived idea returns only to the inbox, which is the specification's
     * "restore archived ideas when appropriate".
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Inbox => [self::Draft, self::UnderReview, self::Archived],
            self::Draft => [self::UnderReview, self::Approved, self::Archived],
            self::UnderReview => [self::Draft, self::Approved, self::Archived],
            self::Approved => [self::UnderReview, self::Archived],
            self::Archived => [self::Inbox],
        };
    }

    /**
     * Determine whether a move from this status to another is permitted.
     *
     * Staying where one is counts as permitted: the edit form submits the status
     * it was opened with every time it saves, and that is not a transition.
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
     * Get the statuses in the order a console should offer them.
     *
     * This is workflow order rather than alphabetical order: an operator reading
     * a queue wants capture first and closed last.
     *
     * @return array<int, self>
     */
    public static function consoleCases(): array
    {
        return [self::Inbox, self::Draft, self::UnderReview, self::Approved, self::Archived];
    }

    /**
     * Get the console statuses as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function consoleOptions(): array
    {
        return collect(self::consoleCases())
            ->map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()])
            ->values()
            ->toArray();
    }
}
