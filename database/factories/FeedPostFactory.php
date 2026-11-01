<?php

namespace Database\Factories;

use App\Enums\CommunityRole;
use App\Enums\FeedPostAudience;
use App\Enums\FeedPostStatus;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\FeedPost;
use App\Models\User;
use App\Support\FeedPersonas;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for the community feed's one content table.
 *
 * The defaults deliberately mirror the curated corpus: an *unowned* row, already
 * published, addressed to everyone. That is what the ten seeded posts are, and it
 * is what every pre-existing feed assertion reads, so a test that only needs "a
 * post in the feed" gets one without inheriting an author it did not ask for.
 * Ownership, draft state, schedule and audience are opt-in states, because each
 * one changes what a reader is allowed to see.
 *
 * Identity is the corpus shape — `author`, `handle`, `avatar`, `role` — not a
 * relation. That duplication is the feed's single-query render, so the factory
 * keeps it and {@see identityFor()} reproduces exactly what
 * `App\Actions\Feed\SaveFeedDraft` writes; a test that asserts a card's byline
 * against a factory-built row is asserting the same snapshot the write path
 * would have produced.
 *
 * @extends Factory<FeedPost>
 */
class FeedPostFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<FeedPost>
     */
    protected $model = FeedPost::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->sentence(5), '.');
        $body = fake()->paragraph(4);

        return [
            'user_id' => null,
            // Minted, never derived at read time: `slug` is both the public URL
            // and a UNIQUE index, and two posts sharing a headline would collide.
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'author' => 'Feed Tester',
            'handle' => '@feedtester',
            'avatar' => 'FT',
            'role' => 'Community member',
            'posted_at' => now(),
            'topic' => (string) config('feed.composer.default_topic', 'community'),
            'type' => (string) config('feed.composer.default_type', 'discussion'),
            'status' => FeedPostStatus::Published,
            'scheduled_at' => null,
            'audience' => FeedPostAudience::Public,
            'community_id' => null,
            'answered' => false,
            'score' => fake()->numberBetween(0, 400),
            'reports' => 0,
            'views' => fake()->numberBetween(0, 900),
            'comments' => fake()->numberBetween(0, 30),
            'body' => $body,
            'tags' => [Str::ucfirst(Str::random(6))],
            'reactions' => [
                'like' => fake()->numberBetween(0, 40),
                'love' => fake()->numberBetween(0, 20),
                'insight' => fake()->numberBetween(0, 10),
            ],
            'pinned' => false,
            'featured' => false,
            'hidden' => false,
            'deleted' => false,
            'read_minutes' => max(1, (int) ceil(str_word_count($body) / 200)),
        ];
    }

    /**
     * Put the row behind an account, with the identity snapshot that account's
     * cards would print.
     *
     * `user_id` is only the authorization key; the rendered byline still comes
     * from the denormalised columns, so both have to move together or a test
     * would be authorising one author while displaying another.
     */
    public function authoredBy(User $author): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $author->id,
            ...$this->identityFor($author),
        ]);
    }

    /**
     * An unfinished draft: owned, not scheduled, and above all not published.
     *
     * `posted_at` is cleared because the write path never sets it for a draft —
     * a draft has no position in the feed's timeline until it publishes.
     */
    public function draft(?User $author = null): static
    {
        return $this->state(function (array $attributes) use ($author): array {
            return $author === null
                ? ['status' => FeedPostStatus::Draft, 'posted_at' => null, 'scheduled_at' => null]
                : [
                    'status' => FeedPostStatus::Draft,
                    'posted_at' => null,
                    'scheduled_at' => null,
                    'user_id' => $author->id,
                    ...$this->identityFor($author),
                ];
        });
    }

    /**
     * Scheduled for a moment that has not arrived.
     *
     * Pass `$due` to pin the exact time a `travel()` step is supposed to cross;
     * left out, the post is due in an hour, which is far enough ahead that no
     * ordinary assertion can publish it by accident.
     */
    public function scheduled(?User $author = null, mixed $due = null): static
    {
        return $this->state(function (array $attributes) use ($author, $due): array {
            return [
                'status' => FeedPostStatus::Scheduled,
                'scheduled_at' => $due === null ? now()->addHour() : $due,
                'posted_at' => null,
                'user_id' => $author !== null ? $author->id : $attributes['user_id'],
                ...($author === null ? [] : $this->identityFor($author)),
            ];
        });
    }

    /**
     * Addressed to its author alone.
     *
     * Requires an owner: a `Self` post with no `user_id` belongs to nobody, so it
     * is invisible to every reader including the one who wrote it. The state says
     * so rather than producing a row that only looks like a private post.
     */
    public function private(User $author): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $author->id,
            'audience' => FeedPostAudience::Self,
            'community_id' => null,
            ...$this->identityFor($author),
        ]);
    }

    /**
     * Shared into a community the author has joined.
     *
     * A community audience is a membership claim, so the state honours the same
     * rule `ResolveFeedAudience` enforces on the write path: the author is joined
     * before the post is addressed. Builds a community when the test does not
     * bring one.
     */
    public function inCommunity(?User $author = null, ?Community $community = null): static
    {
        $community ??= $this->makeCommunity();

        return $this->state(function (array $attributes) use ($author, $community): array {
            if ($author === null) {
                return [
                    'audience' => FeedPostAudience::Community,
                    'community_id' => $community->id,
                ];
            }

            $this->join($community, $author);

            return [
                'user_id' => $author->id,
                'audience' => FeedPostAudience::Community,
                'community_id' => $community->id,
                ...$this->identityFor($author),
            ];
        });
    }

    /**
     * A question that is still waiting for an answer — the "Needs answers" set.
     */
    public function question(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'question',
            'answered' => false,
        ]);
    }

    /**
     * A question someone answered, which is what takes it out of that set.
     */
    public function answered(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'question',
            'answered' => true,
            'answer' => [
                'author' => $attributes['author'],
                'avatar' => $attributes['avatar'],
                'body' => fake()->paragraph(2),
            ],
        ]);
    }

    /**
     * A code snippet, the shape the card's always-dark panel renders.
     */
    public function snippet(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'snippet',
            'code' => "<?php\n\nreturn FeedPost::query()->visible()->curated()->get();\n",
            'code_file' => 'app/Actions/Feed/recent.php',
        ]);
    }

    /**
     * A poll with enough distinct choices to be a poll.
     *
     * Two options is the floor `SaveFeedDraft` enforces; one bar is a statement,
     * not a vote, so a factory that emitted one would build rows the write path
     * refuses.
     */
    public function poll(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'poll',
            'poll' => [
                'options' => [
                    ['label' => 'Livewire', 'votes' => 12],
                    ['label' => 'Inertia', 'votes' => 7],
                ],
                'votes' => 19,
                'closes' => now()->addDays(3)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Hidden by moderation — publicly unreadable while the row survives.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => ['hidden' => true]);
    }

    /**
     * Soft-removed by moderation, the same flag the admin console sets.
     */
    public function removed(): static
    {
        return $this->state(fn (array $attributes): array => ['deleted' => true]);
    }

    /**
     * Carry the structured detail that makes a slug resolve to a thread.
     *
     * `feed.show` 404s anything without `detail['diff']`, so a test that wants to
     * reach a detail page has to build a post that can be reached.
     */
    public function withDetail(): static
    {
        return $this->state(fn (array $attributes): array => [
            'detail' => [
                'title' => $attributes['title'],
                'code' => "<?php\n\n\$posts = recentPosts();\n",
                'diff' => [
                    'filename' => 'app/Feed/recent.php',
                    'summary' => 'Fetch the curated stream in one query.',
                    'lines' => [
                        ['type' => 'add', 'text' => '$posts = FeedPost::query()->visible()->get();'],
                    ],
                ],
                'replies' => [],
            ],
        ]);
    }

    /**
     * The author identity a card prints, mirroring the write path byte for byte.
     *
     * `App\Actions\Feed\SaveFeedDraft::identity()` is the only producer of these
     * four columns in production; a factory that invented its own byline would
     * make every identity assertion test the factory instead of the feed.
     *
     * @return array{author: string, handle: string, avatar: string, role: string}
     */
    protected function identityFor(User $author): array
    {
        $persona = FeedPersonas::findByHandle($author->handle);
        $handle = $author->handle ?? Str::slug(Str::before($author->email, '@'));

        return [
            'author' => $author->name,
            'handle' => FeedPersonas::displayedHandle((string) $handle),
            'avatar' => $this->initials($author->name),
            'role' => $persona['role'] ?? $author->accountRole()->label(),
        ];
    }

    /**
     * At most two initials, the shape the avatar disc expects.
     */
    protected function initials(string $name): string
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
     * A community nobody has joined yet, for audience tests that add membership.
     */
    protected function makeCommunity(): Community
    {
        // Two words in the shape the two uses below need — a string, because a
        // name has to be slugable and titleable without a cast in between.
        $name = fake()->word().' '.fake()->word();

        return Community::query()->create([
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => Str::title($name),
            'description' => 'A test community for feed audience assertions.',
            'topic' => (string) config('feed.composer.default_topic', 'community'),
            'glyph' => 'TC',
            'kind' => 'open',
        ]);
    }

    /**
     * Join an account to a community, idempotently.
     */
    protected function join(Community $community, User $author): void
    {
        CommunityMembership::query()->firstOrCreate([
            'community_id' => $community->id,
            'user_id' => $author->id,
        ], [
            'role' => CommunityRole::Member,
            'joined_at' => now(),
        ]);
    }
}
