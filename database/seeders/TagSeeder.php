<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    /**
     * Seed the tag vocabulary used across posts.
     */
    public function run(): void
    {
        $tags = [
            'laravel', 'php', 'livewire', 'alpine-js', 'tailwind-css',
            'postgresql', 'mysql', 'sqlite', 'redis', 'eloquent',
            'docker', 'kubernetes', 'github-actions', 'ci-cd', 'deployment',
            'testing', 'pest', 'phpstan', 'static-analysis',
            'performance', 'caching', 'queues', 'horizon', 'concurrency',
            'security', 'authentication', 'oauth', 'xss',
            'observability', 'opentelemetry', 'logging', 'monitoring',
            'api-design', 'rest', 'http', 'webhooks',
            'refactoring', 'technical-debt', 'code-review', 'mentoring',
        ];

        foreach ($tags as $slug) {
            Tag::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => str($slug)->replace('-', ' ')->title()->value()],
            );
        }
    }
}
