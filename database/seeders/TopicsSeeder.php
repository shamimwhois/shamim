<?php

namespace Database\Seeders;

use App\Models\Topic;
use App\Services\PublicTaxonomy;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform-owned topic taxonomy that Ideas and the Developer Lab share.
 *
 * These are subjects, not folders: "accessibility" means the same thing to an idea
 * and to a snippet, which is why one list serves both modules. It is deliberately
 * not {@see IdeaCategorySeeder} — those rows belong to an account and stay private —
 * and not the hub's `ai_topics`, which are a hierarchy the hub owns alone.
 *
 * Keyed on slug with `updateOrCreate`, so re-running duplicates nothing and a
 * spelling fix in the shipped defaults reaches a database that was seeded last
 * month. What a re-seed deliberately does not touch is `is_active`: once a row
 * exists, the on/off switch belongs to the operator, and a seed that silently
 * switched a retired subject back on would put it back in front of the public.
 *
 * A row here is only half of being visible. The count gate in
 * {@see PublicTaxonomy} hides any topic no published row carries, so
 * seeding twelve subjects against a database with three published snippets is
 * correct: the gallery shows the one or two chips that actually open something.
 */
class TopicsSeeder extends Seeder
{
    /**
     * @return array<int, array{name: string, slug: string, description: string, icon: string, sort_order: int}>
     */
    public static function defaults(): array
    {
        return [
            [
                'name' => 'Interfaces',
                'slug' => 'interfaces',
                'description' => 'The parts of a screen a person actually touches: controls, states, and what happens next.',
                'icon' => 'squares-2x2',
                'sort_order' => 0,
            ],
            [
                'name' => 'Forms',
                'slug' => 'forms',
                'description' => 'Fields, validation, and the slow craft of not losing what somebody typed.',
                'icon' => 'pencil-square',
                'sort_order' => 1,
            ],
            [
                'name' => 'Accessibility',
                'slug' => 'accessibility',
                'description' => 'Keyboard paths, focus order, contrast, and announcements that make a page usable without a mouse.',
                'icon' => 'eye',
                'sort_order' => 2,
            ],
            [
                'name' => 'CSS',
                'slug' => 'css',
                'description' => 'Layout, cascade, and the box model arguments that never end.',
                'icon' => 'paint-brush',
                'sort_order' => 3,
            ],
            [
                'name' => 'JavaScript',
                'slug' => 'javascript',
                'description' => 'Browser-side behaviour: events, timers, fetches, and the frame you are running in.',
                'icon' => 'code-bracket',
                'sort_order' => 4,
            ],
            [
                'name' => 'PHP',
                'slug' => 'php',
                'description' => 'Server-side code, mostly Laravel, sometimes plain PHP worth knowing.',
                'icon' => 'server-stack',
                'sort_order' => 5,
            ],
            [
                'name' => 'Security',
                'slug' => 'security',
                'description' => 'Trust boundaries, escaping, sandboxing, and the failure mode you have not planned for yet.',
                'icon' => 'shield-check',
                'sort_order' => 6,
            ],
            [
                'name' => 'Performance',
                'slug' => 'performance',
                'description' => 'Queries, bytes, and milliseconds, measured before being claimed.',
                'icon' => 'bolt',
                'sort_order' => 7,
            ],
            [
                'name' => 'Testing',
                'slug' => 'testing',
                'description' => 'Pest and PHPUnit coverage that would fail for the right reason.',
                'icon' => 'beaker',
                'sort_order' => 8,
            ],
            [
                'name' => 'Tooling',
                'slug' => 'tooling',
                'description' => 'Editors, build steps, and the commands that make the working day shorter.',
                'icon' => 'wrench',
                'sort_order' => 9,
            ],
            [
                'name' => 'Data',
                'slug' => 'data',
                'description' => 'Schema, queries, and presentations of numbers that do not flatter them.',
                'icon' => 'queue-list',
                'sort_order' => 10,
            ],
            [
                'name' => 'Layout',
                'slug' => 'layout',
                'description' => 'Grids, breakpoints, and how a page holds together at every width.',
                'icon' => 'rectangle-stack',
                'sort_order' => 11,
            ],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::defaults() as $topic) {
            Topic::query()->updateOrCreate(['slug' => $topic['slug']], $topic);
        }
    }
}
