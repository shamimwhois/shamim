<?php

namespace App\Enums;

/**
 * How much prior knowledge a piece of hub content assumes.
 *
 * Difficulty carries an ordinal because the hub uses it comparatively, not just
 * as a filter label: a learning path's lessons are expected to run from lower
 * to higher difficulty, and the article page suggests "the next step up" from
 * what the reader is currently holding. Storing the rank on the enum keeps that
 * comparison in one place instead of a chain of `match` statements at call
 * sites.
 */
enum AiDifficulty: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
    case Expert = 'expert';

    /**
     * Get the display label for the difficulty.
     */
    public function label(): string
    {
        return match ($this) {
            self::Beginner => __('Beginner'),
            self::Intermediate => __('Intermediate'),
            self::Advanced => __('Advanced'),
            self::Expert => __('Expert'),
        };
    }

    /**
     * Get the reader-facing explanation of what the level expects.
     */
    public function description(): string
    {
        return match ($this) {
            self::Beginner => __('No prior knowledge of the subject is assumed.'),
            self::Intermediate => __('Comfortable with the basics; ready for detail.'),
            self::Advanced => __('Uses the subject in practice; goes into how and why.'),
            self::Expert => __('Assumes production experience; discusses trade-offs.'),
        };
    }

    /**
     * Get the position of the level on the learning ladder, lowest first.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Beginner => 1,
            self::Intermediate => 2,
            self::Advanced => 3,
            self::Expert => 4,
        };
    }

    /**
     * Get the next level up, or null when already at the top.
     */
    public function next(): ?self
    {
        return self::fromRank($this->rank() + 1);
    }

    /**
     * Resolve a difficulty from its rank, returning null outside the ladder.
     */
    public static function fromRank(int $rank): ?self
    {
        return collect(self::cases())
            ->first(fn (self $difficulty) => $difficulty->rank() === $rank);
    }

    /**
     * Get every difficulty as a value => label map, for a `<select>` input.
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
