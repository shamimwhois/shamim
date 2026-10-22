<?php

namespace App\Actions\Communities;

use App\Enums\CommunityKind;
use App\Enums\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/**
 * Join or leave a community — the one toggle behind every membership button.
 *
 * Rules the database also enforces:
 *
 * - Invite-only communities refuse outsiders joining; the refusal is a 403
 *   from the action, not a hidden button.
 * - The (community_id, user_id) unique index is the race-condition backstop:
 *   two simultaneous joins produce one row.
 * - An owner may not leave their own community. Handing over ownership is a
 *   deliberate act outside the join/leave path.
 */
class ToggleCommunityMembership
{
    public function handle(User $user, Community $community): ?CommunityMembership
    {
        $membership = $community->membershipOf($user);

        if ($membership !== null) {
            if ($membership->role->isAtLeast(CommunityRole::Owner)) {
                throw new UnauthorizedException(__('Community owners cannot leave their own community.'));
            }

            $membership->delete();

            return null;
        }

        if ($community->kind === CommunityKind::InviteOnly) {
            throw new UnauthorizedException(__('Membership of this community is by invitation only.'));
        }

        return $this->join($user, $community);
    }

    /**
     * Join the community, racing safely against the unique index.
     */
    private function join(User $user, Community $community): CommunityMembership
    {
        return DB::transaction(function () use ($user, $community): CommunityMembership {
            try {
                return CommunityMembership::query()->create([
                    'community_id' => $community->id,
                    'user_id' => $user->id,
                    // Written rather than left to the column default: the caller
                    // gets this object back, and a role of null on a row the
                    // database says is a member is the model disagreeing with the
                    // table about what it just stored.
                    'role' => CommunityRole::Member,
                    'joined_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request created the membership first; the
                // account is a member either way.
                /** @var CommunityMembership $membership */
                $membership = CommunityMembership::query()
                    ->where('community_id', $community->id)
                    ->where('user_id', $user->id)
                    ->firstOrFail();

                return $membership;
            }
        });
    }
}
