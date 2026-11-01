<?php

namespace Database\Seeders;

use App\Models\FeedPost;
use App\Models\FeedReply;
use App\Models\User;
use App\Support\FeedPersonas;
use Illuminate\Database\Seeder;

/**
 * Seed a few real conversations under the newest corpus commits.
 *
 * The corpus states a reply *count* per post but carries no reply rows, so a
 * fresh install would draw a log where every thread is empty and the "N replies"
 * toggle never appears. This seeder writes actual rows for the newest few
 * commits — authored by the same personas who wrote the posts — so the thread,
 * the reply composer and the count all demonstrate themselves.
 *
 * It is idempotent: a post that already has a reply is left alone, so re-running
 * `db:seed` corrects nothing and duplicates nothing.
 */
class FeedReplySeeder extends Seeder
{
    /**
     * The conversations, keyed by the corpus author's handle.
     *
     * @var array<string, array<int, array{handle: string, body: string}>>
     */
    private const CONVERSATIONS = [
        'shamimstack' => [
            ['handle' => 'priya', 'body' => 'Eager-loading the category relation was the whole win, then? I hit the same wall on a 40k-row index last month.'],
            ['handle' => 'rayhan', 'body' => 'Worth checking `chunkById` over the offset pagination too — the tail of a large table is where the query planner gives up.'],
        ],
        'priya' => [
            ['handle' => 'sarah_ts', 'body' => 'The spacing scale is the part nobody writes down. Ours is four values and a prayer.'],
        ],
        'rayhan' => [
            ['handle' => 'kenji', 'body' => 'Patching a worker mid-stream is the right call. Did you keep the offsets in the checkpoint or recompute them?'],
            ['handle' => 'priya', 'body' => 'The `all and all` review comments on this one are the best part.'],
        ],
    ];

    public function run(): void
    {
        $posts = FeedPost::query()
            ->visible()
            ->orderByDesc('posted_at')
            ->take(6)
            ->get()
            ->keyBy(fn (FeedPost $post): string => ltrim($post->handle, '@'));

        foreach (self::CONVERSATIONS as $author => $conversation) {
            $post = $posts->get($author);

            if ($post === null) {
                continue;
            }

            foreach ($conversation as $index => $reply) {
                // Idempotent per reply, not per post: a conversation that gained
                // a line since the last run is completed, not skipped.
                if ($post->replies()->where('body', $reply['body'])->exists()) {
                    continue;
                }

                $user = $this->accountFor($reply['handle']);

                if ($user === null) {
                    continue;
                }

                FeedReply::query()->create([
                    'feed_post_id' => $post->id,
                    'user_id' => $user->id,
                    'body' => $reply['body'],
                    'created_at' => ($post->posted_at ?? now())->copy()->addMinutes(12 * ($index + 1)),
                ]);
            }
        }
    }

    /** The account behind a persona handle, if the registry seeded it. */
    private function accountFor(string $handle): ?User
    {
        $persona = FeedPersonas::findByHandle($handle);

        if ($persona === null) {
            return null;
        }

        return User::query()->where('handle', ltrim($persona['handle'], '@'))->first();
    }
}
