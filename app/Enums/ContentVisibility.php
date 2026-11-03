<?php

namespace App\Enums;

/**
 * Whether a row is readable without an account.
 *
 * The Ideas workspace and the Developer Lab both let an account decide, per row,
 * whether the thing it made stays private or goes on the public site. Two cases
 * are enough: the specification describes a publish switch, not an audience
 * list, and an "unlisted" tier with no sharing mechanism behind it would be a
 * third state nothing could act on.
 *
 * This enum deliberately does **not** carry a `published` case. Publication in
 * this application is the conjunction of this flag with a non-null
 * `published_at`, exactly as `AiArticle::isPublished()` and
 * `MarketplaceProduct::isPublished()` define it, so a row can never be readable
 * by a guest before the moment it was meant to go live.
 */
enum ContentVisibility: string
{
    case Private = 'private';
    case Public = 'public';

    /**
     * Get the display label for the visibility.
     */
    public function label(): string
    {
        return match ($this) {
            self::Private => __('Private'),
            self::Public => __('Public'),
        };
    }

    /**
     * Determine whether this visibility exposes the row to readers.
     */
    public function isPublic(): bool
    {
        return $this === self::Public;
    }

    /**
     * Get the Flux badge colour token for the visibility.
     *
     * Private is the state a visitor cannot reach, so it is the quieter of the
     * two; public is the one that has left the account's control.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Private => 'zinc',
            self::Public => 'green',
        };
    }

    /**
     * Get every visibility as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $visibility) => ['value' => $visibility->value, 'label' => $visibility->label()])
            ->values()
            ->toArray();
    }
}
