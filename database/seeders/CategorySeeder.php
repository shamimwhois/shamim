<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Seed the author's topic taxonomy.
     *
     * These are real engineering topics rather than filler, because the blog
     * index renders them as the primary navigation affordance.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Architecture',
                'slug' => 'architecture',
                'description' => 'Service boundaries, domain modelling, and the trade-offs that decide whether a system ages well.',
                'color' => 'teal',
            ],
            [
                'name' => 'Backend',
                'slug' => 'backend',
                'description' => 'Application design, HTTP surfaces, queued work, and the parts of a request that are easy to get wrong.',
                'color' => 'cyan',
            ],
            [
                'name' => 'Databases',
                'slug' => 'databases',
                'description' => 'Schema design, indexing strategy, query plans, and migrations that stay reversible.',
                'color' => 'violet',
            ],
            [
                'name' => 'Frontend',
                'slug' => 'frontend',
                'description' => 'Server-driven interfaces, component design, and when a SPA is the wrong answer.',
                'color' => 'rose',
            ],
            [
                'name' => 'DevOps',
                'slug' => 'devops',
                'description' => 'Deployment pipelines, observability, and building the guardrails that stop incidents before they start.',
                'color' => 'amber',
            ],
            [
                'name' => 'Career',
                'slug' => 'career',
                'description' => 'Technical leadership, mentoring, interviewing, and the parts of engineering that are not code.',
                'color' => 'lime',
            ],
        ];

        foreach ($categories as $category) {
            Category::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category,
            );
        }
    }
}
