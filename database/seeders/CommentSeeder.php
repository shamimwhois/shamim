<?php

namespace Database\Seeders;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeds a believable comment thread across the published posts.
 *
 * Distribution (per docs/SCHEMA.md §8):
 *  - ~30 comments total
 *  - 20 approved (visible on the blog detail page)
 *  - 10 pending  (visible only in the dashboard moderation queue)
 *
 * Comments are spread across several reader accounts and a handful of them are
 * threaded replies so the moderation UI and the nested thread renderer both have
 * something realistic to work with.
 */
class CommentSeeder extends Seeder
{
    /**
     * Comment bodies keyed by standing so the queue is not homogeneous.
     *
     * @var list<array{body: string, status: CommentStatus, reply?: string}>
     */
    private const array THREADS = [
        [
            'body' => 'This is the post I keep sending to new hires. The `with()` example finally made eager loading click for one of my teammates.',
            'status' => CommentStatus::Approved,
            'reply' => 'Glad it landed. The `with()` vs `load()` distinction is the one I see trip people up most often in review.',
        ],
        [
            'body' => 'Disagree slightly on the ranking approach — a window function would be cheaper here. But I take the portability argument.',
            'status' => CommentStatus::Approved,
            'reply' => 'Fair push-back. If I could guarantee PostgreSQL I would reach for `row_number()` every time. SQLite in dev is the constraint.',
        ],
        [
            'body' => 'We hit exactly this bug last quarter. Two hours of production debugging for a one-line ordering clause.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'The section on retry policies reads like you have been reading our queue config. Bookmarking this.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'Do you have a follow-up on how this interacts with soft deletes? Curious whether the counters self-heal there too.',
            'status' => CommentStatus::Approved,
            'reply' => 'Yes — recount-based sync means it self-heals. I walk through it in the reconciliation section.',
        ],
        [
            'body' => 'Sanitising on write instead of read is such an obvious win once you see it. Why is this not the default advice everywhere?',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'Wrote something very similar last year but your framing of the trade-off is much clearer. Thanks for publishing.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'The ADR template alone was worth the read. Already adapted it for our team.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'Any guidance on backfilling `body_html` for existing rows? We have about forty thousand posts to migrate.',
            'status' => CommentStatus::Approved,
            'reply' => 'Chunk with `lazyById()`, dispatch per-post hydration to the queue, and keep the renderer versioned so replays stay deterministic.',
        ],
        [
            'body' => 'Observation: this advice assumes a single writer. Much of it still holds with concurrent authors but the counters section needs care.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'Short, concrete, and it does not waste a paragraph telling me what a query plan is. More posts like this please.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'The index write-path trade-off is under-discussed. Everyone wants to add indexes; nobody wants to measure the insert cost.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'Ran the same experiment on our staging dataset and got a comparable curve. Nice to have it validated independently.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'Small nit: the third code block is missing its language tag, so the syntax highlighting falls back to plain text.',
            'status' => CommentStatus::Approved,
            'reply' => 'Good catch — fixed. The renderer reads the fence info string, so a bare fence loses highlighting entirely.',
        ],
        [
            'body' => 'This explains why our "temporary" module boundary has survived three refactors. Turns out the boundary was the design.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'I appreciate that you named the failure mode instead of just the pattern. Most architecture writing skips that.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'Question on the autosave interval — did you find a debounce window that felt right, or is it purely by-writing-pattern?',
            'status' => CommentStatus::Approved,
            'reply' => 'Two seconds after the last keystroke. Fast enough to feel safe, slow enough that a full paragraph is one request.',
        ],
        [
            'body' => 'Bookmarking for our next sprint retro. The code review section is going straight into the team handbook.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'Been writing PHP for a decade and still learned something from the placeholder-stash trick. Clever without being cute.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'Would love a deeper dive on the observability side. Metrics are easy; deciding what to alert on is the hard part.',
            'status' => CommentStatus::Approved,
        ],
        [
            'body' => 'Is this approach still sound if the queue driver is `sync` in local development?',
            'status' => CommentStatus::Pending,
        ],
        [
            'body' => 'First! Anyway — good post, though I think the benchmark is a little optimistic for a cold cache.',
            'status' => CommentStatus::Pending,
        ],
        [
            'body' => 'You should really be using a dedicated search engine instead of LIKE queries. Meilisearch scales far better.',
            'status' => CommentStatus::Pending,
        ],
        [
            'body' => 'Hi, I can help you improve your SEO ranking dramatically. Visit my profile for details.',
            'status' => CommentStatus::Pending,
        ],
        [
            'body' => 'Long-time reader, first-time commenter. Does the reading-time estimate hold for code-heavy posts or does it skew low?',
            'status' => CommentStatus::Pending,
        ],
        [
            'body' => 'I think the ordering-key argument falls apart if you are using a UUIDv7 primary key. Has that changed your view?',
            'status' => CommentStatus::Pending,
        ],
        [
            'body' => 'Following. Currently mid-migration and this is the exact rabbit hole I fell into last week.',
            'status' => CommentStatus::Pending,
        ],
        [
            'body' => 'Great write-up. One thing missing: how do you handle slugs colliding when a title is edited after publication?',
            'status' => CommentStatus::Pending,
        ],
        [
            'body' => 'Disagree that soft deletes are the right default here. Hard deletes plus an archive table is simpler to reason about.',
            'status' => CommentStatus::Pending,
        ],
        [
            'body' => 'The moderation queue design is neat. Curious how you decided on `pending` as the initial state rather than approved.',
            'status' => CommentStatus::Pending,
        ],
    ];

    /**
     * Display names for the seeded reader accounts.
     *
     * @var list<string>
     */
    private const array READERS = [
        'Priya Raman',
        'Marcus Webb',
        'Anika Sorensen',
        'Tomas Delgado',
        'Hannah Lin',
        'Yusuf Bakari',
        'Clara Nowak',
        'Devin Okafor',
    ];

    public function run(): void
    {
        /** @var Collection<int, User> $readers */
        $readers = User::factory()
            ->count(count(self::READERS))
            ->sequence(...array_map(
                static fn (string $name): array => ['name' => $name],
                self::READERS,
            ))
            ->create();

        /** @var Collection<int, Post> $posts */
        $posts = Post::query()->published()->recent()->get();

        if ($posts->isEmpty()) {
            return;
        }

        $readerIndex = 0;
        $postIndex = 0;

        foreach (self::THREADS as $thread) {
            $post = $posts->get($postIndex % $posts->count());
            $author = $readers->get($readerIndex % $readers->count());

            $comment = Comment::factory()
                ->for($post)
                ->for($author, 'user')
                ->state([
                    'body' => $thread['body'],
                    'status' => $thread['status'],
                    'parent_id' => null,
                ])
                ->create();

            if (isset($thread['reply'])) {
                Comment::factory()
                    ->for($post)
                    ->for($readers->get(($readerIndex + 1) % $readers->count()), 'user')
                    ->state([
                        'body' => $thread['reply'],
                        'status' => CommentStatus::Approved,
                        'parent_id' => $comment->id,
                    ])
                    ->create();
            }

            $readerIndex = ($readerIndex + 2) % $readers->count();
            $postIndex++;
        }
    }
}
