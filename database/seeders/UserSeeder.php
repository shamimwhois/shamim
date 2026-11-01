<?php

namespace Database\Seeders;

use App\Enums\AccountRole;
use App\Models\User;
use App\Support\FeedPersonas;
use Illuminate\Database\Seeder;

/**
 * Creates the accounts used by the seeded application.
 *
 * Three accounts, one per role, so every dashboard in the access model is
 * reachable immediately after `composer setup` without hand-editing the
 * database. The author account is the site owner's real account — name, email
 * and handle all match `config('portfolio.author')` — and the seller and admin
 * accounts are additional and clearly labelled.
 *
 * PostSeeder resolves its author from the first user, so the admin account is
 * listed first and owns the seeded posts. Accounts are matched by email, so a
 * second `db:seed` fills in what changed instead of crashing on the unique
 * index — and the factory still writes the row, so a seeded account is verified
 * exactly like a test account.
 *
 * The author account is also a feed persona: {@see FeedPersonas} holds its
 * handle, so a corpus post written by `Md Shamim Hossain` and the login at
 * `shamimstack@gmail.com` are the same person rather than two records that
 * happen to agree. The other two accounts have no public handle to carry.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'name' => config('portfolio.author.name', 'Test User'),
                'email' => 'shamimstack@gmail.com',
                'role' => AccountRole::Admin,
                'password' => 'password',
            ],
            [
                'name' => 'Sam Seller',
                'email' => 'seller@example.com',
                'role' => AccountRole::Seller,
                'password' => 'password',
            ],
            [
                'name' => 'Robin Reader',
                'email' => 'user@example.com',
                'role' => AccountRole::User,
                'password' => 'password',
            ],
        ];

        foreach ($accounts as $account) {
            $persona = FeedPersonas::findByEmail($account['email']);
            $handle = $persona === null ? null : $persona['handle'];

            $existing = User::query()->where('email', $account['email'])->first();

            if ($existing === null) {
                User::factory()->create([...$account, 'handle' => $handle]);

                continue;
            }

            // Re-seeding an install created before the handle column existed has
            // to fill it in, otherwise the author account keeps logging in but
            // never owns anything the feed can attribute to it.
            if ($existing->handle !== $handle || $existing->name !== $account['name']) {
                $existing->update(['name' => $account['name'], 'handle' => $handle]);
            }
        }
    }
}
