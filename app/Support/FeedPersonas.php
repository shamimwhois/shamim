<?php

namespace App\Support;

/**
 * The community feed's author identities — one registry, four consumers.
 *
 * Every corpus post in {@see FeedCorpus} carries a denormalised identity
 * (author / handle / avatar / role) because a feed card may not afford a user
 * eager load. Before this registry that identity was repeated in four places
 * and had already drifted: the corpus said `Kenji Sato · @kenji · KS` while the
 * community seeder's persona list said `Kenji Watanabe`, and the corpus's
 * `@danielo` was seeded as `@daniel`.
 *
 * So the corpus values — the text every visitor actually reads on a published
 * card — are recorded here once, and `UserSeeder`, `CommunitySeeder`,
 * `FeedPostSeeder` and `feed:simulate` all read them. Nothing in this class
 * touches the database: it is a value list, and the seeders decide what to do
 * with it.
 *
 * Handles are stored **bare**, without the leading `@`, because that is the
 * shape the `users.handle` column holds; {@see findByHandle()} accepts either
 * spelling so a corpus row reading `@kenji` resolves to the same persona.
 *
 * @phpstan-type FeedPersona array{handle: string, name: string, email: string, avatar: string, role: string}
 */
final class FeedPersonas
{
    /**
     * The ten corpus authors, in the order they first appear in the feed.
     *
     * `email` is the account key: the six personas the community directory
     * already seeded keep the addresses they were created with, so switching to
     * this registry renames those accounts without orphaning their memberships.
     * `Md Shamim Hossain` is the seeded author account
     * (`shamimstack@gmail.com`), which is why the corpus's own showcase post has
     * a real login behind it.
     *
     * @return array<string, FeedPersona>
     */
    public static function all(): array
    {
        return [
            'shamimstack' => [
                'handle' => 'shamimstack', 'name' => 'Md Shamim Hossain', 'email' => 'shamimstack@gmail.com',
                'avatar' => 'MS', 'role' => 'Full Stack Developer · Laravel',
            ],
            'priya' => [
                'handle' => 'priya', 'name' => 'Priya Menon', 'email' => 'priya@example.com',
                'avatar' => 'PM', 'role' => 'Design engineer',
            ],
            'kenji' => [
                'handle' => 'kenji', 'name' => 'Kenji Sato', 'email' => 'kenji@example.com',
                'avatar' => 'KS', 'role' => 'Frontend engineer · CSS tooling',
            ],
            'danielo' => [
                'handle' => 'danielo', 'name' => 'Daniel Okafor', 'email' => 'daniel@example.com',
                'avatar' => 'DO', 'role' => 'Backend engineer · Queues',
            ],
            'aisha' => [
                'handle' => 'aisha', 'name' => 'Aisha Rahman', 'email' => 'aisha@example.com',
                'avatar' => 'AR', 'role' => 'Maintainer · starter-kit',
            ],
            'tomas' => [
                'handle' => 'tomas', 'name' => 'Tomás Herrera', 'email' => 'tomas@example.com',
                'avatar' => 'TH', 'role' => 'Platform engineer',
            ],
            'rayhan' => [
                'handle' => 'rayhan', 'name' => 'Rayhan Kabir', 'email' => 'rayhan@example.com',
                'avatar' => 'RK', 'role' => 'Systems engineer · Rust',
            ],
            'sarah_ts' => [
                'handle' => 'sarah_ts', 'name' => 'Sarah Thorne', 'email' => 'sarah@example.com',
                'avatar' => 'ST', 'role' => 'TypeScript lead',
            ],
            'kevin_ai' => [
                'handle' => 'kevin_ai', 'name' => 'Kevin Ahn', 'email' => 'kevin@example.com',
                'avatar' => 'KA', 'role' => 'ML engineer · Edge inference',
            ],
            'nadia_p' => [
                'handle' => 'nadia_p', 'name' => 'Nadia Petrova', 'email' => 'nadia@example.com',
                'avatar' => 'NP', 'role' => 'Community host',
            ],
        ];
    }

    /**
     * Look one persona up by handle, with or without the leading `@`.
     *
     * The corpus writes `@kenji`; the column stores `kenji`. Both are the same
     * person, so the lookup normalises rather than keeping two keys per author.
     *
     * @return FeedPersona|null
     */
    public static function findByHandle(?string $handle): ?array
    {
        if ($handle === null || trim($handle) === '') {
            return null;
        }

        $key = ltrim(trim($handle), '@');

        return self::all()[$key] ?? null;
    }

    /**
     * Look one persona up by the email its account is seeded with.
     *
     * @return FeedPersona|null
     */
    public static function findByEmail(?string $email): ?array
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        foreach (self::all() as $persona) {
            if (strcasecmp($persona['email'], trim($email)) === 0) {
                return $persona;
            }
        }

        return null;
    }

    /**
     * The `@`-prefixed handle, the way the feed renders it on a card.
     */
    public static function displayedHandle(string $handle): string
    {
        return '@'.ltrim($handle, '@');
    }
}
