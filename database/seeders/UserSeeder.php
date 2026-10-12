<?php

namespace Database\Seeders;

use App\Enums\AccountRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the accounts used by the seeded application.
 *
 * Three accounts, one per role, so every dashboard in the access model is
 * reachable immediately after `composer setup` without hand-editing the
 * database. The author account keeps Laravel's starter default credentials so
 * the documented setup path lands on a known login; the seller and admin
 * accounts are additional and clearly labelled.
 *
 * PostSeeder resolves its author from the first user, so the admin account is
 * listed first and owns the seeded posts.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'name' => config('portfolio.author.name', 'Test User'),
                'email' => 'test@example.com',
                'role' => AccountRole::Admin,
            ],
            [
                'name' => 'Sam Seller',
                'email' => 'seller@example.com',
                'role' => AccountRole::Seller,
            ],
            [
                'name' => 'Robin Reader',
                'email' => 'user@example.com',
                'role' => AccountRole::User,
            ],
        ];

        foreach ($accounts as $account) {
            User::factory()->create($account);
        }
    }
}
