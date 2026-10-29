<?php

namespace Database\Seeders;

use App\Models\FeedPost;
use App\Models\User;
use App\Support\FeedCorpus;
use App\Support\FeedCorpusMedia;
use App\Support\FeedPersonas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seed the community feed from the canonical corpus.
 *
 * The corpus ({@see FeedCorpus}) is the content definition; this seeder is the
 * only thing that turns it into rows. It upserts by slug, so re-running it
 * corrects edited content without duplicating posts, and a fresh install gets
 * the full corpus through the ordinary `db:seed` path.
 *
 * Each row also gets an owner. `user_id` is resolved through
 * {@see FeedPersonas} — the registry the corpus bylines come from — so a seeded
 * post is editable, deletable and schedulable by the account it is attributed
 * to, instead of being content nobody can act on. The denormalised
 * `author`/`handle`/`avatar`/`role` columns are written byte-for-byte as the
 * corpus states them: they are what a card renders, and `user_id` is only the
 * key authorization is decided against.
 */
class FeedPostSeeder extends Seeder
{
    /**
     * Persona handle → account, so ten corpus rows ask for six users once.
     *
     * @var array<string, User>
     */
    private array $authors = [];

    public function run(): void
    {
        foreach (FeedCorpus::posts() as $post) {
            $row = FeedPost::query()->updateOrCreate(
                ['slug' => $post['slug']],
                [
                    'user_id' => $this->authorFor($post['handle'])?->id,
                    'title' => $post['title'],
                    'author' => $post['author'],
                    'handle' => $post['handle'],
                    'avatar' => $post['avatar'],
                    'role' => $post['role'],
                    'posted_at' => FeedCorpus::parsePostedAt($post['time']),
                    'topic' => $post['topic'],
                    'type' => $post['type'],
                    'answered' => $post['answered'],
                    'score' => $post['score'],
                    'reports' => $post['reports'],
                    'views' => $post['views'],
                    'comments' => $post['comments'],
                    'body' => $post['body'],
                    'tags' => $post['tags'],
                    'reactions' => $post['reactions'],
                    'pinned' => $post['pinned'],
                    'featured' => $post['featured'],
                    'hidden' => $post['hidden'],
                    'deleted' => $post['deleted'],
                    'code' => $post['code'] ?? null,
                    'code_file' => $post['codeFile'] ?? null,
                    'read_minutes' => $post['readMinutes'] ?? null,
                    'poll' => $post['poll'] ?? null,
                    'answer' => $post['answer'] ?? null,
                    'activity' => $post['activity'] ?? null,
                    'detail' => $post['detail'] ?? null,
                ],
            );

            // The showcase rows ship with images: painted here rather than
            // stored in the repository, so the image grid is reviewable on a
            // fresh install without a binary in git.
            FeedCorpusMedia::attachTo($row, (int) ($post['media'] ?? 0));
        }
    }

    /**
     * The account behind a corpus byline, created if this install has none.
     *
     * Feed posts are seeded before the community directory on some paths and
     * after it on others, so the author cannot be assumed to exist. Creating it
     * here by email keeps the seeder order-independent and still produces one
     * account per person.
     */
    private function authorFor(string $handle): ?User
    {
        $persona = FeedPersonas::findByHandle($handle);

        if ($persona === null) {
            return null;
        }

        if (isset($this->authors[$persona['handle']])) {
            return $this->authors[$persona['handle']];
        }

        return $this->authors[$persona['handle']] = User::query()->firstOrCreate(
            ['email' => $persona['email']],
            [
                'name' => $persona['name'],
                'handle' => $persona['handle'],
                'password' => Hash::make('password'),
            ],
        );
    }
}
