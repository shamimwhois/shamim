<?php

use App\Actions\Communities\ToggleCommunityMembership;
use App\Enums\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/*
|--------------------------------------------------------------------------
| Joining and leaving a community
|--------------------------------------------------------------------------
|
| The toggle is the only write behind every membership button on the directory
| and the feed, and its two refusals are enforced here rather than by hiding a
| button: an owner handing a community over is a deliberate act that the join
| path does not implement, and an invite-only community is a door that has to
| say no even to a request that came from the page which offered the button.
|
| The last case is the one a straightforward test cannot reach, because it needs
| two requests to arrive at the same time. It is reproduced by letting a
| competing row land between the action's read and its insert, which is exactly
| what the unique index exists to survive.
|
*/

function toggle(User $user, Community $community): ?CommunityMembership
{
    return app(ToggleCommunityMembership::class)->handle($user, $community);
}

it('joins an open community and writes the hour the account arrived', function (): void {
    $user = User::factory()->create();
    $community = Community::factory()->create();

    $membership = toggle($user, $community);

    expect($membership)->not->toBeNull()
        ->and(CommunityMembership::query()->count())->toBe(1)
        ->and($membership->user_id)->toBe($user->id)
        ->and($membership->community_id)->toBe($community->id)
        // Read off the object the action returned, not off a fresh query: a joiner
        // handed a row whose role is null cannot tell whether they are a member.
        ->and($membership->role)->toBe(CommunityRole::Member)
        ->and($membership->fresh()->role)->toBe(CommunityRole::Member)
        ->and($membership->joined_at)->not->toBeNull();
});

it('leaves again on the second toggle, taking the row with it', function (): void {
    $user = User::factory()->create();
    $community = Community::factory()->create();
    $membership = toggle($user, $community);

    expect(toggle($user, $community->fresh()))->toBeNull()
        ->and(CommunityMembership::query()->whereKey($membership?->id)->exists())->toBeFalse();

    // A third press joins: the toggle is a toggle, not a one-way leave.
    expect(toggle($user, $community->fresh()))->not->toBeNull();
});

it('refuses to let an owner walk away from their own community', function (): void {
    $owner = User::factory()->create();
    $community = Community::factory()->create();
    CommunityMembership::factory()->in($community)->heldBy($owner)->owner()->create();

    expect(fn () => toggle($owner, $community))
        ->toThrow(UnauthorizedException::class, 'cannot leave their own community');

    // The refusal has to be a refusal all the way down: the row stays, so the
    // account is still the owner rather than a community with none.
    expect(CommunityMembership::query()->count())->toBe(1)
        ->and($community->fresh()->ownerMembership()?->user_id)->toBe($owner->id);
});

it('lets a moderator leave, because a moderator is not the owner', function (): void {
    $moderator = User::factory()->create();
    $community = Community::factory()->create();
    $membership = CommunityMembership::factory()->in($community)->heldBy($moderator)->moderator()->create();

    expect(toggle($moderator, $community))->toBeNull()
        ->and(CommunityMembership::query()->whereKey($membership->id)->exists())->toBeFalse();
});

it('refuses a stranger an invite-only community and creates nothing', function (): void {
    $user = User::factory()->create();
    $community = Community::factory()->inviteOnly()->create();

    expect(fn () => toggle($user, $community))
        ->toThrow(UnauthorizedException::class, 'by invitation only');

    expect(CommunityMembership::query()->count())->toBe(0);
});

it('still admits someone an invite-only community already let in', function (): void {
    $member = User::factory()->create();
    $community = Community::factory()->inviteOnly()->create();
    CommunityMembership::factory()->in($community)->heldBy($member)->create();

    // Leaving is never blocked by the kind of the community: the gate is on
    // arriving, and an account cannot be held in a place it was admitted to.
    expect(toggle($member, $community))->toBeNull()
        ->and(CommunityMembership::query()->count())->toBe(0);
});

it('returns the row a concurrent join already wrote rather than failing twice', function (): void {
    $user = User::factory()->create();
    $community = Community::factory()->create();

    // The competing request lands between the action's read and its insert, which
    // makes the second insert collide with the unique index for real. The action
    // treats that as success, because the account is a member either way.
    CommunityMembership::creating(function () use ($community, $user): void {
        DB::table('community_memberships')->insert([
            'community_id' => $community->id,
            'user_id' => $user->id,
            'role' => CommunityRole::Member->value,
            'joined_at' => now(),
        ]);
    });

    $membership = toggle($user, $community);

    expect($membership)->not->toBeNull()
        ->and(CommunityMembership::query()->count())->toBe(1)
        ->and($membership->user_id)->toBe($user->id);
});
