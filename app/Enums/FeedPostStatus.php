<?php

namespace App\Enums;

/**
 * Publishing status for a community feed post.
 *
 * `draft` is visible only to its author, inside the composer. `scheduled` is
 * held back until `scheduled_at` arrives, at which point `feed:publish-scheduled`
 * promotes it. `published` is what the feed renders.
 *
 * The status gate lives inside FeedPost::visible(), so a draft can never reach
 * a public surface by a caller forgetting to filter — the scope is the filter.
 */
enum FeedPostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Scheduled => __('Scheduled'),
            self::Published => __('Published'),
        };
    }

    /**
     * Determine whether posts in this status are candidates for the public feed.
     *
     * A scheduled post is not public yet; it becomes one when the clock reaches
     * it, which is the command's job, not the reader's.
     */
    public function isPublished(): bool
    {
        return $this === self::Published;
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
}
