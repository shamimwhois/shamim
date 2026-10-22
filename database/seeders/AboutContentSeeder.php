<?php

namespace Database\Seeders;

use App\Enums\AboutItemType;
use App\Enums\AboutSectionType;
use App\Models\AboutItem;
use App\Models\AboutSection;
use Illuminate\Database\Seeder;

/**
 * Seeds the About page content so the admin console opens with the live
 * copy already on the table.
 *
 * Every row intentionally mirrors what `config/portfolio.php` and the view's
 * built-in prose already render: the database layer takes over from the
 * fallback layer without changing a single pixel, and the operator edits from
 * real content instead of an empty screen. Values seeded here are safe to
 * change in Administration → About; the config remains the fresh-install
 * default.
 *
 * Idempotent: sections are keyed by their anchor `key`, rows by section +
 * title, so re-running updates rather than duplicates. The Experience tree is
 * re-linked on each pass because role/highlight rows need their parent's id.
 */
class AboutContentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $author = config('portfolio.author');

        $sections = [
            ['key' => 'about-hero', 'type' => AboutSectionType::Hero, 'ordinal' => 1, 'label' => 'Intro', 'heading' => $author['role'], 'lead' => $author['tagline'], 'body' => $author['availability'], 'icon' => null],
            ['key' => 'about-reasons', 'type' => AboutSectionType::Reasons, 'ordinal' => 2, 'label' => 'Why me', 'heading' => 'Why hire me', 'lead' => null, 'body' => null, 'icon' => null],
            ['key' => 'about-principles', 'type' => AboutSectionType::Principles, 'ordinal' => 4, 'label' => 'How I work', 'heading' => 'The things I keep coming back to', 'lead' => 'These are the six defaults learned the expensive way — when a decision is hard, they are what break the tie.', 'body' => null, 'icon' => null],
            ['key' => 'about-expertise', 'type' => AboutSectionType::Expertise, 'ordinal' => 5, 'label' => 'Capabilities', 'heading' => 'Capabilities', 'lead' => null, 'body' => null, 'icon' => null],
            ['key' => 'about-experience', 'type' => AboutSectionType::Experience, 'ordinal' => 6, 'label' => 'Experience', 'heading' => 'Experience', 'lead' => null, 'body' => null, 'icon' => null],
            ['key' => 'about-work', 'type' => AboutSectionType::Work, 'ordinal' => 7, 'label' => 'Work', 'heading' => 'Selected work', 'lead' => null, 'body' => null, 'icon' => null],
            ['key' => 'about-stats', 'type' => AboutSectionType::Stats, 'ordinal' => 8, 'label' => 'By the numbers', 'heading' => null, 'lead' => null, 'body' => null, 'icon' => null],
            ['key' => 'about-focus', 'type' => AboutSectionType::Focus, 'ordinal' => 9, 'label' => 'Current focus', 'heading' => null, 'lead' => null, 'body' => null, 'icon' => null],
            ['key' => 'about-contact', 'type' => AboutSectionType::Contact, 'ordinal' => 10, 'label' => 'Contact', 'heading' => 'Get in touch', 'lead' => null, 'body' => null, 'icon' => null],
            ['key' => 'about-ai-workflow', 'type' => AboutSectionType::AiWorkflow, 'ordinal' => 3, 'label' => 'AI Workflow', 'heading' => 'AI Workflow', 'lead' => 'From task trigger to cloud deployment. Coordinate AI agents, track execution, and visualize your entire software delivery pipeline.', 'body' => 'Coordinate agents, automate testing, and ship with confidence.', 'icon' => null],
        ];

        $models = [];

        foreach ($sections as $definition) {
            $models[$definition['type']->value] = AboutSection::query()->updateOrCreate(
                ['key' => $definition['key']],
                $definition + ['sort_order' => 0, 'is_visible' => true],
            );
        }

        $this->seedTitleBody($models['reasons'], $this->defaultReasons());
        $this->seedTitleBody($models['principles'], $this->defaultPrinciples());
        $this->seedExpertise($models['expertise'], $author['expertise']);
        $this->seedWork($models['work'], $author['projects']);
        $this->seedStats($models['stats'], $author['stats']);
        $this->seedExperience($models['experience'], $author['experience']);
        $this->seedContact($models['contact'], $author['links']);
        $this->seedFocus($models['focus'], $author);
        $this->seedWorkflow($models['ai_workflow']);
    }

    /**
     * @param  array<int, array{title: string, body: string}>  $entries
     */
    private function seedTitleBody(AboutSection $section, array $entries): void
    {
        foreach ($this->ordering($entries) as $index => $entry) {
            AboutItem::query()->updateOrCreate(
                ['about_section_id' => $section->id, 'title' => $entry['title']],
                [
                    'type' => AboutItemType::Entry,
                    'body' => $entry['body'],
                    'sort_order' => $index,
                    'is_visible' => true,
                ],
            );
        }
    }

    /**
     * @param  array<int, array{title: string, description: string, icon: string, tags: array<int, string>}>  $entries
     */
    private function seedExpertise(AboutSection $section, array $entries): void
    {
        foreach ($this->ordering($entries) as $index => $entry) {
            AboutItem::query()->updateOrCreate(
                ['about_section_id' => $section->id, 'title' => $entry['title']],
                [
                    'type' => AboutItemType::Entry,
                    'body' => $entry['description'],
                    'icon' => $entry['icon'],
                    'tags' => implode(', ', $entry['tags']),
                    'sort_order' => $index,
                    'is_visible' => true,
                ],
            );
        }
    }

    /**
     * @param  array<int, array{name: string, role: string, summary: string, outcome: string, stack: array<int, string>, url: string|null}>  $entries
     */
    private function seedWork(AboutSection $section, array $entries): void
    {
        foreach ($this->ordering($entries) as $index => $entry) {
            AboutItem::query()->updateOrCreate(
                ['about_section_id' => $section->id, 'title' => $entry['name']],
                [
                    'type' => AboutItemType::Entry,
                    'subtitle' => $entry['role'],
                    'body' => $entry['summary'],
                    'value' => $entry['outcome'],
                    'tags' => implode(', ', $entry['stack']),
                    'url' => $entry['url'] ?? null,
                    'url_label' => 'View project',
                    'sort_order' => $index,
                    'is_visible' => true,
                ],
            );
        }
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $entries
     */
    private function seedStats(AboutSection $section, array $entries): void
    {
        foreach ($this->ordering($entries) as $index => $entry) {
            AboutItem::query()->updateOrCreate(
                ['about_section_id' => $section->id, 'title' => $entry['label']],
                [
                    'type' => AboutItemType::Entry,
                    'value' => $entry['value'],
                    'sort_order' => $index,
                    'is_visible' => true,
                ],
            );
        }
    }

    /**
     * @param  array<string, array{label: string, url: string|null, handle: string|null}>  $links
     */
    private function seedContact(AboutSection $section, array $links): void
    {
        $index = 0;

        foreach ($links as $link) {
            AboutItem::query()->updateOrCreate(
                // The presenter keys channels by the slugified title, so the
                // seeded title must stay the channel name itself.
                ['about_section_id' => $section->id, 'title' => $link['label']],
                [
                    'type' => AboutItemType::Entry,
                    'url' => $link['url'],
                    'value' => $link['handle'],
                    'sort_order' => $index++,
                    'is_visible' => true,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $author
     */
    private function seedFocus(AboutSection $section, array $author): void
    {
        $entries = [
            ['label' => 'Primary stack', 'value' => 'Laravel, PHP 8.5, PostgreSQL, Redis'],
            ['label' => 'Comfortable in', 'value' => 'TypeScript, Go, Kubernetes, Terraform'],
            ['label' => 'Worked on', 'value' => 'Payments, developer tooling, public APIs'],
            ['label' => 'Based in', 'value' => $author['location']],
            ['label' => 'Experience', 'value' => $author['years_of_experience'].' years'],
            ['label' => 'Looking for', 'value' => 'Full stack developer roles'],
        ];

        $this->seedStats($section, $entries);
    }

    /**
     * Seed the employer → role → highlight tree.
     *
     * @param  array<int, array{company: string, period: string, roles: array<int, array{title: string, period: string, highlights: array<int, array{text: string, stack: array<int, string>}>}>}>  $entries
     */
    private function seedExperience(AboutSection $section, array $entries): void
    {
        foreach ($this->ordering($entries) as $employerIndex => $employer) {
            $employerRow = AboutItem::query()->updateOrCreate(
                ['about_section_id' => $section->id, 'title' => $employer['company']],
                [
                    'type' => AboutItemType::Employer,
                    'period' => $employer['period'],
                    'sort_order' => $employerIndex,
                    'is_visible' => true,
                ],
            );

            foreach ($this->ordering($employer['roles']) as $roleIndex => $role) {
                $roleRow = AboutItem::query()->updateOrCreate(
                    ['about_section_id' => $section->id, 'title' => $role['title']],
                    [
                        'type' => AboutItemType::Role,
                        'parent_id' => $employerRow->id,
                        'period' => $role['period'],
                        'sort_order' => $roleIndex,
                        'is_visible' => true,
                    ],
                );

                foreach ($this->ordering($role['highlights']) as $highlightIndex => $highlight) {
                    AboutItem::query()->updateOrCreate(
                        ['about_section_id' => $section->id, 'title' => $highlight['text']],
                        [
                            'type' => AboutItemType::Highlight,
                            'parent_id' => $roleRow->id,
                            'tags' => implode(', ', $highlight['stack']),
                            'sort_order' => $highlightIndex,
                            'is_visible' => true,
                        ],
                    );
                }
            }
        }
    }

    /**
     * Seed the AI Workflow dashboard rows — the same demo the presenter
     * falls back to, written into the generic item columns per the
     * documented workflow semantics (task: title = intent, subtitle =
     * identifier, value = stage, period = agent, url_label = priority;
     * agent: title = name, body = role; metric: subtitle = band).
     */
    private function seedWorkflow(AboutSection $section): void
    {
        $tasks = [
            ['#SH-1240', 'Redesign navbar profile', 'Improve avatar menu, account actions, and mobile navigation.', 'trigger', 'Feature', ['Laravel', 'UI/UX'], 'Task queue'],
            ['#SH-1239', 'Fix mobile navigation', 'Resolve a responsive menu issue on small screens.', 'trigger', 'Bug', ['Frontend', 'Mobile'], 'Task queue'],
            ['#SH-1237', 'Optimize database queries', 'Inspect slow queries and propose improvements.', 'planning', 'Planning', ['Performance', 'Database'], 'Planner agent'],
            ['#SH-1234', 'User authentication module', 'Implement token authentication and refresh logic.', 'encoding', 'Coding', ['Backend', 'Security'], 'Coder agent'],
            ['#SH-1232', 'Dashboard analytics', 'Create reusable analytics cards and metrics.', 'encoding', 'Coding', ['Analytics', 'Frontend'], 'Coder agent'],
            ['#SH-1230', 'Run automated test suite', 'Execute unit, feature, and browser tests.', 'review', 'Testing', ['Testing', 'CI/CD'], 'Tester agent'],
            ['#SH-1225', 'Deploy application', 'Prepare release and verify cloud infrastructure.', 'deploy', 'Deploy', ['Cloud', 'Release'], 'Deploy agent'],
        ];

        foreach ($this->ordering($tasks) as $index => [$id, $title, $description, $stage, $priority, $tags, $agent]) {
            AboutItem::query()->updateOrCreate(
                ['about_section_id' => $section->id, 'title' => $title],
                [
                    'type' => AboutItemType::WorkflowTask,
                    'subtitle' => $id,
                    'body' => $description,
                    'value' => $stage,
                    'period' => $agent,
                    'url_label' => $priority,
                    'tags' => implode(', ', $tags),
                    'sort_order' => $index,
                    'is_visible' => true,
                ],
            );
        }

        $agents = [
            ['Research Agent', 'Analyzes codebase'],
            ['Planner Agent', 'Builds implementation plan'],
            ['Coder Agent', 'Writes & refactors code'],
            ['Tester Agent', 'Runs automated tests'],
            ['Review Agent', 'Reviews code quality'],
        ];

        foreach ($this->ordering($agents) as $index => [$name, $description]) {
            AboutItem::query()->updateOrCreate(
                ['about_section_id' => $section->id, 'title' => $name],
                [
                    'type' => AboutItemType::WorkflowAgent,
                    'body' => $description,
                    'sort_order' => $index,
                    'is_visible' => true,
                ],
            );
        }

        // sort_order drives the tone rotation (0 cyan, 1 green, 2 neutral),
        // so telemetry rows continue the header sequence rather than reset it.
        $metrics = [
            ['header', 'Active agents', '08'],
            ['header', 'Tasks completed', '248'],
            ['header', 'Success rate', '98.6%'],
            ['telemetry', 'Agents running', '05'],
            ['telemetry', 'Parallel jobs', '12'],
            ['telemetry', 'Pipeline health', 'Healthy'],
        ];

        foreach ($this->ordering($metrics) as $index => [$band, $label, $value]) {
            AboutItem::query()->updateOrCreate(
                ['about_section_id' => $section->id, 'title' => $label],
                [
                    'type' => AboutItemType::WorkflowMetric,
                    'subtitle' => $band,
                    'value' => $value,
                    'sort_order' => $index,
                    'is_visible' => true,
                ],
            );
        }
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $entries
     * @return array<int, T>
     */
    private function ordering(array $entries): array
    {
        return array_values($entries);
    }

    /**
     * The built-in principles prose — duplicated from AboutContent on purpose:
     * the fallback must keep living in code for fresh installs, while the
     * seeded rows give the console something real to edit.
     *
     * @return array<int, array{title: string, body: string}>
     */
    private function defaultPrinciples(): array
    {
        return [
            ['title' => 'Make the wrong thing hard to do', 'body' => 'A convention nobody can violate beats a convention everyone agrees with. Constraints that live in the type system, the query layer, or the build pipeline keep working when the team is tired.'],
            ['title' => 'Read the query plan before arguing about the abstraction', 'body' => 'Most performance debates are really debates about measurements nobody took. The database will tell you what is slow if you ask it directly.'],
            ['title' => 'Write it down while the reasoning is still recoverable', 'body' => 'An architecture decision record costs twenty minutes and saves the next engineer a week of archaeology. The value is not the decision — it is the constraint that forced it.'],
            ['title' => 'Delete code before adding a flag', 'body' => 'Every configuration branch doubles the surface you have to test. Most features are not optional; they are just unloved, and the honest move is removal.'],
            ['title' => 'Optimise for the second year', 'body' => 'Shipping is the easy part. Almost everything that makes a system expensive later was a reasonable decision made in the first month.'],
            ['title' => 'Leave the codebase friendlier than you found it', 'body' => 'Not a rewrite. One clearer name, one test that was missing, one comment that explains why rather than what.'],
        ];
    }

    /**
     * The built-in "why hire me" prose.
     *
     * @return array<int, array{title: string, body: string}>
     */
    private function defaultReasons(): array
    {
        return [
            ['title' => 'I close loops', 'body' => 'Most teams do not lack talent; they lack someone who finishes. Feature, tests, docs, deploy, monitor — the work is done when the pager stays quiet, not when the pull request merges.'],
            ['title' => 'Hiring for year two, not the launch', 'body' => 'Almost everything that makes a system expensive later was a reasonable decision in month one. You get the migrations, dashboards and runbooks that still read well when the original author is on holiday.'],
            ['title' => 'Backend depth where it fails at 3am', 'body' => 'Postgres query plans, Redis semantics, queue backpressure, cache invalidation — the parts of an application that break overnight are the parts I have spent eight-plus years specialising in.'],
            ['title' => 'Breadth at the edges, on purpose', 'body' => 'TypeScript, Go, Kubernetes and Terraform where the job needs them — enough to own a problem from model to ingress without pretending to be a specialist in six stacks at once.'],
            ['title' => 'Writes like an engineer', 'body' => 'Design docs, decision records and incident reviews the next engineer can follow without a walkthrough. The writing on this site is the same habit, not a performance of it.'],
            ['title' => 'Honest by default', 'body' => 'If a feature should not exist, I say so before you pay for it. The most expensive consultant in the room is the one whose answer is always yes.'],
        ];
    }
}
