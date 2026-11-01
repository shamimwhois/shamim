<?php

namespace App\Enums;

/**
 * Who a community feed post is visible to.
 *
 * Three options that the application can actually honour today: the open web,
 * the author alone, and a community the author belongs to. A "followers" or
 * "custom" audience is deliberately absent — the platform has no follow graph,
 * and an option that silently means "public" is a privacy lie, not a placeholder.
 */
enum FeedPostAudience: string
{
    case Public = 'public';
    case Self = 'self';
    case Community = 'community';

    /**
     * Get the display label for the audience.
     */
    public function label(): string
    {
        return match ($this) {
            self::Public => __('Public'),
            self::Self => __('Only me'),
            self::Community => __('Private community'),
        };
    }

    /**
     * Get the one-line hint rendered under the audience option.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Public => __('Anyone can see this post, including readers who are signed out.'),
            self::Self => __('Only you can see this post.'),
            self::Community => __('Members of the chosen community can see this post.'),
        };
    }

    /**
     * Get every audience as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $audience) => ['value' => $audience->value, 'label' => $audience->label()])
            ->values()
            ->toArray();
    }
}
