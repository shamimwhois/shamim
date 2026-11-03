<?php

namespace Database\Seeders;

use App\Enums\CommunityKind;
use App\Enums\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\User;
use App\Support\FeedPersonas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seed the community directory and its memberships.
 *
 * Six communities across the four feed topics; two are invite-only so the
 * private-content paths have something real to guard. Demo member accounts
 * are created deterministically by email with `firstOrCreate`, so re-running
 * the seeder never duplicates people, and the seeded author account (from
 * {@see UserSeeder}, listed first) owns the flagship community.
 *
 * The people are not invented here. Each roster entry is a handle into
 * {@see FeedPersonas} — the same registry the feed cards and the corpus rows
 * read — so a name in the member list and the byline on that person's post
 * cannot disagree, and the handle reaches `users.handle` instead of being
 * dropped on the floor the way it used to be.
 */
class CommunitySeeder extends Seeder
{
    /**
     * The personas who hold community memberships.
     *
     * A deliberate subset of the registry, keyed by bare handle: the corpus has
     * ten authors, and only these six are needed to populate the directory's
     * member lists and own the two private communities.
     *
     * @var array<int, string>
     */
    private const ROSTER = ['priya', 'danielo', 'nadia_p', 'kenji', 'aisha', 'rayhan'];

    public function run(): void
    {
        $users = $this->demoUsers();

        $communities = [
            ['slug' => 'backend-engineers', 'name' => 'Backend Engineers', 'description' => 'APIs, queues and the databases behind them. Bring your worst production story.', 'topic' => 'backend', 'glyph' => 'BE', 'kind' => CommunityKind::Open, 'rules' => 'Ship working code, cite your benchmarks, and no framework wars.'],
            ['slug' => 'frontend-craft', 'name' => 'Frontend Craft', 'description' => 'Components, state and the last pixel. Designer-client developers welcome.', 'topic' => 'frontend', 'glyph' => 'FC', 'kind' => CommunityKind::Open, 'rules' => null],
            ['slug' => 'toolshed', 'name' => 'The Toolshed', 'description' => 'CLI tools, pipelines and everything that makes the rest faster.', 'topic' => 'tooling', 'glyph' => 'TS', 'kind' => CommunityKind::Open, 'rules' => null],
            ['slug' => 'guild-hall', 'name' => 'Guild Hall', 'description' => 'Meta: how we moderate, what we seed, and which posts float.', 'topic' => 'community', 'glyph' => 'GH', 'kind' => CommunityKind::Open, 'rules' => 'Treat the moderators\' decisions as final and their reasons as public.'],
            ['slug' => 'the-dark-panel', 'name' => 'The Dark Panel', 'description' => 'Production incident post-mortems, shared under an explicit expectation of confidentiality.', 'topic' => 'backend', 'glyph' => 'DP', 'kind' => CommunityKind::InviteOnly, 'rules' => 'Everything shared here stays here. No quotes, no screenshots.'],
            ['slug' => 'the-mentorship-ring', 'name' => 'The Mentorship Ring', 'description' => 'Private pairing circle for migration code reviews.', 'topic' => 'community', 'glyph' => 'MR', 'kind' => CommunityKind::InviteOnly, 'rules' => null],
        ];

        foreach ($communities as $index => $entry) {
            $community = Community::query()->updateOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name' => $entry['name'],
                    'description' => $entry['description'],
                    'topic' => $entry['topic'],
                    'glyph' => $entry['glyph'],
                    'kind' => $entry['kind'],
                    'rules' => $entry['rules'],
                ],
            );

            $this->membersFor($index, $users, $community);
        }
    }

    /**
     * Attach the owner and a slice of the demo personas to one community.
     *
     * @param  array{owner: User, personas: array<int, User>}  $users
     */
    private function membersFor(int $index, array $users, Community $community): void
    {
        // The seeded author account (first user) owns every community except
        // the private ones, which the demo personas run themselves.
        if ($community->kind === CommunityKind::Open) {
            CommunityMembership::query()->firstOrCreate([
                'community_id' => $community->id,
                'user_id' => $users['owner']->id,
            ], [
                'role' => CommunityRole::Owner,
                'joined_at' => now()->subDays(30),
            ]);
        }

        foreach (array_slice($users['personas'], $index % 3, 4) as $personaIndex => $persona) {
            CommunityMembership::query()->firstOrCreate([
                'community_id' => $community->id,
                'user_id' => $persona->id,
            ], [
                'role' => $community->kind === CommunityKind::InviteOnly && $personaIndex === 0
                    ? CommunityRole::Owner
                    : CommunityRole::Member,
                'joined_at' => now()->subDays(30 - $personaIndex * 3),
            ]);
        }
    }

    /**
     * Resolve the owner account and create the deterministic demo personas.
     *
     * @return array{owner: User, personas: array<int, User>}
     */
    private function demoUsers(): array
    {
        /** @var User|null $owner */
        $owner = User::query()->orderBy('id')->first();

        if ($owner === null) {
            // CommunitySeeder always runs after UserSeeder, but a direct
            // seeder invocation should still work end to end.
            $owner = User::factory()->admin()->create();
        }

        $personas = [];

        foreach (self::ROSTER as $handle) {
            $persona = FeedPersonas::findByHandle($handle);

            if ($persona === null) {
                continue;
            }

            $user = User::query()->firstOrCreate(
                ['email' => $persona['email']],
                [
                    'name' => $persona['name'],
                    'handle' => $persona['handle'],
                    'password' => Hash::make('password'),
                ],
            );

            // An account created before this registry existed still carries the
            // name it was seeded with. Rename it rather than let the directory
            // and the cards disagree about who @kenji is.
            if ($user->name !== $persona['name'] || $user->handle !== $persona['handle']) {
                $user->update(['name' => $persona['name'], 'handle' => $persona['handle']]);
            }

            $personas[] = $user;
        }

        // Never let a persona collide with the fixture accounts that ship
        // with the app (shamimstack@gmail.com and user@example.com).
        $personas = array_values(array_filter(
            $personas,
            fn (User $user): bool => ! in_array($user->email, ['shamimstack@gmail.com', 'seller@example.com', 'user@example.com'], true),
        ));

        return ['owner' => $owner, 'personas' => $personas];
    }
}
