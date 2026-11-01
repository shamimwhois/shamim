<?php

namespace App\Actions\Feed;

use App\Enums\FeedPostAudience;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Decide who a post will be visible to, and refuse the answers that cannot hold.
 *
 * The audience selector in the composer is client-side convenience; this is the
 * server's authority. The rule it enforces is the one the rest of the application
 * cannot enforce for you: a `Community` audience is a promise to a specific
 * group's members, so the author must actually be in that group. Everything the
 * audience needs is settled here and returned as one pair, so no writer of a
 * post can set `audience` without also setting a `community_id` that agrees with
 * it — the two columns either describe one real sharing decision or the write
 * never happens.
 */
class ResolveFeedAudience
{
    /**
     * Resolve and validate the audience for an author.
     *
     * @return array{audience: FeedPostAudience, community_id: int|null}
     */
    public function handle(User $author, mixed $audience, mixed $communityId = null): array
    {
        $audience = $this->coerce($audience);

        // Only a community audience can carry a community. The others clear it,
        // so a post that was shared to a group and then widened to Public does
        // not keep a stale id that the next edit would silently re-honour.
        if ($audience !== FeedPostAudience::Community) {
            return ['audience' => $audience, 'community_id' => null];
        }

        return ['audience' => $audience, 'community_id' => $this->joinedCommunity($author, $communityId)];
    }

    /**
     * Turn the submitted value into an audience, or say why it is not one.
     */
    private function coerce(mixed $audience): FeedPostAudience
    {
        if ($audience instanceof FeedPostAudience) {
            return $audience;
        }

        $resolved = FeedPostAudience::tryFrom((string) $audience);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'audience' => __('Choose who can see this post.'),
            ]);
        }

        return $resolved;
    }

    /**
     * The community the author belongs to, validated against their membership.
     *
     * `joinedCommunityIds()` is one memoised query that proves both halves of the
     * question at once — that the community exists and that the author is in it.
     * A value that is not on that list is either a group that has since been
     * deleted or one the author never joined, and the refusal deliberately does
     * not distinguish them: telling someone which private communities exist is
     * not information the composer needs to do its job.
     */
    private function joinedCommunity(User $author, mixed $communityId): int
    {
        $communityId = filter_var($communityId, FILTER_VALIDATE_INT);

        if ($communityId === false) {
            throw ValidationException::withMessages([
                'community_id' => __('Choose the community to share this post with.'),
            ]);
        }

        if (! in_array($communityId, $author->joinedCommunityIds(), true)) {
            throw ValidationException::withMessages([
                'community_id' => __('You can only share a post into a community you have joined.'),
            ]);
        }

        return $communityId;
    }
}
