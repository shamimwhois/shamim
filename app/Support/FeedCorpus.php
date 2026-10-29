<?php

namespace App\Support;

use App\Models\FeedPost;
use DateTimeInterface;

/**
 * The community feed corpus — the canonical content definition, seeded once.
 *
 * The feed module used to ship this array as its live data with a session
 * overlay for moderation. It is now the seed definition behind
 * {@see FeedPost}: {@see FeedPostSeeder} upserts these rows, and
 * the admin console's "restore defaults" action re-reads them. Nothing renders
 * from this class directly — every surface (public feed, discussion detail,
 * admin console) reads the model, so the corpus exists in exactly one place.
 *
 * Moderation state is *not* here: pinned / featured / hidden / deleted / solved
 * live as columns on the model, because a moderation decision must be true for
 * every visitor, not only the operator's session.
 *
 * @phpstan-type FeedReply array{author: string, avatar: string, time: string, body: string}
 * @phpstan-type PollOption array{label: string, votes: int}
 * @phpstan-type DiffLine array{num: int, kind: 'ctx'|'add'|'del'|'meta', code: string, comment?: array{author: string, avatar: string, body: string, replies: array<int, FeedReply>}}
 * @phpstan-type FeedCorpusPost array{id: int, slug: string, title: string, author: string, handle: string, avatar: string, role: string, time: string, topic: string, type: string, answered: bool, score: int, reports: int, views: int, body: string, tags: array<int, string>, reactions: array{like: int, love: int, insight: int}, comments: int, pinned: bool, featured: bool, hidden: bool, deleted: bool, code?: string, codeFile?: string, readMinutes?: int, poll?: array{options: array<int, PollOption>, votes: int, closes: string}, answer?: array{author: string, avatar: string, body: string}, activity?: array{label: string, detail: string}, detail?: array{title: string, code: string, diff: array{filename: string, summary: string, lines: array<int, DiffLine>}, replies: array<int, FeedReply>}, media?: int}}
 */
final class FeedCorpus
{
    /**
     * The corpus, in seed order. The `time` labels are parsed into real
     * `posted_at` timestamps by the seeder; everything else is stored as-is.
     *
     * @return array<int, FeedCorpusPost>
     */
    public static function posts(): array
    {
        return [
            [
                'id' => 1, 'slug' => 'eloquent-n1-dashboard', 'title' => 'Pulled the N+1 out of the dashboard post list: 140ms → 18ms',
                'author' => 'Md Shamim Hossain', 'handle' => '@shamimstack', 'avatar' => 'MS', 'role' => 'Full Stack Developer · Laravel',
                'time' => '2h ago', 'topic' => 'backend', 'type' => 'showcase', 'answered' => true, 'score' => 210, 'reports' => 0, 'views' => 2140,
                'body' => 'Shipped a small but satisfying refactor today: pulled the N+1 out of the dashboard post list by eager-loading category + tags in a single query. Page went from 140ms to 18ms. The boring wins are the best wins.',
                'tags' => ['Laravel', 'Performance', 'Eloquent'],
                'reactions' => ['like' => 24, 'love' => 6, 'insight' => 3],
                'comments' => 14, 'pinned' => false, 'featured' => false, 'hidden' => false, 'deleted' => false,
                'media' => 2,
            ],
            [
                'id' => 2, 'slug' => 'design-systems-hill', 'title' => 'Hot take: most design systems are just component libraries with a marketing page',
                'author' => 'Priya Menon', 'handle' => '@priya', 'avatar' => 'PM', 'role' => 'Design engineer',
                'time' => '5h ago', 'topic' => 'frontend', 'type' => 'discussion', 'answered' => false, 'score' => 96, 'reports' => 0, 'views' => 890,
                'body' => 'Hot take: most "design systems" are just component libraries with a marketing page. The actual system is the decisions — spacing scale, type ramp, motion language — not the button variants. (Yes, I will die on this hill.)',
                'tags' => ['Design', 'Systems', 'Opinion'],
                'reactions' => ['like' => 41, 'love' => 12, 'insight' => 9],
                'comments' => 7, 'pinned' => false, 'featured' => false, 'hidden' => false, 'deleted' => false,
                'activity' => ['label' => 'Active discussion', 'detail' => 'Last reply 8m ago by @kenji'],
            ],
            [
                'id' => 3, 'slug' => 'tailwind-v4-dark-mode', 'title' => 'Tailwind v4 dark mode classes stopped working — the `@custom-variant` fix',
                'author' => 'Kenji Sato', 'handle' => '@kenji', 'avatar' => 'KS', 'role' => 'Frontend engineer · CSS tooling',
                'time' => '1d ago', 'topic' => 'tooling', 'type' => 'question', 'answered' => true, 'score' => 88, 'reports' => 0, 'views' => 5300,
                'body' => "SOLVED: if you are on Tailwind v4 and your dark mode classes stopped working, check that you moved from `darkMode: 'class'` to the new `@custom-variant dark` syntax. Spent an hour on this so you do not have to.",
                'tags' => ['Tailwind', 'CSS', 'Tips'],
                'reactions' => ['like' => 89, 'love' => 4, 'insight' => 31],
                'comments' => 5, 'pinned' => false, 'featured' => false, 'hidden' => false, 'deleted' => false,
                'answer' => [
                    'author' => 'Matthias Klein', 'avatar' => 'MK',
                    'body' => '`@custom-variant dark (&:where(.dark, .dark *));` in your main CSS is the v4 replacement — the old `darkMode: "class"` option silently no-ops.',
                ],
            ],
            [
                'id' => 4, 'slug' => 'eloquent-chunk-export', 'title' => 'chunkById vs lazyById for a 500k-row export on a busy primary?',
                'author' => 'Daniel Okafor', 'handle' => '@danielo', 'avatar' => 'DO', 'role' => 'Backend engineer · Queues',
                'time' => '2d ago', 'topic' => 'backend', 'type' => 'question', 'answered' => false, 'score' => 12, 'reports' => 0, 'views' => 240,
                'body' => 'Best practice for chunking a 500k-row export? `lazy()` seems to hold the connection hostage in worker queues — anyone benchmarked `chunkById` vs `lazyById` on a busy primary?',
                'tags' => ['Queues', 'Eloquent', 'Help'],
                'reactions' => ['like' => 8, 'love' => 1, 'insight' => 3],
                'comments' => 2, 'pinned' => false, 'featured' => false, 'hidden' => false, 'deleted' => false,
            ],
            [
                'id' => 5, 'slug' => 'starter-kit-v1', 'title' => 'Cut v1.0 of our starter kit: auth scaffolding, role gates, tested CI',
                'author' => 'Aisha Rahman', 'handle' => '@aisha', 'avatar' => 'AR', 'role' => 'Maintainer · starter-kit',
                'time' => '3d ago', 'topic' => 'community', 'type' => 'showcase', 'answered' => true, 'score' => 158, 'reports' => 1, 'views' => 1780,
                'body' => 'Just cut v1.0 of our starter kit: auth scaffolding, role gates and a tested CI pipeline. Ten months of weekend nights in a git tag. Docs are rough; feedback wildly welcome.',
                'tags' => ['OpenSource', 'Release'],
                'reactions' => ['like' => 96, 'love' => 40, 'insight' => 22],
                'comments' => 23, 'pinned' => false, 'featured' => false, 'hidden' => false, 'deleted' => false,
                'media' => 1,
            ],
            [
                'id' => 6, 'slug' => 'boring-tools', 'title' => 'The tools I trust are the boring ones — what quietly carries your workflow?',
                'author' => 'Tomás Herrera', 'handle' => '@tomas', 'avatar' => 'TH', 'role' => 'Platform engineer',
                'time' => '4d ago', 'topic' => 'tooling', 'type' => 'discussion', 'answered' => false, 'score' => 74, 'reports' => 0, 'views' => 1120,
                'body' => 'The tools I trust are the boring ones: ripgrep, fd, make, jq. Every shiny dashboard eventually becomes a shell alias. What "boring" tool quietly carries your whole workflow?',
                'tags' => ['Cli', 'Workflow', 'Opinion'],
                'reactions' => ['like' => 30, 'love' => 8, 'insight' => 14],
                'comments' => 11, 'pinned' => false, 'featured' => false, 'hidden' => false, 'deleted' => false,
            ],
            self::rustAsyncDiscussion(),
            [
                'id' => 8, 'slug' => 'immutable-patch-ts', 'title' => 'Zero-runtime deep immutable patch utility in TypeScript 5.4',
                'author' => 'Sarah Thorne', 'handle' => '@sarah_ts', 'avatar' => 'ST', 'role' => 'TypeScript lead',
                'time' => '6h ago', 'topic' => 'frontend', 'type' => 'snippet', 'answered' => false, 'score' => 142, 'reports' => 0, 'views' => 960,
                'body' => 'Zero-runtime deep immutable patch utility in TypeScript 5.4. Preserves discriminated unions without pulling in Immer or Lodash — 12 lines, no dependencies.',
                'tags' => ['TypeScript', 'DX'],
                'reactions' => ['like' => 61, 'love' => 9, 'insight' => 38],
                'comments' => 29, 'pinned' => false, 'featured' => false, 'hidden' => false, 'deleted' => false,
                'codeFile' => 'immutable-patch.ts',
                'code' => "type DeepPatch<T> = {\n  [K in keyof T]?: T[K] extends object ? DeepPatch<T[K]> : T[K];\n};\n\nexport const immutablePatch = <T extends object>(base: T, patch: DeepPatch<T>): T => ({\n  ...base,\n  ...Object.fromEntries(\n    Object.entries(patch).map(([k, v]) =>\n      [k, typeof v === 'object' && v !== null ? immutablePatch(base[k], v as any) : v]\n    )\n  ),\n});",
            ],
            [
                'id' => 9, 'slug' => 'quantization-edge-inference', 'title' => 'Understanding quantization for edge inference: GGUF vs AWQ',
                'author' => 'Kevin Ahn', 'handle' => '@kevin_ai', 'avatar' => 'KA', 'role' => 'ML engineer · Edge inference',
                'time' => '12h ago', 'topic' => 'tooling', 'type' => 'article', 'answered' => false, 'score' => 187, 'reports' => 0, 'views' => 1350,
                'body' => 'Understanding quantization for edge inference: GGUF vs AWQ, memory bandwidth ceilings, perplexity trade-offs across 4-bit vs 8-bit matrices, and what SIMD kernels buy you on M-series silicon.',
                'tags' => ['LLM', 'Performance'],
                'reactions' => ['like' => 74, 'love' => 12, 'insight' => 26],
                'comments' => 31, 'pinned' => false, 'featured' => false, 'hidden' => false, 'deleted' => false,
                'readMinutes' => 8,
            ],
            [
                'id' => 10, 'slug' => 'weekly-poll-runtimes', 'title' => 'Weekly pulse: which backend runtime powers your greenfield projects?',
                'author' => 'Nadia Petrova', 'handle' => '@nadia_p', 'avatar' => 'NP', 'role' => 'Community host',
                'time' => '1d ago', 'topic' => 'community', 'type' => 'poll', 'answered' => false, 'score' => 95, 'reports' => 0, 'views' => 4180,
                'body' => 'Weekly pulse: which backend runtime is currently powering your greenfield production projects this year?',
                'tags' => ['Runtimes'],
                'reactions' => ['like' => 44, 'love' => 2, 'insight' => 6],
                'comments' => 62, 'pinned' => false, 'featured' => false, 'hidden' => false, 'deleted' => false,
                'poll' => [
                    'options' => [
                        ['label' => 'Go', 'votes' => 1756],
                        ['label' => 'Rust', 'votes' => 1296],
                        ['label' => 'Node / Bun', 'votes' => 794],
                        ['label' => 'Python', 'votes' => 334],
                    ],
                    'votes' => 4180,
                    'closes' => '1 day left',
                ],
            ],
        ];
    }

    /**
     * The featured discussion: Rust async stream processing — the one
     * fully-authored detail view, with a code sample, an interactive diff
     * with per-line review comments, and a reply thread.
     *
     * @return FeedCorpusPost
     */
    private static function rustAsyncDiscussion(): array
    {
        return [
            'id' => 7, 'slug' => 'rust-async-stream-processing', 'title' => 'Rust async stream processing: walking a 4 GB event log without blocking the runtime',
            'author' => 'Rayhan Kabir', 'handle' => '@rayhan', 'avatar' => 'RK', 'role' => 'Systems engineer · Rust',
            'time' => '6h ago', 'topic' => 'backend', 'type' => 'discussion', 'answered' => true, 'score' => 186, 'reports' => 0, 'views' => 2418,
            'body' => 'We ingest a rolling 4 GB event log across hundreds of thousands of lines, and the first cut blocked a worker on every 8 MB page fetch. Here is the async stream version that keeps the runtime hot end to end — patch, review comments and all.',
            'tags' => ['Rust', 'Async', 'Tokio', 'Performance'],
            'reactions' => ['like' => 62, 'love' => 19, 'insight' => 28],
            'comments' => 9, 'pinned' => true, 'featured' => true, 'hidden' => false, 'deleted' => false,
            'detail' => [
                'title' => 'Rust async stream processing: walking a 4 GB event log without blocking the runtime',
                'code' => "use futures::stream::{self, StreamExt};\nuse tokio::io::{BufReader, AsyncBufReadExt};\n\npub async fn ingest(path: &str) -> Result<(), Error> {\n    let file = File::open(path).await?;\n    let lines = BufReader::new(file).lines();\n\n    // Cap in-flight pages; the decoder runs as its own task.\n    let events = stream::iter(lines)\n        .filter_map(|line| async move { line.ok() })\n        .buffer_unordered(8);\n\n    events.for_each(handle).await;\n\n    Ok(())\n}",
                'diff' => [
                    'filename' => 'src/ingest.rs',
                    'summary' => 'Replace the blocking page loop with an ordered async stream. Concurrency is capped at 8 to keep backpressure on our side of the socket.',
                    'lines' => [
                        ['num' => 1, 'kind' => 'ctx', 'code' => 'use tokio::fs::File;'],
                        ['num' => 2, 'kind' => 'ctx', 'code' => 'use tokio::io::{BufReader, AsyncBufReadExt};'],
                        ['num' => 8, 'kind' => 'meta', 'code' => '@@ -8,6 +8,11 @@ pub async fn ingest(path: &str) {'],
                        ['num' => 8, 'kind' => 'del', 'code' => 'let mut cursor = 0;', 'comment' => [
                            'author' => 'Rayhan Kabir', 'avatar' => 'RK',
                            'body' => 'This loop held the worker hostage: every `blocking_fetch` parked the thread for ~200ms while the socket ran cold between pages.',
                            'replies' => [
                                ['author' => 'Priya Menon', 'avatar' => 'PM', 'time' => '4h ago', 'body' => 'That cold-socket gap is exactly the 140ms → 18ms shape we saw on the dashboard. Same disease, different runtime.'],
                            ],
                        ]],
                        ['num' => 9, 'kind' => 'del', 'code' => 'loop {'],
                        ['num' => 10, 'kind' => 'del', 'code' => 'let bytes = blocking_fetch(cursor, PAGE_SIZE)?;'],
                        ['num' => 11, 'kind' => 'del', 'code' => 'for line in bytes.lines() { handle(line)?; }'],
                        ['num' => 12, 'kind' => 'del', 'code' => 'cursor += PAGE_SIZE;'],
                        ['num' => 13, 'kind' => 'del', 'code' => '}'],
                        ['num' => 8, 'kind' => 'add', 'code' => 'let file = File::open(path).await?;', 'comment' => [
                            'author' => 'Tanzil Ahmed', 'avatar' => 'TA',
                            'body' => 'Opening through tokio keeps the open() itself non-blocking — worth a module-doc note, because `std::fs::File` compiles fine here and quietly reintroduces the stall.',
                            'replies' => [],
                        ]],
                        ['num' => 9, 'kind' => 'add', 'code' => 'let lines = BufReader::new(file).lines();'],
                        ['num' => 10, 'kind' => 'add', 'code' => 'let events = stream::iter(lines)'],
                        ['num' => 11, 'kind' => 'add', 'code' => '.filter_map(|line| async move { line.ok() })'],
                        ['num' => 12, 'kind' => 'add', 'code' => '.buffer_unordered(8);', 'comment' => [
                            'author' => 'Rayhan Kabir', 'avatar' => 'RK',
                            'body' => 'Eight is not magic: it is core count minus headroom for the decoder task. Sweep 4 → 32 in the bench harness; throughput plateaus at 8 on our volume.',
                            'replies' => [
                                ['author' => 'Daniel Okafor', 'avatar' => 'DO', 'time' => '2h ago', 'body' => 'Plateau-at-8 matches our queue worker findings. The cap also protects you when someone runs this on a 4-core CI box.'],
                                ['author' => 'Tanzil Ahmed', 'avatar' => 'TA', 'time' => '1h ago', 'body' => 'Then the constant deserves a name and this comment — sending a patch.'],
                            ],
                        ]],
                        ['num' => 13, 'kind' => 'add', 'code' => 'events.for_each(handle).await;'],
                    ],
                ],
                'replies' => [
                    ['author' => 'Priya Menon', 'avatar' => 'PM', 'time' => '5h ago', 'body' => 'The before/after trace is worth pinning on its own: worker busy-time went from 41% to 94% once the fetch pages stopped stalling the scheduler.'],
                    ['author' => 'Kenji Sato', 'avatar' => 'KS', 'time' => '3h ago', 'body' => 'If you wrap this in join with the decoder stage, the two overlap end to end. Benchmarked 1.4× on a 2-core box.'],
                    ['author' => 'Aisha Rahman', 'avatar' => 'AR', 'time' => '1h ago', 'body' => 'Bookmarking this as the review example for our onboarding doc: real patch, real line comments, real reasoning about backpressure.'],
                ],
            ],
        ];
    }

    /**
     * Parse a corpus relative label ("2h ago", "1d ago") into a timestamp.
     */
    public static function parsePostedAt(string $time): DateTimeInterface
    {
        if (preg_match('/^(\d+)h ago$/', $time, $matches) === 1) {
            return now()->subHours((int) $matches[1]);
        }

        if (preg_match('/^(\d+)d ago$/', $time, $matches) === 1) {
            return now()->subDays((int) $matches[1]);
        }

        return now();
    }
}
