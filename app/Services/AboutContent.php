<?php

namespace App\Services;

use App\Data\AuthorProfile;
use App\Enums\AboutItemType;
use App\Enums\AboutSectionType;
use App\Models\AboutItem;
use App\Models\AboutSection;
use App\Support\ThinkingTaxonomy;
use App\Support\WorkflowTaxonomy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Presents the About page, preferring database rows over configuration.
 *
 * The page was originally assembled from `config('portfolio.*')` and prose
 * hardcoded in the view. This service keeps both as the *fallback* layer and
 * overlays any visible `about_sections` / `about_items` rows on top, so the
 * page renders identically on a fresh install (empty tables) and becomes
 * fully operator-managed the moment rows exist. Every read is memoised per
 * request — the public page asks for the same blocks several times.
 *
 * @phpstan-type WorkflowPayload array{
 *     eyebrow: string,
 *     heading: string,
 *     lead: string,
 *     cta: array{title: string, text: string},
 *     tasks: array<int, array<string, mixed>>,
 *     agents: array<int, array{name: string, description: string, status: string, statusLabel: string, statusTone: string}>,
 *     metrics: array{header: array<int, array{label: string, value: string, tone: string}>, telemetry: array<int, array{label: string, value: string, tone: string}>}
 * }
 *
 * @phpstan-type ThinkingPayload array{
 *     modes: array<string, array{key: string, name: string, description: string}>,
 *     categories: array<int, array{key: string, name: string, description: string, affinity: string, nodes: array<int, array{label: string, text: string}>}>
 * }
 */
class AboutContent
{
    /**
     * Loaded sections with their visible, ordered items.
     *
     * @var Collection<int, AboutSection>|null
     */
    protected ?Collection $sections = null;

    protected ?AuthorProfile $profile = null;

    /**
     * Memoised AI Workflow payload.
     *
     * @var WorkflowPayload|null
     */
    protected ?array $workflow = null;

    /**
     * Memoised Thinking Explorer payload.
     *
     * @var ThinkingPayload|null
     */
    protected ?array $thinking = null;

    /**
     * The author profile as the page should render it: config values with any
     * database-managed blocks (expertise, work, experience, stats, contact)
     * swapped for their rows.
     */
    public function profile(): AuthorProfile
    {
        return $this->profile ??= $this->buildProfile();
    }

    /**
     * How I work — the operating principles.
     *
     * @return array<int, array{title: string, body: string}>
     */
    public function principles(): array
    {
        return $this->titleBodyBlock(AboutSectionType::Principles, $this->defaultPrinciples());
    }

    /**
     * Why hire me — the value claims.
     *
     * @return array<int, array{title: string, body: string}>
     */
    public function reasons(): array
    {
        return $this->titleBodyBlock(AboutSectionType::Reasons, $this->defaultReasons());
    }

    /**
     * The Thinking Explorer payload: the two thinking modes, the knowledge
     * categories orbiting the brain, and each category's child concepts, with
     * database rows overlaid on the built-in defaults so the section renders
     * meaningful content before an operator touches the admin console.
     *
     * The explorer shares the *reasons* section — it IS the rework of that
     * band, so there is no second section row, no new table and no migration.
     * The row types partition the section's items (see the enum docblocks):
     *
     * - ThinkingMode: subtitle = mode key ('system_1' | 'system_2'),
     *   title = display name, body = description. A typo'd key degrades to
     *   System 1 instead of vanishing.
     * - ThinkingCategory: subtitle = key slug, title = name, body =
     *   description, url_label = mode affinity ('fast' | 'deep' | 'both').
     *   Stored rows replace the built-in category list entirely (row order is
     *   display order — and cluster order on the canvas).
     * - ThinkingNode: subtitle = parent category key, title = label,
     *   body = explanation. Stored nodes attach per category; a category
     *   with no stored nodes keeps its built-in ones.
     *
     * The generic `entry` rows in the same section remain the value claims
     * rendered by {@see reasons()} — the two contents coexist by type.
     *
     * @return ThinkingPayload
     */
    public function thinkingExplorer(): array
    {
        return $this->thinking ??= $this->buildThinkingExplorer();
    }
    /**
     * Current focus areas as label/value pairs.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function focus(): array
    {
        $profile = $this->profile();

        $fallback = [
            ['label' => 'Primary stack', 'value' => 'Laravel, PHP 8.5, PostgreSQL, Redis'],
            ['label' => 'Comfortable in', 'value' => 'TypeScript, Go, Kubernetes, Terraform'],
            ['label' => 'Worked on', 'value' => 'Payments, developer tooling, public APIs'],
            ['label' => 'Based in', 'value' => $profile->location],
            ['label' => 'Experience', 'value' => $profile->yearsOfExperience.' years'],
            ['label' => 'Looking for', 'value' => 'Full stack developer roles'],
        ];

        $items = $this->itemsFor(AboutSectionType::Focus);

        if ($items->isEmpty()) {
            return $fallback;
        }

        return $items
            ->map(fn (AboutItem $item): array => [
                'label' => $item->title,
                'value' => (string) ($item->value ?? ''),
            ])
            ->values()
            ->toArray();
    }

    /**
     * The AI Workflow dashboard payload: Kanban cards, agent graph nodes and
     * the two metric bands, with database rows overlaid on the demo defaults
     * so the surface renders meaningful data even before an operator touches
     * the admin console.
     *
     * Column semantics for the workflow row types (the schema stays generic,
     * see the enum docblocks):
     * - WorkflowTask: title = intent, subtitle = identifier, body = description,
     *   value = stage key, period = agent, url = target, url_label = priority,
     *   tags = comma-separated chips.
     * - WorkflowAgent: title = name, body = one-line role description,
     *   value = status key (active / idle / blocked).
     * - WorkflowMetric: subtitle = band ('header' or 'telemetry'),
     *   title = label, value = value, url_label = tone key.
     *
     * Every key is normalised through {@see WorkflowTaxonomy}, so a stored typo
     * degrades to a safe default instead of stranding a card in a column that
     * does not exist or painting a metric an accent it did not earn.
     *
     * @return WorkflowPayload
     */
    public function aiWorkflow(): array
    {
        return $this->workflow ??= $this->buildAiWorkflow();
    }

    /**
     * The stored section row for a type, when the operator has one.
     */
    public function section(AboutSectionType $type): ?AboutSection
    {
        return $this->sections()->firstWhere('type', $type);
    }

    /**
     * Resolve a section heading, preferring the stored row over the built-in.
     */
    public function headingFor(AboutSectionType $type, string $fallback): string
    {
        return $this->section($type)?->heading ?: $fallback;
    }

    /**
     * Resolve a scrollspy / marker label, preferring the stored row.
     */
    public function labelFor(AboutSectionType $type, string $fallback): string
    {
        return $this->section($type)?->label ?: $fallback;
    }

    /**
     * The lead paragraph for a section, preferring the stored row.
     */
    public function leadFor(AboutSectionType $type, ?string $fallback = null): ?string
    {
        return $this->section($type)?->lead ?: $fallback;
    }

    /**
     * Every visible section in page order.
     *
     * @return Collection<int, AboutSection>
     */
    public function sections(): Collection
    {
        return $this->sections ??= AboutSection::query()
            ->visible()
            ->ordered()
            ->with(['items' => fn ($query) => $query->visible()->ordered()])
            ->get();
    }

    /**
     * The visible items of one section type, in order.
     *
     * @return Collection<int, AboutItem>
     */
    protected function itemsFor(AboutSectionType $type): Collection
    {
        $section = $this->section($type);

        return $section === null ? new Collection : $section->items;
    }

    /**
     * Overlay the stored thinking rows on the built-in vocabulary. Every key
     * passes through {@see ThinkingTaxonomy} first, so a stored typo degrades
     * to a safe default instead of stranding a mode, orphaning a category or
     * breaking the canvas cluster lookup.
     *
     * @return ThinkingPayload
     */
    protected function buildThinkingExplorer(): array
    {
        $modes = ThinkingTaxonomy::modes();

        /** @var array<string, array{key: string, name: string, description: string, affinity: string, nodes: array<int, array{label: string, text: string}>}> $categoryByKey */
        $categoryByKey = [];
        foreach (ThinkingTaxonomy::categories() as $category) {
            $categoryByKey[$category['key']] = $category;
        }

        $storedModes = [];
        $storedCategories = [];
        $storedNodes = [];

        foreach ($this->itemsFor(AboutSectionType::Reasons) as $item) {
            match ($item->type) {
                AboutItemType::ThinkingMode => $storedModes[] = $item,
                AboutItemType::ThinkingCategory => $storedCategories[] = $item,
                AboutItemType::ThinkingNode => $storedNodes[] = $item,
                default => null,
            };
        }

        foreach ($storedModes as $item) {
            $key = ThinkingTaxonomy::normalizeModeKey($item->subtitle);

            $modes[$key] = [
                'key' => $key,
                'name' => $item->title,
                'description' => (string) ($item->body ?? ''),
            ];
        }

        if ($storedCategories !== []) {
            $categoryByKey = [];

            foreach ($storedCategories as $index => $item) {
                $key = ThinkingTaxonomy::normalizeCategoryKey($item->subtitle, $index);

                $categoryByKey[$key] = [
                    'key' => $key,
                    'name' => $item->title,
                    'description' => (string) ($item->body ?? ''),
                    'affinity' => ThinkingTaxonomy::normalizeAffinity($item->url_label),
                    'nodes' => [],
                ];
            }
        }

        $storedNodesByKey = [];

        foreach ($storedNodes as $item) {
            $key = ThinkingTaxonomy::normalizeCategoryKey($item->subtitle, 0);

            $storedNodesByKey[$key][] = [
                'label' => $item->title,
                'text' => (string) ($item->body ?? ''),
            ];
        }

        $categories = array_values(array_map(
            function (array $category) use ($storedNodesByKey): array {
                $category['nodes'] = $storedNodesByKey[$category['key']] ?? $category['nodes'];

                return $category;
            },
            $categoryByKey,
        ));

        /** @var ThinkingPayload $payload */
        $payload = ['modes' => $modes, 'categories' => $categories];

        return $payload;
    }

    /**
     * Map a flat section's rows to title/body pairs, falling back when empty.
     *
     * Only generic `entry` rows count: a section may also carry typed rows
     * (the reasons section hosts the Thinking Explorer's mode/category/node
     * rows), and those must never leak into a flat title/body block.
     *
     * @param  array<int, array{title: string, body: string}>  $fallback
     * @return array<int, array{title: string, body: string}>
     */
    protected function titleBodyBlock(AboutSectionType $type, array $fallback): array
    {
        $items = $this->itemsFor($type)->filter(
            fn (AboutItem $item): bool => $item->type === AboutItemType::Entry,
        );

        if ($items->isEmpty()) {
            return $fallback;
        }

        return $items
            ->map(fn (AboutItem $item): array => [
                'title' => $item->title,
                'body' => (string) ($item->body ?? ''),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Assemble the profile, swapping config blocks for database rows.
     */
    protected function buildProfile(): AuthorProfile
    {
        $config = AuthorProfile::fromConfig();
        $hero = $this->section(AboutSectionType::Hero);

        return new AuthorProfile(
            name: $config->name,
            role: $hero?->heading ?: $config->role,
            tagline: $hero?->lead ?: $config->tagline,
            location: $config->location,
            availability: $hero?->body ?: $config->availability,
            bio: $config->bio,
            longBio: $config->longBio,
            yearsOfExperience: $config->yearsOfExperience,
            expertise: $this->expertiseBlock($config->expertise),
            projects: $this->workBlock($config->projects),
            experience: $this->experienceBlock($config->experience),
            stats: $this->statsBlock($config->stats),
            links: $this->contactBlock($config->links),
        );
    }

    /**
     * @param  array<int, array{title: string, description: string, icon: string, tags: array<int, string>}>  $fallback
     * @return array<int, array{title: string, description: string, icon: string, tags: array<int, string>}>
     */
    protected function expertiseBlock(array $fallback): array
    {
        $items = $this->itemsFor(AboutSectionType::Expertise);

        if ($items->isEmpty()) {
            return $fallback;
        }

        return $items
            ->map(fn (AboutItem $item): array => [
                'title' => $item->title,
                'description' => (string) ($item->body ?? ''),
                'icon' => $item->icon ?: 'cube',
                'tags' => $item->tagList(),
            ])
            ->values()
            ->toArray();
    }

    /**
     * @param  array<int, array{name: string, role: string, summary: string, outcome: string, stack: array<int, string>, url: string|null}>  $fallback
     * @return array<int, array{name: string, role: string, summary: string, outcome: string, stack: array<int, string>, url: string|null}>
     */
    protected function workBlock(array $fallback): array
    {
        $items = $this->itemsFor(AboutSectionType::Work);

        if ($items->isEmpty()) {
            return $fallback;
        }

        return $items
            ->map(fn (AboutItem $item): array => [
                'name' => $item->title,
                'role' => (string) ($item->subtitle ?? ''),
                'summary' => (string) ($item->body ?? ''),
                'outcome' => (string) ($item->value ?? ''),
                'stack' => $item->tagList(),
                'url' => $item->url,
            ])
            ->values()
            ->toArray();
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $fallback
     * @return array<int, array{label: string, value: string}>
     */
    protected function statsBlock(array $fallback): array
    {
        $items = $this->itemsFor(AboutSectionType::Stats);

        if ($items->isEmpty()) {
            return $fallback;
        }

        return $items
            ->map(fn (AboutItem $item): array => [
                'label' => $item->title,
                'value' => (string) ($item->value ?? ''),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Rebuild the experience timeline from the employer → role → highlight tree.
     *
     * @param  array<int, array{company: string, period: string, roles: array<int, array{title: string, period: string, highlights: array<int, array{text: string, stack: array<int, string>}>}>}>  $fallback
     * @return array<int, array{company: string, period: string, roles: array<int, array{title: string, period: string, highlights: array<int, array{text: string, stack: array<int, string>}>}>}>
     */
    protected function experienceBlock(array $fallback): array
    {
        $items = $this->itemsFor(AboutSectionType::Experience);

        if ($items->isEmpty()) {
            return $fallback;
        }

        // Filtering the already-loaded, ordered collection keeps the tree walk
        // free of extra queries; parents resolve before children by sort order.
        return $items
            ->where('type', AboutItemType::Employer)
            ->map(function (AboutItem $employer) use ($items): array {
                $roles = $items
                    ->where('parent_id', $employer->id)
                    ->where('type', AboutItemType::Role)
                    ->map(function (AboutItem $role) use ($items): array {
                        $highlights = $items
                            ->where('parent_id', $role->id)
                            ->map(fn (AboutItem $highlight): array => [
                                'text' => $highlight->title,
                                'stack' => $highlight->tagList(),
                            ])
                            ->values()
                            ->toArray();

                        return [
                            'title' => $role->title,
                            'period' => (string) ($role->period ?? ''),
                            'highlights' => $highlights,
                        ];
                    })
                    ->values()
                    ->toArray();

                return [
                    'company' => $employer->title,
                    'period' => (string) ($employer->period ?? ''),
                    'roles' => $roles,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * @param  array<string, array{label: string, url: string|null, handle: string|null}>  $fallback
     * @return array<string, array{label: string, url: string|null, handle: string|null}>
     */
    protected function contactBlock(array $fallback): array
    {
        $items = $this->itemsFor(AboutSectionType::Contact);

        if ($items->isEmpty()) {
            return $fallback;
        }

        $links = $fallback;

        foreach ($items as $item) {
            $key = Str::slug(Str::lower($item->title));

            $links[$key] = [
                'label' => $item->subtitle ?: $item->title,
                'url' => $item->url,
                'handle' => $item->value,
            ];
        }

        return $links;
    }

    /**
     * Assemble the AI Workflow payload, preferring stored rows per block.
     *
     * @return WorkflowPayload
     */
    protected function buildAiWorkflow(): array
    {
        $section = $this->section(AboutSectionType::AiWorkflow);
        $items = $this->itemsFor(AboutSectionType::AiWorkflow);
        $defaults = $this->defaultWorkflow();

        $tasks = $items
            ->where('type', AboutItemType::WorkflowTask)
            ->map(fn (AboutItem $item): array => $this->workflowTaskRow($item))
            ->values();

        $agents = $items
            ->where('type', AboutItemType::WorkflowAgent)
            ->map(function (AboutItem $item): array {
                $status = WorkflowTaxonomy::normaliseStatus($item->value);

                return [
                    'name' => $item->title,
                    'description' => (string) ($item->body ?? ''),
                    'status' => $status,
                    'statusLabel' => WorkflowTaxonomy::statusLabel($status),
                    'statusTone' => WorkflowTaxonomy::statusTone($status),
                ];
            })
            ->values();

        return [
            'eyebrow' => $defaults['eyebrow'],
            'heading' => $section?->heading ?: $defaults['heading'],
            'lead' => $section?->lead ?: $defaults['lead'],
            'cta' => [
                'title' => $defaults['cta']['title'],
                'text' => $section?->body ?: $defaults['cta']['text'],
            ],
            'tasks' => $tasks->isEmpty() ? $defaults['tasks'] : $tasks->toArray(),
            'agents' => $agents->isEmpty() ? $defaults['agents'] : $agents->take(5)->values()->toArray(),
            'metrics' => [
                'header' => $this->workflowMetricBand($items, 'header', $defaults['metrics']['header']),
                'telemetry' => $this->workflowMetricBand($items, 'telemetry', $defaults['metrics']['telemetry']),
            ],
        ];
    }

    /**
     * One metric band: the stored rows, or the demo band when the operator has
     * written none.
     *
     * The stored tone wins. Rows written before tones were captured keep the
     * rotation they were seeded with, so an upgrade does not repaint a board
     * an operator had already signed off.
     *
     * @param  Collection<int, AboutItem>  $items
     * @param  array<int, array{label: string, value: string, tone: string}>  $fallback
     * @return array<int, array{label: string, value: string, tone: string}>
     */
    protected function workflowMetricBand(Collection $items, string $band, array $fallback): array
    {
        $rows = $items
            ->where('type', AboutItemType::WorkflowMetric)
            ->filter(fn (AboutItem $item): bool => WorkflowTaxonomy::normaliseBand($item->subtitle) === $band)
            ->map(fn (AboutItem $item): array => [
                'label' => $item->title,
                'value' => (string) ($item->value ?? ''),
                'tone' => filled($item->url_label)
                    ? WorkflowTaxonomy::normaliseTone($item->url_label)
                    : WorkflowTaxonomy::legacyTone($item->sort_order),
            ])
            ->values()
            ->all();

        return $rows === [] ? $fallback : $rows;
    }

    /**
     * Normalise one stored task row into the shape the board expects. The
     * stage column is validated against the fixed pipeline so a typo cannot
     * strand a card in a column that does not exist.
     *
     * @return array<string, mixed>
     */
    protected function workflowTaskRow(AboutItem $item): array
    {
        $priority = (string) ($item->url_label ?: 'Feature');

        return [
            'id' => (string) ($item->subtitle ?: '#T-'.$item->id),
            'title' => $item->title,
            'description' => (string) ($item->body ?? ''),
            'stage' => WorkflowTaxonomy::normaliseStage($item->value),
            'agent' => (string) ($item->period ?: 'Task queue'),
            'target' => $item->url,
            'priority' => $priority,
            'tone' => $this->workflowToneFor($priority),
            'tags' => $item->tagList(),
        ];
    }

    /**
     * Map a priority label onto the two-accent-plus-warning palette defined
     * in docs/AI-WORKFLOW.md §6: amber is reserved for work a human must act
     * on, green for work that has cleared a human gate, cyan for machine
     * intent. The rule itself lives in {@see WorkflowTaxonomy} so the console
     * can preview the same decision the board will render.
     */
    protected function workflowToneFor(string $priority): string
    {
        return WorkflowTaxonomy::toneForPriority($priority);
    }

    /**
     * The built-in Thinking Explorer flattened into persistable rows, for the
     * console's "seed defaults" action — same contract as the workflow rows:
     * seeding writes exactly what a fresh install renders, so the fallback and
     * the database can never drift into two different copies.
     *
     * @return array<int, array{type: AboutItemType, title: string, subtitle: string|null, body: string|null, value: string|null, period: string|null, url: string|null, url_label: string|null, tags: string|null, sort_order: int}>
     */
    public function thinkingDefaultRows(): array
    {
        $rows = [];
        $order = 0;

        foreach (ThinkingTaxonomy::modes() as $mode) {
            $rows[] = [
                'type' => AboutItemType::ThinkingMode,
                'title' => $mode['name'],
                'subtitle' => $mode['key'],
                'body' => $mode['description'],
                'value' => null,
                'period' => null,
                'url' => null,
                'url_label' => null,
                'tags' => null,
                'sort_order' => $order++,
            ];
        }

        foreach (ThinkingTaxonomy::categories() as $category) {
            $rows[] = [
                'type' => AboutItemType::ThinkingCategory,
                'title' => $category['name'],
                'subtitle' => $category['key'],
                'body' => $category['description'],
                'value' => null,
                'period' => null,
                'url' => null,
                'url_label' => $category['affinity'],
                'tags' => null,
                'sort_order' => $order++,
            ];

            foreach ($category['nodes'] as $node) {
                $rows[] = [
                    'type' => AboutItemType::ThinkingNode,
                    'title' => $node['label'],
                    'subtitle' => $category['key'],
                    'body' => $node['text'],
                    'value' => null,
                    'period' => null,
                    'url' => null,
                    'url_label' => null,
                    'tags' => null,
                    'sort_order' => $order++,
                ];
            }
        }

        return $rows;
    }

    /**
     * The built-in board flattened into persistable rows, for the console's
     * "seed from defaults" action.
     * The mapping from the demo shape onto the generic item columns lives here,
     * next to the demo itself, so an operator seeding a board gets exactly what
     * a fresh install renders — not a second, drifting copy of it inside the
     * admin view.
     *
     * @return array<int, array{type: AboutItemType, title: string, subtitle: string|null, body: string|null, value: string|null, period: string|null, url: string|null, url_label: string|null, tags: string|null, sort_order: int}>
     */
    public function workflowDefaultRows(): array
    {
        $defaults = $this->defaultWorkflow();

        /** @var array<int, array<string, mixed>> $tasks */
        $tasks = $defaults['tasks'];

        /** @var array<int, array<string, mixed>> $agents */
        $agents = $defaults['agents'];

        /** @var array{header: array<int, array<string, mixed>>, telemetry: array<int, array<string, mixed>>} $metrics */
        $metrics = $defaults['metrics'];

        $rows = [];
        $order = 0;

        foreach ($tasks as $task) {
            $rows[] = [
                'type' => AboutItemType::WorkflowTask,
                'title' => (string) $task['title'],
                'subtitle' => (string) $task['id'],
                'body' => (string) $task['description'],
                'value' => (string) $task['stage'],
                'period' => (string) $task['agent'],
                'url' => null,
                'url_label' => (string) $task['priority'],
                'tags' => implode(', ', (array) $task['tags']),
                'sort_order' => $order++,
            ];
        }

        foreach ($agents as $agent) {
            $rows[] = [
                'type' => AboutItemType::WorkflowAgent,
                'title' => (string) $agent['name'],
                'subtitle' => null,
                'body' => (string) $agent['description'],
                'value' => (string) $agent['status'],
                'period' => null,
                'url' => null,
                'url_label' => null,
                'tags' => null,
                'sort_order' => $order++,
            ];
        }

        foreach (['header', 'telemetry'] as $band) {
            foreach ($metrics[$band] as $metric) {
                $rows[] = [
                    'type' => AboutItemType::WorkflowMetric,
                    'title' => (string) $metric['label'],
                    'subtitle' => $band,
                    'body' => null,
                    'value' => (string) $metric['value'],
                    'period' => null,
                    'url' => null,
                    'url_label' => (string) $metric['tone'],
                    'tags' => null,
                    'sort_order' => $order++,
                ];
            }
        }

        return $rows;
    }

    /**
     * The built-in dashboard demo — the fresh-install rendering and the shape
     * the seeder mirrors, held in code so the surface never renders empty.
     *
     * @return WorkflowPayload
     */
    protected function defaultWorkflow(): array
    {
        $task = fn (string $id, string $title, string $description, string $stage, string $priority, array $tags, string $agent): array => [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'stage' => $stage,
            'agent' => $agent,
            'target' => null,
            'priority' => $priority,
            'tone' => $this->workflowToneFor($priority),
            'tags' => $tags,
        ];

        return [
            'eyebrow' => 'Autonomous Engineering',
            'heading' => 'AI Workflow',
            'lead' => 'From task trigger to cloud deployment. Coordinate AI agents, track execution, and visualize your entire software delivery pipeline.',
            'cta' => [
                'title' => 'From idea to production, autonomously.',
                'text' => 'Coordinate agents, automate testing, and ship with confidence.',
            ],
            'tasks' => [
                $task('#SH-1240', 'Redesign navbar profile', 'Improve avatar menu, account actions, and mobile navigation.', 'trigger', 'Feature', ['Laravel', 'UI/UX'], 'Task queue'),
                $task('#SH-1239', 'Fix mobile navigation', 'Resolve a responsive menu issue on small screens.', 'trigger', 'Bug', ['Frontend', 'Mobile'], 'Task queue'),
                $task('#SH-1237', 'Optimize database queries', 'Inspect slow queries and propose improvements.', 'planning', 'Planning', ['Performance', 'Database'], 'Planner agent'),
                $task('#SH-1234', 'User authentication module', 'Implement token authentication and refresh logic.', 'encoding', 'Coding', ['Backend', 'Security'], 'Coder agent'),
                $task('#SH-1232', 'Dashboard analytics', 'Create reusable analytics cards and metrics.', 'encoding', 'Coding', ['Analytics', 'Frontend'], 'Coder agent'),
                $task('#SH-1230', 'Run automated test suite', 'Execute unit, feature, and browser tests.', 'review', 'Testing', ['Testing', 'CI/CD'], 'Tester agent'),
                $task('#SH-1225', 'Deploy application', 'Prepare release and verify cloud infrastructure.', 'deploy', 'Deploy', ['Cloud', 'Release'], 'Deploy agent'),
            ],
            'agents' => [
                ['name' => 'Research Agent', 'description' => 'Analyzes codebase', 'status' => 'active', 'statusLabel' => 'Active', 'statusTone' => 'cyan'],
                ['name' => 'Planner Agent', 'description' => 'Builds implementation plan', 'status' => 'active', 'statusLabel' => 'Active', 'statusTone' => 'cyan'],
                ['name' => 'Coder Agent', 'description' => 'Writes & refactors code', 'status' => 'active', 'statusLabel' => 'Active', 'statusTone' => 'cyan'],
                ['name' => 'Tester Agent', 'description' => 'Runs automated tests', 'status' => 'idle', 'statusLabel' => 'Idle', 'statusTone' => 'neutral'],
                ['name' => 'Review Agent', 'description' => 'Reviews code quality', 'status' => 'blocked', 'statusLabel' => 'Blocked', 'statusTone' => 'amber'],
            ],
            'metrics' => [
                'header' => [
                    ['label' => 'Active agents', 'value' => '08', 'tone' => 'cyan'],
                    ['label' => 'Tasks completed', 'value' => '248', 'tone' => 'green'],
                    ['label' => 'Success rate', 'value' => '98.6%', 'tone' => 'neutral'],
                ],
                'telemetry' => [
                    ['label' => 'Agents running', 'value' => '05', 'tone' => 'cyan'],
                    ['label' => 'Parallel jobs', 'value' => '12', 'tone' => 'green'],
                    ['label' => 'Pipeline health', 'value' => 'Healthy', 'tone' => 'neutral'],
                ],
            ],
        ];
    }

    /**
     * The built-in principles prose, used until an operator manages them.
     *
     * @return array<int, array{title: string, body: string}>
     */
    protected function defaultPrinciples(): array
    {
        return [
            [
                'title' => 'Make the wrong thing hard to do',
                'body' => 'A convention nobody can violate beats a convention everyone agrees with. Constraints that live in the type system, the query layer, or the build pipeline keep working when the team is tired.',
            ],
            [
                'title' => 'Read the query plan before arguing about the abstraction',
                'body' => 'Most performance debates are really debates about measurements nobody took. The database will tell you what is slow if you ask it directly.',
            ],
            [
                'title' => 'Write it down while the reasoning is still recoverable',
                'body' => 'An architecture decision record costs twenty minutes and saves the next engineer a week of archaeology. The value is not the decision — it is the constraint that forced it.',
            ],
            [
                'title' => 'Delete code before adding a flag',
                'body' => 'Every configuration branch doubles the surface you have to test. Most features are not optional; they are just unloved, and the honest move is removal.',
            ],
            [
                'title' => 'Optimise for the second year',
                'body' => 'Shipping is the easy part. Almost everything that makes a system expensive later was a reasonable decision made in the first month.',
            ],
            [
                'title' => 'Leave the codebase friendlier than you found it',
                'body' => 'Not a rewrite. One clearer name, one test that was missing, one comment that explains why rather than what.',
            ],
        ];
    }

    /**
     * The built-in "why hire me" prose, used until an operator manages it.
     *
     * @return array<int, array{title: string, body: string}>
     */
    protected function defaultReasons(): array
    {
        return [
            [
                'title' => 'I close loops',
                'body' => 'Most teams do not lack talent; they lack someone who finishes. Feature, tests, docs, deploy, monitor — the work is done when the pager stays quiet, not when the pull request merges.',
            ],
            [
                'title' => 'Hiring for year two, not the launch',
                'body' => 'Almost everything that makes a system expensive later was a reasonable decision in month one. You get the migrations, dashboards and runbooks that still read well when the original author is on holiday.',
            ],
            [
                'title' => 'Backend depth where it fails at 3am',
                'body' => 'Postgres query plans, Redis semantics, queue backpressure, cache invalidation — the parts of an application that break overnight are the parts I have spent eight-plus years specialising in.',
            ],
            [
                'title' => 'Breadth at the edges, on purpose',
                'body' => 'TypeScript, Go, Kubernetes and Terraform where the job needs them — enough to own a problem from model to ingress without pretending to be a specialist in six stacks at once.',
            ],
            [
                'title' => 'Writes like an engineer',
                'body' => 'Design docs, decision records and incident reviews the next engineer can follow without a walkthrough. The writing on this site is the same habit, not a performance of it.',
            ],
            [
                'title' => 'Honest by default',
                'body' => 'If a feature should not exist, I say so before you pay for it. The most expensive consultant in the room is the one whose answer is always yes.',
            ],
        ];
    }
}
