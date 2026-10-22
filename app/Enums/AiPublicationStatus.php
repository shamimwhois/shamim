<?php

namespace App\Enums;

/**
 * The publication workflow state of a piece of AI Knowledge Hub content.
 *
 * The hub deliberately has one more step than the blog. Knowledge articles are
 * reviewed for technical accuracy before they are taught from, so `Review`
 * exists as a state that is explicitly *not* public, and the spec requirement
 * "new content must remain a draft until explicitly published" is enforced by
 * {@see self::allowedTransitions()} rather than by a convention nobody reads.
 *
 * Transitions are declared here instead of in each Action so that articles,
 * learning paths, and resources all obey one workflow, and a later change to
 * the workflow is a change in exactly one place.
 */
enum AiPublicationStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Review => __('In Review'),
            self::Published => __('Published'),
            self::Archived => __('Archived'),
        };
    }

    /**
     * Determine whether content in this status is visible on the public site.
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /**
     * Determine whether content in this status may be edited without care.
     *
     * Archived content is kept for reference; the console treats it as
     * read-mostly so an operator cannot quietly rewrite published teaching
     * material that a learning path still points at.
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
            self::Draft => 'amber',
            self::Review => 'sky',
            self::Published => 'green',
            self::Archived => 'zinc',
        };
    }

    /**
     * Get the statuses that may follow this one.
     *
     * A published item can be pulled back to draft or archived; a draft can go
     * to review or straight to published (the hub is single-team, so review is
     * optional); nothing escapes archive except by an explicit re-draft.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Review, self::Published, self::Archived],
            self::Review => [self::Draft, self::Published, self::Archived],
            self::Published => [self::Draft, self::Archived],
            self::Archived => [self::Draft],
        };
    }

    /**
     * Determine whether a move from this status to another is permitted.
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
     * Determine whether this status may be used by a container (path, resource).
     *
     * The restriction is enforced server-side rather than only by hiding the
     * option, because a console can be driven by a request the browser never
     * built.
     */
    public function isContainerStatus(): bool
    {
        return in_array($this, self::consoleCases(), true);
    }

    /**
     * Get the statuses offered for supporting content (paths, resources).
     *
     * Learning paths and resources are containers, not documents under
     * technical review, so the console hides `Review` from them. The status is
     * still stored in the same column with the same enum — this is a display
     * restriction, not a second workflow.
     *
     * @return array<int, self>
     */
    public static function consoleCases(): array
    {
        return [self::Draft, self::Published, self::Archived];
    }

    /**
     * Get the supporting-content statuses as a value/label pair.
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
