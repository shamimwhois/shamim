<?php

namespace Database\Seeders;

use App\Models\FeedPost;
use App\Models\FeedReply;
use App\Models\FeedStar;
use App\Models\LogNotification;
use App\Models\Remote;
use App\Models\RemoteMessage;
use App\Models\User;
use App\Support\LogIdentity;
use Illuminate\Database\Seeder;

/**
 * Seed the inbox and a couple of conversations for the owner account.
 *
 * A fresh install has a full log and nothing addressed at anyone, so the inbox,
 * the remotes page and the nav badges would all render their empty states and
 * the surfaces would never demonstrate themselves. This writes the activity the
 * pages are for — from the same personas who wrote the commits, so the names
 * and lanes match what the log shows.
 *
 * Idempotent: the inbox is only filled when it is empty, and a conversation is
 * keyed by its participants, so re-running `db:seed` duplicates nothing.
 */
class LogActivitySeeder extends Seeder
{
    /** Who the owner hears from, and about what. */
    private const INBOX = [
        ['handle' => 'priya', 'type' => LogNotification::Starred],
        ['handle' => 'rayhan', 'type' => LogNotification::Replied],
        ['handle' => 'sarah_ts', 'type' => LogNotification::Starred],
        ['handle' => 'kenji', 'type' => LogNotification::Mentioned],
        ['handle' => 'nadia_p', 'type' => LogNotification::Followed],
    ];

    /** Two conversations, with their pushes. */
    private const REMOTES = [
        [
            'with' => 'priya',
            'messages' => [
                ['from' => 'priya', 'body' => 'The lazy-loading fix from your last commit unblocked our dashboard. Thank you.'],
                ['from' => 'owner', 'body' => 'Glad it landed. The N+1 was hiding behind the category accessor the whole time.'],
                ['from' => 'priya', 'body' => 'Same one. We had a `with()` in the controller that never applied because the scope reset the query.'],
            ],
        ],
        [
            'with' => 'rayhan',
            'messages' => [
                ['from' => 'rayhan', 'body' => 'Do you want to review the stream patch before I push it? It touches the checkpoint offsets.'],
                ['from' => 'owner', 'body' => 'Push it. I will read it tonight — the offset handling is the part I want a second pair of eyes on anyway.'],
            ],
        ],
    ];

    public function run(): void
    {
        $owner = User::query()->where('handle', 'shamimstack')->first();

        if ($owner === null) {
            return;
        }

        $this->seedInbox($owner);
        $this->seedStars($owner);
        $this->seedRemotes($owner);
    }

    /** The inbox rows, one per persona, against the owner's newest commits. */
    private function seedInbox(User $owner): void
    {
        if (LogNotification::query()->where('user_id', $owner->id)->exists()) {
            return;
        }

        $commits = FeedPost::query()
            ->visible()
            ->where('user_id', $owner->id)
            ->latest('posted_at')
            ->take(3)
            ->get();

        if ($commits->isEmpty()) {
            return;
        }

        foreach (self::INBOX as $index => $row) {
            $actor = $this->accountFor($row['handle']);
            $commit = $commits[$index % $commits->count()];

            if ($actor === null) {
                continue;
            }

            LogNotification::query()->create([
                'user_id' => $owner->id,
                'actor_id' => $actor->id,
                'type' => $row['type'],
                'feed_post_id' => $commit->id,
                'feed_reply_id' => $row['type'] === LogNotification::Replied
                    ? FeedReply::query()->where('feed_post_id', $commit->id)->value('id')
                    : null,
                'created_at' => now()->subHours(6 * ($index + 1)),
            ]);
        }
    }

    /**
     * A few stars, so the starred page and the trending log have something.
     *
     * The owner stars other people's commits — starring your own is the one
     * gesture the log does not offer.
     */
    private function seedStars(User $owner): void
    {
        if (FeedStar::query()->where('user_id', $owner->id)->exists()) {
            return;
        }

        $commits = FeedPost::query()
            ->visible()
            ->where('user_id', '!=', $owner->id)
            ->orderByDesc('posted_at')
            ->take(3)
            ->get();

        foreach ($commits as $commit) {
            FeedStar::query()->create([
                'feed_post_id' => $commit->id,
                'user_id' => $owner->id,
            ]);
        }
    }

    /** The conversations, keyed by the two participants. */
    private function seedRemotes(User $owner): void
    {
        foreach (self::REMOTES as $index => $conversation) {
            $other = $this->accountFor($conversation['with']);

            if ($other === null) {
                continue;
            }

            $exists = Remote::query()
                ->whereHas('participants', fn ($query) => $query->where('users.id', $owner->id))
                ->whereHas('participants', fn ($query) => $query->where('users.id', $other->id))
                ->exists();

            if ($exists) {
                continue;
            }

            $remote = Remote::query()->create([
                'kind' => 'direct',
                'created_by' => $other->id,
            ]);

            $remote->participants()->attach([
                $owner->id => ['last_read_at' => now()->subHours(2)],
                $other->id => ['last_read_at' => now()],
            ]);

            foreach ($conversation['messages'] as $offset => $message) {
                RemoteMessage::query()->create([
                    'remote_id' => $remote->id,
                    'user_id' => $message['from'] === 'owner' ? $owner->id : $other->id,
                    'body' => $message['body'],
                    'created_at' => now()->subHours(4 - $offset)->subMinutes($index * 10),
                ]);
            }
        }
    }

    /** The account behind a persona handle. */
    private function accountFor(string $handle): ?User
    {
        return User::query()
            ->whereRaw('lower(handle) = ?', [mb_strtolower(LogIdentity::handle($handle))])
            ->first();
    }
}
