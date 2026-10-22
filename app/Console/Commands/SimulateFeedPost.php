<?php

namespace App\Console\Commands;

use App\Enums\FeedPostAudience;
use App\Enums\FeedPostStatus;
use App\Models\FeedPost;
use App\Models\User;
use App\Support\FeedPersonas;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Publish demo posts into the community feed.
 *
 * The live feed is a polling surface: it announces posts that arrive after
 * the page was opened. In a seeded demo nothing arrives on its own, so this
 * command is the way to exercise that path by hand —
 *
 *     php artisan feed:simulate --count=2
 *
 * Open /feed, run the command, and within one poll interval the "new posts"
 * pill appears; clicking it folds the posts in with their entry animation.
 * It writes ordinary rows (no flags, no special casing), so nothing about the
 * feed has to know this command exists.
 *
 * A template names the persona who is speaking and nothing else about them.
 * The byline, handle, initials and role come from {@see FeedPersonas}, so a
 * simulated post reads exactly like the corpus post it imitates — and cannot
 * invent an eleventh author or misspell an existing one. Every row is written
 * as published and public — the same state the model's own column defaults
 * declare — because "simulated" here means *arrives in the feed*, nothing less.
 */
class SimulateFeedPost extends Command
{
    protected $signature = 'feed:simulate {--count=1 : How many posts to publish}';

    protected $description = 'Publish demo posts into the community feed (dev helper for the live feed)';

    /**
     * Templates, in the corpus's own voice. Each is a complete post: the
     * feed renders exactly the same fields a seeded row has.
     *
     * @var array<int, array{persona: string, topic: string, type: string, title: string, body: string, tags: array<int, string>, score: int, comments: int, views: int, reactions: array{like: int, love: int, insight: int}, read_minutes: int|null, code?: string|null, code_file?: string|null}>
     */
    private const TEMPLATES = [
        [
            'persona' => 'aisha',
            'topic' => 'backend', 'type' => 'discussion',
            'title' => 'Why our queue workers kept timing out at exactly 30 seconds',
            'body' => 'Every worker died at the same 30-second mark. Not a slow job — a missing heartbeat. The supervisor considered the worker dead while it was happily chewing through a batch, because the heartbeat was written on the main loop the batch had blocked.',
            'tags' => ['Queues', 'Laravel', 'Debugging'], 'score' => 64, 'comments' => 9, 'views' => 720,
            'reactions' => ['like' => 18, 'love' => 2, 'insight' => 21],
            'code' => null, 'code_file' => null, 'read_minutes' => null,
        ],
        [
            'persona' => 'kenji',
            'topic' => 'frontend', 'type' => 'snippet',
            'title' => 'A 12-line hook for optimistic lists that never flicker',
            'body' => 'Optimistic UI usually flickers because the server response replaces the whole list. Keep the optimistic entry keyed by a client id and reconcile by id instead of by position — the list stops jumping and the rollback is a single splice.',
            'tags' => ['React', 'DX'], 'score' => 91, 'comments' => 12, 'views' => 640,
            'reactions' => ['like' => 33, 'love' => 6, 'insight' => 14],
            'code_file' => 'use-optimistic-list.ts',
            'read_minutes' => null,
            'code' => "export function useOptimisticList<T extends { id: string }>(initial: T[]) {\n  const [items, setItems] = useState(initial);\n\n  const add = (item: T) => setItems((prev) => [item, ...prev]);\n  const reconcile = (saved: T) =>\n    setItems((prev) => prev.map((i) => (i.id === saved.id ? saved : i)));\n\n  return { items, add, reconcile };\n}",
        ],
        [
            'persona' => 'shamim',
            'topic' => 'tooling', 'type' => 'showcase',
            'title' => 'Shipped a command palette for the dashboard in three evenings',
            'body' => 'Cmd-K now jumps to any dashboard route, searches posts and runs the two actions people actually use. No new dependency: a Livewire component, a keyboard listener and a server-side index over route names.',
            'tags' => ['DX', 'Livewire'], 'score' => 74, 'comments' => 6, 'views' => 410,
            'reactions' => ['like' => 26, 'love' => 4, 'insight' => 8],
            'code' => null, 'code_file' => null, 'read_minutes' => null,
        ],
        [
            'persona' => 'priya',
            'topic' => 'community', 'type' => 'poll',
            'title' => 'Weekly pulse: which editor are you actually using this year?',
            'body' => 'Not which one you recommend in interviews — which one is open on your machine right now.',
            'tags' => ['Runtimes'], 'score' => 48, 'comments' => 21, 'views' => 980,
            'reactions' => ['like' => 12, 'love' => 1, 'insight' => 3],
            'code' => null, 'code_file' => null, 'read_minutes' => null,
        ],
        [
            'persona' => 'danielo',
            'topic' => 'backend', 'type' => 'question',
            'title' => 'Is a read replica worth it at 200 req/s, or is that premature?',
            'body' => 'Primary sits at 35% CPU with everything on one box. The replica would move reporting queries off the hot path, but now every read has a staleness window to reason about. Where is the line for you?',
            'tags' => ['Databases', 'Help'], 'score' => 22, 'comments' => 4, 'views' => 180,
            'reactions' => ['like' => 7, 'love' => 0, 'insight' => 5],
            'code' => null, 'code_file' => null, 'read_minutes' => null,
        ],
    ];

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));

        for ($i = 0; $i < $count; $i++) {
            $template = self::TEMPLATES[array_rand(self::TEMPLATES)];
            $persona = FeedPersonas::findByHandle($template['persona']);

            if ($persona === null) {
                $this->error(sprintf('Unknown feed persona "%s" — nothing was published.', $template['persona']));

                return self::FAILURE;
            }

            FeedPost::query()->create([
                'user_id' => $this->authorIdFor($persona['email']),
                'slug' => Str::slug($template['title']).'-'.Str::lower(Str::random(4)),
                'title' => $template['title'],
                'author' => $persona['name'],
                'handle' => FeedPersonas::displayedHandle($persona['handle']),
                'avatar' => $persona['avatar'],
                'role' => $persona['role'],
                'posted_at' => now(),
                'status' => FeedPostStatus::Published,
                'audience' => FeedPostAudience::Public,
                'topic' => $template['topic'],
                'type' => $template['type'],
                'answered' => false,
                'score' => $template['score'],
                'reports' => 0,
                'views' => $template['views'],
                'comments' => $template['comments'],
                'body' => $template['body'],
                'tags' => $template['tags'],
                'reactions' => $template['reactions'],
                'pinned' => false,
                'featured' => false,
                'hidden' => false,
                'deleted' => false,
                'code' => $template['code'] ?? null,
                'code_file' => $template['code_file'] ?? null,
                'read_minutes' => $template['read_minutes'],
            ]);
        }

        $this->info(sprintf('Published %d demo post%s into the feed.', $count, $count === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /**
     * The account matching a persona email, when this install has one.
     *
     * The command is a dev helper run against a seeded database, so the account
     * normally already exists; asking for it rather than creating one keeps
     * `feed:simulate` from inventing people. A missing account publishes an
     * owner-less row, exactly like a corpus post that predates its author.
     */
    private function authorIdFor(string $email): ?int
    {
        $id = User::query()->where('email', $email)->value('id');

        return $id === null ? null : (int) $id;
    }
}
