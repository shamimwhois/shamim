<?php

namespace App\Enums;

/**
 * Community visibility kinds.
 *
 * `open` communities may be joined by any authenticated account and their
 * feed is public. `invite-only` communities keep their posts and members
 * list behind membership; joining without an invitation is refused by the
 * action class, not merely hidden in the UI.
 */
enum CommunityKind: string
{
    case Open = 'open';
    case InviteOnly = 'invite-only';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::InviteOnly => __('Invite only'),
        };
    }

    /**
     * Get the one-line hint rendered under the membership button.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Open => __('Anyone can join.'),
            self::InviteOnly => __('Membership is by invitation.'),
        };
    }
}
