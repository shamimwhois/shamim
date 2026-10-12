<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Order matters:
     *   1. The author account — PostSeeder resolves its author from the first user.
     *   2. Taxonomies  — posts reference categories and tags by name during creation.
     *   3. Posts       — created through the real CreatePost action so every derived
     *                    column (body_html, body_text, word_count, reading_time,
     *                    excerpt) is populated exactly as it would be in production.
     *   4. Comments    — attached to published posts so the moderation queue and the
     *                    threaded detail view both have realistic data.
     */
    public function run(): void
    {
        $this->call(UserSeeder::class);

        $this->call([
            CategorySeeder::class,
            TagSeeder::class,
            PostSeeder::class,
            CommentSeeder::class,
        ]);

        $this->command->info(sprintf(
            'Seeded %d users, %d categories, %d tags, %d posts, %d comments.',
            User::query()->count(),
            Category::query()->count(),
            Tag::query()->count(),
            Post::query()->count(),
            Comment::query()->count(),
        ));
    }
}
