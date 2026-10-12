<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the site owner account.
 *
 * PostSeeder resolves its author from the first user, so this must run before it.
 * Credentials are intentionally the same as Laravel's starter default so the
 * documented `composer setup` path lands on a known login.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => config('portfolio.author.name', 'Test User'),
            'email' => 'test@example.com',
        ]);
    }
}
