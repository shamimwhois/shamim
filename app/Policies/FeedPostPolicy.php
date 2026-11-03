<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\User;

/**
 * Access rules for community feed posts.
 *
 * Two axes decide here, and they are deliberately not the same question:
 * *readability* is the model's own visibility rule (publish gate plus audience),
 * while *authoring* is ownership backed by an account permission. A published
 * post is readable by a guest and still not editable by them.
 *
 * The `*AnyPost` permissions are what an administrator holds (they are the only
 * role above `Seller` in `AccountRole`), so such an account acts on rows it does
 * not own — including the curated corpus rows, which have no author account at
 * all and are therefore untouchable by anyone without such a grant.
 */
class FeedPostPolicy
{
    /**
     * Determine whether the reader may open the feed at all.
     *
     * The feed is a public surface: signing in changes which cards are visible,
     * never whether the page exists.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the reader may see this particular post.
     *
     * Delegation, not duplication: `isVisibleTo()` is the one visibility rule
     * the stream filters and the detail page both consult, so a post can never
     * be hidden in the feed and still reachable by its slug.
     */
    public function view(?User $user, FeedPost $post): bool
    {
        return $post->isVisibleTo($user);
    }

    /**
     * Determine whether the account may compose a feed post.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(AccountPermission::CreatePost);
    }

    /**
     * Determine whether the account may rewrite the post.
     */
    public function update(User $user, FeedPost $post): bool
    {
        if ($user->hasPermission(AccountPermission::UpdateAnyPost)) {
            return true;
        }

        return $this->owns($user, $post) && $user->hasPermission(AccountPermission::UpdateOwnPost);
    }

    /**
     * Determine whether the account may remove the post.
     */
    public function delete(User $user, FeedPost $post): bool
    {
        if ($user->hasPermission(AccountPermission::DeleteAnyPost)) {
            return true;
        }

        return $this->owns($user, $post) && $user->hasPermission(AccountPermission::DeleteOwnPost);
    }

    /**
     * Determine whether the account may publish or un-publish the post.
     *
     * Publishing is only ever an act on your own words — a moderator who needs
     * to publish someone else's post acts through `update`, under the grant that
     * lets them rewrite it, rather than pushing an unpublished draft live
     * uninvited.
     *
     * The permission test is `PublishPost` **or** `CreatePost`, and the reason is
     * the role matrix: `AccountRole::User` grants `CreatePost` but reserves
     * `PublishPost` for the blog workflow, so reading `PublishPost` here would
     * forbid every ordinary member from ever publishing a feed composition. A
     * member who is allowed to compose a post at all is allowed to publish it.
     */
    public function publish(User $user, FeedPost $post): bool
    {
        return $this->owns($user, $post)
            && $user->hasAnyPermission(AccountPermission::PublishPost, AccountPermission::CreatePost);
    }

    /**
     * Determine whether the reader may stream the bytes of an attachment.
     *
     * Attached media inherits its post's visibility exactly: a signed URL is a
     * transport permission, never an authorisation. A file that is not the one
     * attached to the post named in the request, or whose reader has fallen out
     * of that post's audience, does not load.
     *
     * The post is nullable because a composer stages its images *before* the row
     * exists, and the modal has to show them or the author is picking files blind.
     * Such a file is previewable by exactly one account — the one that uploaded it,
     * while it is still unclaimed. That is not a loophole around the visibility
     * rule; a staged file has no post to be visible to yet, and the uploader is
     * the only party with any claim on it.
     *
     * The same argument applies one step later, when an author resumes a saved
     * draft: its media is claimed onto a row whose status is still `Draft`, which
     * `isVisibleTo()` refuses everybody — including the person writing it. So
     * ownership is a second, narrower key on the attached branch. It never widens
     * what a *reader* may see; it only lets an author see their own unpublished
     * work, which is the entire point of a draft.
     */
    public function viewAttachment(?User $user, ?FeedPost $post, FeedAttachment $attachment): bool
    {
        if ($post === null) {
            return $user !== null
                && $attachment->feed_post_id === null
                && $attachment->temp_key !== null
                && $attachment->user_id === $user->id;
        }

        if ($attachment->feed_post_id !== $post->id) {
            return false;
        }

        return $this->view($user, $post)
            || ($user !== null && $this->owns($user, $post));
    }

    /**
     * Determine whether the account may retire an attachment.
     *
     * Reading and removing are different questions, and the composer used to
     * conflate them: `viewAttachment` is a *streaming* permission, and anyone
     * entitled to see a published post's picture is entitled to keep seeing it
     * — not to delete it. Removing media is an edit of somebody's work, so it
     * travels the authoring axis instead: the writer of the post, or a
     * moderator with the rewrite-everything grant.
     *
     * An unclaimed staging row has no post to edit yet, and its only party is
     * the account that uploaded it — which is the same answer
     * {@see viewAttachment()} gives that narrow case, for the same reason.
     */
    public function removeAttachment(?User $user, ?FeedPost $post, FeedAttachment $attachment): bool
    {
        if ($user === null) {
            return false;
        }

        if ($post === null) {
            return $attachment->feed_post_id === null
                && $attachment->temp_key !== null
                && $attachment->user_id === $user->id;
        }

        if ($attachment->feed_post_id !== $post->id) {
            return false;
        }

        return $this->update($user, $post);
    }

    /**
     * Determine whether the account owns the post.
     *
     * A post with no `user_id` is owned by nobody, which is what keeps the
     * curated corpus out of every member's "my posts" reach.
     */
    private function owns(User $user, FeedPost $post): bool
    {
        return $post->user_id !== null && $user->id === $post->user_id;
    }
}
