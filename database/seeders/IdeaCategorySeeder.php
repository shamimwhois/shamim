<?php

namespace Database\Seeders;

use App\Enums\AccountPermission;
use App\Models\IdeaCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a starting set of idea categories for every account that may use the
 * Ideas module.
 *
 * Categories belong to an account, so this cannot be one flat list like the
 * marketplace taxonomy: each holder gets its own copy of the same five folders,
 * keyed on `(user_id, slug)` and written with `updateOrCreate`. Re-running the
 * seeder therefore renames nothing and duplicates nothing, which is what makes it
 * safe to leave in `DatabaseSeeder` rather than behind a flag.
 *
 * No ideas are seeded here. The workspace is judged on rows a person actually
 * captured, and a demo inbox of invented thoughts teaches a reviewer nothing
 * about the module while making the counts on the dashboard lie.
 */
class IdeaCategorySeeder extends Seeder
{
    /**
     * @return array<int, array{name: string, slug: string, description: string}>
     */
    public static function defaults(): array
    {
        return [
            [
                'name' => 'Product',
                'slug' => 'product',
                'description' => 'Changes to the thing itself: features worth building and features worth refusing.',
            ],
            [
                'name' => 'Engineering',
                'slug' => 'engineering',
                'description' => 'Infrastructure, tooling and technical debt that pays for itself later.',
            ],
            [
                'name' => 'Audience',
                'slug' => 'audience',
                'description' => 'How more people arrive, stay, and come back.',
            ],
            [
                'name' => 'Experiments',
                'slug' => 'experiments',
                'description' => 'Small, reversible bets. If it works it moves to Product.',
            ],
            [
                'name' => 'Someday',
                'slug' => 'someday',
                'description' => 'Worth keeping, not worth starting. Revisit quarterly.',
            ],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = User::query()->get()
            ->filter(fn (User $user) => $user->hasPermission(AccountPermission::UseIdeas));

        foreach ($accounts as $user) {
            foreach (self::defaults() as $category) {
                IdeaCategory::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'slug' => $category['slug'],
                    ],
                    [
                        'name' => $category['name'],
                        'description' => $category['description'],
                    ],
                );
            }
        }
    }
}
