<?php

namespace App\Actions\Feed;

use App\Enums\FeedPostAudience;
use App\Enums\FeedPostStatus;
use App\Models\FeedPost;
use App\Models\User;
use App\Support\FeedPersonas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Land a composed post in the database.
 *
 * This is the only place a feed row is written, and that is the point. A feed
 * post has eleven columns whose values nobody typed — a slug that must be unique,
 * a headline derived from the first line, a reading time, tags, the denormalised
 * identity a card renders, and the three JSON structures that decide which card
 * variant shows. If publish, schedule, draft and edit each assembled those on
 * their own, they would disagree within a week, and the disagreement would be
 * visible on the public feed. So {@see ComposeFeedPost()}, {@see ScheduleFeedPost()}
 * and {@see UpdateFeedPost()} all call this and then change only the publishing
 * state.
 *
 * The state itself is a parameter, defaulting to `Draft`: writing content and
 * deciding who may read it are separate questions, and answering the first
 * should never silently answer the second. Publishing is {@see ComposeFeedPost()}
 * acting on the row this returns.
 *
 * Authorization happens here rather than at the call sites, unlike
 * `App\Actions\Posts\*`, because this row is reachable from four verbs and a
 * forgotten `authorize()` in one of them is an authorization bug, not a crash.
 */
class SaveFeedDraft
{
    public function __construct(
        protected HydrateFeedPostContent $hydrateContent,
        protected ResolveFeedAudience $resolveAudience,
    ) {}

    /**
     * Write the post as a draft, creating the row when it does not exist yet.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, array $data, ?FeedPost $post = null): FeedPost
    {
        return $this->write($author, $data, $post, FeedPostStatus::Draft);
    }

    /**
     * Write the post's content and keep whatever publishing state it already had.
     *
     * @param  array<string, mixed>  $data
     */
    public function keepingState(User $author, array $data, FeedPost $post): FeedPost
    {
        return $this->write($author, $data, $post, $post->status);
    }

    /**
     * Authorize, validate the transition, then write.
     *
     * @param  array<string, mixed>  $data
     */
    private function write(User $author, array $data, ?FeedPost $post, FeedPostStatus $status): FeedPost
    {
        // Authorised against `$author`, not against whoever happens to be
        // authenticated, so the account the row is written for and the account the
        // permission was checked on can never drift apart.
        $post === null
            ? Gate::forUser($author)->authorize('create', FeedPost::class)
            : Gate::forUser($author)->authorize('update', $post);

        $this->assertWritable($post, $status);

        return DB::transaction(fn (): FeedPost => $this->store($author, $data, $post, $status));
    }

    /**
     * Refuse the writes that would quietly undo someone else's decision.
     */
    private function assertWritable(?FeedPost $post, FeedPostStatus $status): void
    {
        if ($post === null) {
            return;
        }

        if ($post->deleted) {
            throw ValidationException::withMessages([
                'body' => __('This post has been removed and can no longer be edited.'),
            ]);
        }

        // Drafting over a live post is an unpublish, and no part of the composer
        // offers that. `Unpublish` would be its own verb with its own grant.
        if ($status === FeedPostStatus::Draft && $post->status === FeedPostStatus::Published) {
            throw ValidationException::withMessages([
                'body' => __('This post is already published. Edit it instead of saving a draft.'),
            ]);
        }
    }

    /**
     * Assemble every column the row needs and save it.
     *
     * @param  array<string, mixed>  $data
     */
    private function store(User $author, array $data, ?FeedPost $post, FeedPostStatus $status): FeedPost
    {
        $content = $this->hydrateContent->handle([
            'body' => $data['body'] ?? '',
            'title' => $data['title'] ?? null,
            'type' => $data['type'] ?? null,
            'topic' => $data['topic'] ?? null,
            'tags' => $data['tags'] ?? [],
        ]);

        $audience = $this->resolveAudience->handle(
            $author,
            $data['audience'] ?? FeedPostAudience::Public,
            $data['community_id'] ?? null,
        );

        // A post being written over keeps the reactions it already earned;
        // a new one starts at zero, which is what the cards render.
        $reactions = $post !== null ? $post->reactions : null;

        $attributes = [
            ...$content,
            ...$this->structure((string) $content['type'], $data),
            ...$this->identity($author),
            'user_id' => $author->id,
            'status' => $status,
            'audience' => $audience['audience'],
            'community_id' => $audience['community_id'],
            'reactions' => $reactions ?? ['like' => 0, 'love' => 0, 'insight' => 0],
        ];

        // A draft has no due time. Clearing it here is what keeps a draft resumed
        // from a cancelled schedule from publishing itself the moment its old
        // minute passes.
        if ($status === FeedPostStatus::Draft) {
            $attributes['scheduled_at'] = null;
        }

        $detail = $this->aiLabel($post, $data);

        if ($detail !== null) {
            $attributes['detail'] = $detail;
        }

        $post ??= new FeedPost;

        // The slug is the public URL, so it is minted once and never recomputed:
        // renaming a headline must not 404 everyone who already linked the post.
        if (! $post->exists) {
            $attributes['slug'] = FeedPost::generateUniqueSlug(
                $content['title'] !== '' ? (string) $content['title'] : (string) $content['body'],
            );
        }

        $post->fill($attributes)->save();

        return $post;
    }

    /**
     * The type-specific structures a card renders, and the ones it must stop
     * rendering.
     *
     * The card branches on column *presence* — a poll bar appears because `poll`
     * is set, a code panel because `code` is — not on `type`. So changing a
     * post's type has to clear the structure the new type does not use, or an
     * edited post keeps showing a poll that is no longer part of it.
     *
     * @param  array<string, mixed>  $data
     * @return array{code: string|null, code_file: string|null, poll: array<string, mixed>|null, activity: array{label: string, detail: string}|null}
     */
    private function structure(string $type, array $data): array
    {
        $isSnippet = $type === 'snippet';
        $isPoll = $type === 'poll';

        return [
            'code' => $isSnippet ? $this->text($data['code'] ?? null) : null,
            'code_file' => $isSnippet ? $this->label($data['code_file'] ?? null) : null,
            'poll' => $isPoll ? $this->poll($data['poll'] ?? null) : null,
            'activity' => $this->activity($data['activity'] ?? null),
        ];
    }

    /**
     * The author identity the card prints, snapshotted from the account.
     *
     * `author`, `handle`, `avatar` and `role` are denormalised on purpose — the
     * feed is one query per render and a card that eager-loaded a user to print
     * a name would break it. So the snapshot is taken at write time and is the
     * rendered truth for that post forever, which is what a signed-in reader sees.
     *
     * The one field an account does not carry is the byline: `role` on a card is
     * "Design engineer", not an account tier. When the author matches a persona in
     * {@see FeedPersonas()} that registry byline is used, so a seeded persona's
     * posts read exactly like the curated corpus posts by the same author;
     * otherwise the honest value is the account's own role.
     *
     * @return array{author: string, handle: string, avatar: string, role: string}
     */
    private function identity(User $author): array
    {
        $persona = FeedPersonas::findByHandle($author->handle);
        $handle = $author->handle ?? Str::slug(Str::before($author->email, '@'));
        $byline = $persona === null ? $author->accountRole()->label() : $persona['role'];

        return [
            'author' => $author->name,
            'handle' => FeedPersonas::displayedHandle((string) $handle),
            'avatar' => $this->initials($author->name),
            'role' => $byline,
        ];
    }

    /**
     * At most two initials, the shape the avatar disc expects.
     */
    private function initials(string $name): string
    {
        /** @var array<int, string> $parts */
        $parts = preg_split('/[\s\-\/]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= Str::upper(Str::substr($part, 0, 1));
        }

        return $initials === '' ? '?' : $initials;
    }

    /**
     * Shape and check a poll, or refuse to store one that cannot be voted on.
     *
     * Two distinct options is the minimum that makes a poll a poll: the card
     * renders bars, and one bar is a statement. Votes are re-summed from the
     * options rather than trusted from the client, so the total the card prints
     * always agrees with the bars it draws.
     *
     * @return array{options: array<int, array{label: string, votes: int}>, votes: int, closes: string}|null
     */
    private function poll(mixed $poll): ?array
    {
        if (! is_array($poll)) {
            return null;
        }

        /** @var int $minimum */
        $minimum = (int) config('feed.composer.poll_min_options', 2);
        /** @var int $maximum */
        $maximum = (int) config('feed.composer.poll_max_options', 6);

        /** @var array<int, array{label: string, votes: int}> $options */
        $options = [];

        foreach ($poll['options'] ?? [] as $option) {
            $label = $this->label(is_array($option) ? ($option['label'] ?? null) : $option);

            if ($label === null || in_array($label, array_column($options, 'label'), true)) {
                continue;
            }

            $options[] = ['label' => $label, 'votes' => $this->votes($option)];
        }

        if (count($options) < $minimum) {
            throw ValidationException::withMessages([
                'poll' => __('A poll needs at least :count choices that differ.', ['count' => $minimum]),
            ]);
        }

        if (count($options) > $maximum) {
            throw ValidationException::withMessages([
                'poll' => __('A poll can offer at most :count choices.', ['count' => $maximum]),
            ]);
        }

        return [
            'options' => $options,
            'votes' => array_sum(array_column($options, 'votes')),
            'closes' => (string) ($poll['closes'] ?? ''),
        ];
    }

    /**
     * An option's existing vote count, which an edit must not invent or erase.
     */
    private function votes(mixed $option): int
    {
        $votes = is_array($option) ? ($option['votes'] ?? 0) : 0;

        return max(0, (int) $votes);
    }

    /**
     * A feeling or activity chip, kept only when it says something.
     *
     * @return array{label: string, detail: string}|null
     */
    private function activity(mixed $activity): ?array
    {
        if (! is_array($activity)) {
            return null;
        }

        $label = $this->label($activity['label'] ?? null);

        if ($label === null) {
            return null;
        }

        return ['label' => $label, 'detail' => (string) ($this->text($activity['detail'] ?? null) ?? '')];
    }

    /**
     * Merge the AI-content flag into the post's existing `detail` json.
     *
     * `detail` already holds the curated thread structure, so the flag is merged
     * rather than replacing it — and returning null when the composer said nothing
     * means an edit leaves the column exactly as it found it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function aiLabel(?FeedPost $post, array $data): ?array
    {
        if (! array_key_exists('ai_label', $data)) {
            return null;
        }

        /** @var array<string, mixed> $detail */
        $detail = is_array($post?->detail) ? $post->detail : [];

        if ($data['ai_label']) {
            $detail['ai_label'] = true;
        } else {
            unset($detail['ai_label']);
        }

        return $detail === [] ? null : $detail;
    }

    /**
     * A block of text, or null when there is nothing to keep.
     */
    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A one-line label — a filename, a poll option, a feeling — cut to what the
     * card can actually display.
     */
    private function label(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : Str::limit($value, 120, '');
    }
}
