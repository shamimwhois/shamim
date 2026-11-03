<?php

namespace App\Actions\Feed;

use App\Enums\FeedPostAudience;
use App\Enums\FeedPostStatus;
use App\Models\FeedPost;
use App\Models\User;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

/**
 * Put a composition in front of the community.
 *
 * The publish verb, and the only one of the four writing verbs that decides a
 * post is *finished*. It does not write the content itself — {@see SaveFeedDraft}
 * owns that, so a published row and a drafted row cannot disagree about their
 * slug, their reading time or their byline. What this adds is the three things
 * that only matter at the moment of publication: the guard rails that stop one
 * account from flooding the feed, the claim on media staged for this post, and
 * the publish gate itself.
 *
 * The order is deliberate. Content is written first, so a post that fails the
 * publish grant is left as the draft it was rather than vanishing; media is
 * claimed next, inside the same transaction, so a post is never published with
 * half its images; and the throttle is spent only on a publish that actually
 * happened, so an author who mistypes a poll option does not burn one of their
 * five posts for the minute.
 */
class ComposeFeedPost
{
    /** Session key prefix holding the last published fingerprint per account. */
    private const DUPLICATE_KEY = 'feed.publish.';

    public function __construct(
        protected SaveFeedDraft $saveDraft,
        protected AttachFeedMedia $media,
        protected RateLimiter $limiter,
    ) {}

    /**
     * Publish a new composition, or the author's own resumed draft.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, array $data, ?FeedPost $draft = null, ?string $tempKey = null): FeedPost
    {
        $this->guardRate();
        $this->guardDuplicate($author, $data);

        $post = DB::transaction(function () use ($author, $data, $draft, $tempKey): FeedPost {
            $post = $this->saveDraft->handle($author, $data, $draft);

            if ($tempKey !== null && $tempKey !== '') {
                $this->media->claim($post, $tempKey, $author);
            }

            Gate::forUser($author)->authorize('publish', $post);

            $post->forceFill([
                'status' => FeedPostStatus::Published,
                // The first publish is the moment the community sees it; re-publishing
                // an already-posted thread keeps the original timestamp so the card's
                // "3 days ago" does not reset every time its author edits a typo.
                'posted_at' => $post->posted_at ?? now(),
                'scheduled_at' => null,
            ])->save();

            return $post;
        });

        $this->recordPublication($author, $data);

        return $post;
    }

    /**
     * Refuse the publish that is too soon after the last one.
     *
     * The limit itself is the named `feed-publish` limiter registered by
     * `AppServiceProvider`, because that is where the administrator exemption
     * lives. Reading it back rather than re-deriving the numbers here means this
     * action cannot become the place that quietly lets an admin's rate limit
     * apply, or a member's not apply.
     */
    private function guardRate(): void
    {
        $registered = $this->limiter->limiter('feed-publish');

        if (! $registered instanceof Closure) {
            return;
        }

        /** @var Limit $limit */
        $limit = $registered(request());

        // `Limit::none()` — what the exemption returns — is an Unlimited, and
        // counting against it would apply a limit the provider just removed.
        if ($limit instanceof Unlimited) {
            return;
        }

        if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
            throw ValidationException::withMessages([
                'body' => __('You are posting too quickly. Give it a minute and try again.'),
            ]);
        }
    }

    /**
     * Refuse the second publish of the same text, from the same browser.
     *
     * A double-clicked publish button is the most common way this application
     * gets fed a duplicate, and it looks identical to the author until the feed
     * shows the same post twice. The window is far shorter than the rate limit's
     * minute, because it is guarding an accident rather than an agenda: editing a
     * word and republishing inside ten seconds is still allowed by the limiter,
     * but is no longer a double-submit.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardDuplicate(User $author, array $data): void
    {
        $fingerprint = $this->fingerprint($data);

        if ($fingerprint === null) {
            return;
        }

        /** @var array{hash: string, at: int}|null $previous */
        $previous = $this->sessionStore() ? Session::get($this->sessionKey($author)) : null;

        /** @var int $window */
        $window = (int) config('feed.publishing.duplicate_window_seconds', 10);

        if (is_array($previous) && $previous['hash'] === $fingerprint && (time() - (int) $previous['at']) < $window) {
            throw ValidationException::withMessages([
                'body' => __('That post is already published. Check the feed before sending it again.'),
            ]);
        }
    }

    /**
     * Spend one rate-limit attempt and remember what was just published.
     *
     * Only ever called once the row is durably published. Recording before the
     * transaction would mean a failed publish blocked the author's own retry as a
     * duplicate of a post that does not exist.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordPublication(User $author, array $data): void
    {
        $registered = $this->limiter->limiter('feed-publish');

        if ($registered instanceof Closure) {
            /** @var Limit $limit */
            $limit = $registered(request());

            if (! $limit instanceof Unlimited) {
                $this->limiter->hit($limit->key, $limit->decaySeconds);
            }
        }

        $fingerprint = $this->fingerprint($data);

        if ($fingerprint !== null && $this->sessionStore()) {
            Session::put($this->sessionKey($author), ['hash' => $fingerprint, 'at' => time()]);
        }
    }

    /**
     * A stable digest of the publishing decision, or null when it cannot be taken.
     *
     * Deliberately `hash('sha256')` and not Laravel's `Hash` facade: that is a
     * salted bcrypt hasher, so the same post digested twice produces two different
     * strings and the comparison below could never match. The fingerprint needs a
     * digest that repeats, and it is only ever compared to itself — never used as
     * a secret.
     *
     * @param  array<string, mixed>  $data
     */
    private function fingerprint(array $data): ?string
    {
        $body = trim((string) ($data['body'] ?? ''));

        if ($body === '') {
            return null;
        }

        $audience = $data['audience'] ?? FeedPostAudience::Public;
        $audience = $audience instanceof FeedPostAudience ? $audience->value : (string) $audience;

        return hash('sha256', $body.'|'.$audience.'|'.trim((string) ($data['type'] ?? '')));
    }

    /**
     * The fingerprint is per account and per browser, never shared between them.
     */
    private function sessionKey(User $author): string
    {
        return self::DUPLICATE_KEY.$author->getKey();
    }

    /**
     * Determine whether a session store is available to hold the fingerprint.
     *
     * The composer always runs behind the `web` middleware, but this action is
     * also reachable from a console path where there is no session to write to.
     * Losing the double-click guard is the correct failure there; throwing would
     * be a crash over a convenience.
     */
    private function sessionStore(): bool
    {
        return app()->bound('session.store');
    }
}
