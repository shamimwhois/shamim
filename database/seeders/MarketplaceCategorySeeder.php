<?php

namespace Database\Seeders;

use App\Models\MarketplaceCategory;
use Illuminate\Database\Seeder;

/**
 * Seeds the marketplace taxonomy.
 *
 * Categories are operator data, not code: the catalogue reads them at runtime,
 * so the seeder only provides a sensible starting set. Slugs are stable and
 * used by the seeder for products, so renaming a slug here breaks the product
 * corpus — add a new category instead.
 */
class MarketplaceCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Laravel Packages',
                'slug' => 'laravel-packages',
                'description' => 'Composer packages that extend the framework itself: model concerns, query helpers and infrastructure.',
                'icon' => 'cube',
                'sort_order' => 1,
            ],
            [
                'name' => 'Laravel Plugins',
                'slug' => 'laravel-plugins',
                'description' => 'Drop-in behaviour for an existing application — flags, billing hooks, integrations and admin tooling.',
                'icon' => 'puzzle-piece',
                'sort_order' => 2,
            ],
            [
                'name' => 'Admin Templates',
                'slug' => 'admin-templates',
                'description' => 'Complete dashboard shells and page blocks for back-office products.',
                'icon' => 'squares-2x2',
                'sort_order' => 3,
            ],
            [
                'name' => 'UI Components',
                'slug' => 'ui-components',
                'description' => 'Individual interface primitives and composed patterns, built to be dropped into a design system.',
                'icon' => 'swatch',
                'sort_order' => 4,
            ],
            [
                'name' => 'Starter Kits',
                'slug' => 'starter-kits',
                'description' => 'Opinionated project scaffolds with authentication, billing and deployment already wired.',
                'icon' => 'rocket-launch',
                'sort_order' => 5,
            ],
            [
                'name' => 'Developer Tools',
                'slug' => 'developer-tools',
                'description' => 'Debugging surfaces, inspectors and command-line utilities that make the working day shorter.',
                'icon' => 'command-line',
                'sort_order' => 6,
            ],
            [
                'name' => 'JavaScript Libraries',
                'slug' => 'javascript-libraries',
                'description' => 'Framework-agnostic browser libraries, from data tables to drag-and-drop primitives.',
                'icon' => 'code-bracket',
                'sort_order' => 7,
            ],
            [
                'name' => 'AI Tools',
                'slug' => 'ai-tools',
                'description' => 'Prompt libraries, agent scaffolds and evaluation harnesses for model-backed features.',
                'icon' => 'sparkles',
                'sort_order' => 8,
            ],
        ];

        foreach ($categories as $category) {
            MarketplaceCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['is_active' => true],
            );
        }
    }
}
