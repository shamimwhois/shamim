<?php

namespace App\Actions\Feed;

use App\Enums\FeedPostAudience;
use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Rewrite a post that already exists.
 *
 * The content half of an edit is not this class's business — {@see SaveFeedDraft}
 * re-runs the whole derivation (headline, tags, reading time, the JSON structure
 * the card branches on) so an edited post is normalised exactly like a new one.
 * What this owns is the two questions an edit raises that a first write does not.
 *
 * The first is *state*. A published post stays published when its author fixes a
 * typo, and a scheduled post keeps its scheduled minute; an edit that silently
 * reset either would unpublish people's work under their feet.
 *
 * The second is *audience*. Changing `audience` is not editing text, it is
 * changing who is allowed to read it — widening a post from "Only me" to the
 * whole community is a publication act, and an account that merely has the
 * right to correct somebody's post does not thereby acquire the right to share
 * it further than its author intended. That is why a changed audience is checked
 * against `publish`, which is owner-only, while the text itself is checked
 * against `update`, which the wider `*AnyPost` grant satisfies.
 */
class UpdateFeedPost
{
    public function __construct(
        protected SaveFeedDraft $saveDraft,
    ) {}

    /**
     * Apply an edit, keeping the post's publishing state.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $editor, array $data, FeedPost $post): FeedPost
    {
        $this->authorizeAudienceChange($editor, $data, $post);

        return $this->saveDraft->keepingState($editor, $data, $post);
    }

    /**
     * Require the publication grant when the readership is about to change.
     *
     * Comparison is against the value already stored, not against whatever the
     * form happened to submit, so an untouched audience selector is not an
     * audience change. A missing key means the caller is not editing the
     * audience at all.
     *
     * @param  array<string, mixed>  $data
     */
    private function authorizeAudienceChange(User $editor, array $data, FeedPost $post): void
    {
        if (! array_key_exists('audience', $data)) {
            return;
        }

        $requested = $this->requestedAudience($data);

        if ($requested === null || $requested === $post->audience) {
            return;
        }

        Gate::forUser($editor)->authorize('publish', $post);
    }

    /**
     * The audience the caller is asking for, if it named one it understands.
     *
     * An unrecognised value returns null rather than throwing: this method only
     * answers "is the readership changing?", and {@see SaveFeedDraft} is the place
     * that rejects a nonsense audience. Duplicating the complaint here would make
     * two classes owners of the same validation.
     *
     * @param  array<string, mixed>  $data
     */
    private function requestedAudience(array $data): ?FeedPostAudience
    {
        $audience = $data['audience'] ?? null;

        if ($audience instanceof FeedPostAudience) {
            return $audience;
        }

        return is_string($audience) ? FeedPostAudience::tryFrom($audience) : null;
    }
}
