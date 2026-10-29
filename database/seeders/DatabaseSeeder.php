<?php

namespace Database\Seeders;

use App\Models\AboutItem;
use App\Models\AboutSection;
use App\Models\AiArticle;
use App\Models\AiLearningPath;
use App\Models\AiResource;
use App\Models\AiTopic;
use App\Models\Category;
use App\Models\Comment;
use App\Models\FeedPost;
use App\Models\IdeaCategory;
use App\Models\LabSnippet;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceReview;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Topic;
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
     *   5. Feed posts  — the community feed corpus; every feed surface reads the
     *                    model, so the corpus is seeded, not hardcoded at render.
     *   6. Marketplace — categories first, then products (which resolve their
     *                    category by slug) and the reviews attached to them.
     *   7. About content — sections and rows mirroring the config defaults, so the
     *                    admin console opens with the live copy ready to edit.
     *   8. AI Knowledge Hub — topics first (the taxonomy everything is filed
     *                    under), then articles, paths, and resources written
     *                    through the same save actions the console uses.
     *   9. Public topics — the platform-owned taxonomy both modules file against.
     *                    It precedes the lab examples because those examples
     *                    advertise themselves by topic, and a chip row cannot be
     *                    born pointing at subjects that do not exist yet.
     *  10. Ideas — the per-account category folders, which need the users to
     *                    exist first and seed nothing else: an inbox is the
     *                    account's own.
     *  11. Developer Lab — the four example snippets, which need an author and
     *                    the publish action, and are the only rows the lab ships
     *                    pre-seeded because workspaces are scratch buffers.
     */
    public function run(): void
    {
        $this->call(UserSeeder::class);

        $this->call([
            CategorySeeder::class,
            TagSeeder::class,
            PostSeeder::class,
            CommentSeeder::class,
            FeedPostSeeder::class,
            FeedReplySeeder::class,
            LogActivitySeeder::class,
        ]);

        $this->call([
            MarketplaceSeeder::class,
            AboutContentSeeder::class,
            AiHubSeeder::class,
            TopicsSeeder::class,
            IdeaCategorySeeder::class,
            LabSnippetSeeder::class,
            PaymentProviderSeeder::class,
        ]);

        $this->command->info(sprintf(
            'Seeded %d users, %d categories, %d tags, %d posts, %d comments, '
            .'%d feed posts, %d marketplace categories, %d products, %d reviews, '
            .'%d about sections, %d about rows, '
            .'%d hub topics, %d hub articles, %d learning paths, %d resources, '
            .'%d topics, %d idea categories, %d lab snippets.',
            User::query()->count(),
            Category::query()->count(),
            Tag::query()->count(),
            Post::query()->count(),
            Comment::query()->count(),
            FeedPost::query()->count(),
            MarketplaceCategory::query()->count(),
            MarketplaceProduct::query()->count(),
            MarketplaceReview::query()->count(),
            AboutSection::query()->count(),
            AboutItem::query()->count(),
            AiTopic::query()->count(),
            AiArticle::query()->count(),
            AiLearningPath::query()->count(),
            AiResource::query()->count(),
            Topic::query()->count(),
            IdeaCategory::query()->count(),
            LabSnippet::query()->count(),
        ));
    }
}
