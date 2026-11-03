<?php

namespace App\Enums;

/**
 * Roles a community member can hold.
 *
 * The hierarchy is ascending privilege: Member → Moderator → Owner. Owners
 * may not leave their own community — handing over ownership is a deliberate
 * act outside the join/leave path.
 */
enum CommunityRole: string
{
    case Member = 'member';
    case Moderator = 'moderator';
    case Owner = 'owner';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Member => __('Member'),
            self::Moderator => __('Moderator'),
            self::Owner => __('Owner'),
        };
    }

    /**
     * Determine whether this role is at least as privileged as the given one.
     */
    public function isAtLeast(self $other): bool
    {
        return $this->level() >= $other->level();
    }

    /**
     * Get the hierarchy level: higher means more privilege.
     */
    public function level(): int
    {
        return match ($this) {
            self::Member => 1,
            self::Moderator => 2,
            self::Owner => 3,
        };
    }
}
