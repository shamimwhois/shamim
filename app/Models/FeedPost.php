<?php

namespace App\Models;

use App\Actions\Feed\HydrateFeedPostContent;
use App\Concerns\HasSlug;
use App\Enums\FeedPostAudience;
use App\Enums\FeedPostStatus;
use Database\Factories\FeedPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A community feed post — the one dynamic source behind every feed surface.
 *
 * The public feed, the discussion detail and the admin moderation console all
 * read this model; nothing renders from a second definition of the content.
 * Moderation state (pinned / featured / hidden / deleted / solved) lives in
 * columns here, so a decision taken in the admin console is immediately true
 * on the public surfaces for every visitor, not just the operator's session.
 *
 * Publishing state (status / scheduled_at / audience / community_id / user_id)
 * lives here too. `user_id` is the ownership key that authorization is decided
 * against; `author`, `handle`, `avatar` and `role` stay the rendered identity on
 * purpose, because a card that eager-loaded a user to print a name would cost
 * the feed the single-query render it is built around.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $slug
 * @property string|null $title
 * @property string $author
 * @property string $handle
 * @property string $avatar
 * @property string|null $role
 * @property Carbon|null $posted_at
 * @property string $topic
 * @property string $type
 * @property FeedPostStatus $status
 * @property Carbon|null $scheduled_at
 * @property FeedPostAudience $audience
 * @property int|null $community_id
 * @property bool $answered
 * @property int $score
 * @property int $reports
 * @property int $views
 * @property int $comments
 * @property string $body
 * @property array<int, string> $tags
 * @property array{like: int, love: int, insight: int} $reactions
 * @property bool $pinned
 * @property bool $featured
 * @property bool $hidden
 * @property bool $deleted
 * @property string|null $code
 * @property string|null $code_file
 * @property int|null $read_minutes
 * @property array{options: array<int, array{label: string, votes: int}>, votes: int, closes: string}|null $poll
 * @property array{author: string, avatar: string, body: string}|null $answer
 * @property array{label: string, detail: string}|null $activity
 * @property array{title: string, code: string, diff: array{filename: string, summary: string, lines: array<int, mixed>}, replies: array<int, mixed>}|null $detail
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'slug',
    'title',
    'author',
    'handle',
    'avatar',
    'role',
    'posted_at',
    'topic',
    'type',
    'status',
    'scheduled_at',
    'audience',
    'community_id',
    'answered',
    'score',
    'reports',
    'views',
    'comments',
    'body',
    'tags',
    'reactions',
    'pinned',
    'featured',
    'hidden',
    'deleted',
    'code',
    'code_file',
    'read_minutes',
    'poll',
    'answer',
    'activity',
    'detail',
])]
class FeedPost extends Model
{
    /** @use HasFactory<FeedPostFactory> */
    use HasFactory, HasSlug;

    /**
     * The attributes that should have default values.
     *
     * Declared here as well as on the migration column so a post built in memory
     * reads published and public, exactly like a row fresh from the database,
     * and the visibility predicates never have to survive a null enum.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => FeedPostStatus::Published->value,
        'audience' => FeedPostAudience::Public->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'status' => FeedPostStatus::class,
            'audience' => FeedPostAudience::class,
            'answered' => 'boolean',
            'pinned' => 'boolean',
            'featured' => 'boolean',
            'hidden' => 'boolean',
            'deleted' => 'boolean',
            'score' => 'integer',
            'reports' => 'integer',
            'views' => 'integer',
            'comments' => 'integer',
            'read_minutes' => 'integer',
            'tags' => 'array',
            'reactions' => 'array',
            'poll' => 'array',
            'answer' => 'array',
            'activity' => 'array',
            'detail' => 'array',
        ];
    }

    /**
     * The account that owns the post, when it has one.
     *
     * Corpus rows keep their denormalised identity and may have no account
     * behind them, so this is nullable by design rather than by omission.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The community a community-audience post is shared into.
     *
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * Attached media, in the order the composer arranged it.
     *
     * @return HasMany<FeedAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(FeedAttachment::class)->orderBy('sort_order');
    }

    /**
     * The stars this commit has earned.
     *
     * The log counts them with `withCount('stars')` on the one query it already
     * runs, so a row's count costs nothing per row, and the reader's own stars
     * are a single pluck keyed by post id.
     *
     * @return HasMany<FeedStar, $this>
     */
    public function stars(): HasMany
    {
        return $this->hasMany(FeedStar::class);
    }

    /**
     * The commit's thread, oldest first.
     *
     * @return HasMany<FeedReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(FeedReply::class)->oldest();
    }

    /**
     * Get the attribute used for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Scope to posts the public feed may render.
     *
     * The single definition of "publicly readable": neither hidden nor deleted
     * by moderation, and past its publish gate. A draft or a not-yet-due
     * scheduled post is not publicly readable no matter which surface asks.
     *
     * Audience is deliberately *not* filtered here — the feed reads one curated
     * collection for every widget, so per-reader visibility is applied as an
     * in-memory predicate by {@see isVisibleTo()}, next to the filters that
     * already work that way. This scope stays the SQL floor nothing escapes.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query
            ->where('hidden', false)
            ->where('deleted', false)
            ->where('status', FeedPostStatus::Published->value)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('scheduled_at')
                ->orWhere('scheduled_at', '<=', now()));
    }

    /**
     * Scope to questions still waiting on an accepted answer — the feed's
     * "needs answers" set, ranked by community demand at the call site.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unanswered(Builder $query): void
    {
        $query->where('type', 'question')->where('answered', false);
    }

    /**
     * Scope to posts that carry authored detail content.
     *
     * Only these resolve to a discussion thread; a slug without detail 404s
     * rather than pretending to be a thread it is not.
     *
     * The `diff` key is required as well as the column being set, because
     * `detail` is also where the composer stores its own small flags. A thread
     * page reads `$detail['diff']` unguarded, so "has a detail column" and "is a
     * thread" are not the same question — this scope answers the second one, and
     * is therefore the SQL mirror of {@see hasDetail()}.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withDetail(Builder $query): void
    {
        $query->whereNotNull('detail')->whereNotNull('detail->diff');
    }

    /**
     * Scope to curated order: pinned first, then featured, then newest.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function curated(Builder $query): void
    {
        $query->orderByDesc('pinned')
            ->orderByDesc('featured')
            ->orderByDesc('posted_at')
            ->orderByDesc('id');
    }

    /**
     * Scope to one author's unfinished drafts, newest first.
     *
     * Drafts belong to exactly one account and are never public, so this is the
     * only query that should ever return them. A draft with no author cannot be
     * anybody's to resume.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function draftsOf(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId)
            ->where('status', FeedPostStatus::Draft->value)
            ->where('deleted', false)
            ->latest('updated_at');
    }

    /**
     * Total reactions across like / love / insight — the trending signal.
     */
    public function reactionSum(): int
    {
        return array_sum($this->reactions);
    }

    /**
     * The community score, formatted for display.
     */
    public function formattedScore(): string
    {
        return number_format($this->score);
    }

    /**
     * Determine whether the post is a question.
     */
    public function isQuestion(): bool
    {
        return $this->type === 'question';
    }

    /**
     * Determine whether the post is a question solved by an answer.
     */
    public function isSolved(): bool
    {
        return $this->isQuestion() && $this->answered;
    }

    /**
     * Determine whether the post resolves to a full discussion thread.
     */
    public function hasDetail(): bool
    {
        return $this->detail !== null && isset($this->detail['diff']);
    }

    /**
     * Determine whether the publish gate has opened for this post.
     *
     * Independent of moderation and audience: a published row whose schedule is
     * still in the future is not yet anything a reader can see.
     */
    public function isPublishedNow(): bool
    {
        return $this->status === FeedPostStatus::Published
            && ($this->scheduled_at === null || $this->scheduled_at->isPast());
    }

    /**
     * The body as the card prints it: the stored text with the derived headline
     * taken back out of the top.
     *
     * A card shows a subject above the body, the way `git log` does, and `git
     * log` prints a body with its subject line removed. A composer post keeps
     * its headline *inside* `body` (storage stays faithful, so the inline editor
     * has the author's text to open with), so the removal has to happen here —
     * otherwise the opening sentence is printed twice: once bold as the subject
     * and again at the top of the body.
     *
     * **An authored headline is never lifted.** The rule is not "does the body
     * contain the title" — a corpus post's headline is a separate field that the
     * body never repeats, and stripping a line on that basis would eat a
     * paragraph. The lift happens only when the stored title is exactly what
     * deriving from this body would have produced, which is a question the
     * derivation itself can answer.
     *
     * Returns an empty string for a one-line post, and empty is the right
     * answer: the subject is the whole post, and printing it again as the body
     * would be the duplication with nothing added. A sigil-only opening line
     * (`---`, `#`) derives no headline at all, so nothing is lifted and the
     * line stays where the author put it.
     */
    public function bodyUnderHeadline(): string
    {
        $body = trim((string) $this->body);
        $title = trim((string) $this->title);

        if ($body === '' || $title === '') {
            return $body;
        }

        // Re-derive from the body alone: when that produces the stored title,
        // the title *was* this body's opening line and printing both repeats it.
        $derived = app(HydrateFeedPostContent::class)->headlineFor($body);

        if ($derived === '' || $derived !== $title) {
            return $body;
        }

        $lines = explode("\n", $body);
        $index = null;

        foreach ($lines as $offset => $line) {
            if (trim($line) !== '') {
                $index = $offset;

                break;
            }
        }

        if ($index === null) {
            return $body;
        }

        unset($lines[$index]);

        return trim(implode("\n", $lines));
    }

    /**
     * Determine whether the given reader may see this post.
     *
     * The one visibility rule, asked by the feed's in-memory filters and by the
     * detail page's 404 gate alike, so a post can never be hidden in the stream
     * and reachable by slug. Guests get the public floor and nothing above it.
     *
     * `$joinedCommunityIds` is accepted so a caller already holding the reader's
     * memberships — the feed page, which loads them for `following` — costs no
     * extra query. When it is omitted the membership is looked up once.
     *
     * @param  array<int, int>|null  $joinedCommunityIds
     */
    public function isVisibleTo(?User $user, ?array $joinedCommunityIds = null): bool
    {
        // `getAttribute()` rather than `$this->hidden`, and this is the one place
        // in the model that has to spell it out: `Illuminate\Database\Eloquent\Model`
        // declares a protected `$hidden` property to list attributes withheld from
        // serialization, so inside this class `$this->hidden` reads that array —
        // empty, therefore falsy — and never the moderation column. From outside
        // the class the same expression is correct, because a protected property is
        // inaccessible and the read falls through to `__get()`. Which is why the
        // feed's own gate silently accepted a hidden post until a unit test caught
        // it: only in-class access is shadowed.
        if ($this->getAttribute('hidden') || $this->deleted || ! $this->isPublishedNow()) {
            return false;
        }

        return match ($this->audience) {
            FeedPostAudience::Public => true,
            FeedPostAudience::Self => $user !== null && $user->id === $this->user_id,
            FeedPostAudience::Community => $user !== null
                && ($user->id === $this->user_id
                    || in_array(
                        $this->community_id,
                        $joinedCommunityIds ?? $user->joinedCommunityIds(),
                        true,
                    )),
        };
    }
}
