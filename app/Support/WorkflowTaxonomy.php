<?php

namespace App\Support;

use App\Services\AboutContent;

/**
 * The AI Workflow dashboard's fixed vocabulary — one registry, three consumers.
 *
 * `docs/AI-WORKFLOW.md` makes the pipeline a *contract*: five stages in a fixed
 * order, each with an owning role and an exit gate, and a palette where cyan is
 * machine intent, green is human-verified and amber is the one hue that means a
 * human must act. Before this registry those five names lived in three places
 * (the Blade component's `$stages`, the presenter's stage whitelist, and the
 * seeder's string literals), which is exactly how a board and its graph start
 * disagreeing about what "review" means.
 *
 * So the vocabulary is recorded here once and read by {@see AboutContent},
 * the public component and the admin console. Nothing in this class touches the
 * database: it is a value list, and the callers decide what to store.
 *
 * The visual treatment (Tailwind classes) deliberately stays in the component —
 * this class owns *meaning*, not styling.
 *
 * @phpstan-type WorkflowStage array{id: string, name: string, description: string, owner: string, gate: string, trust: string}
 */
final class WorkflowTaxonomy
{
    /**
     * The five pipeline stages, in the only order they may appear.
     *
     * `trust` is the accent the stage earns, not a decoration: `neutral` is
     * unprocessed intake, `cyan` is machine intent, `green` is human-verified.
     * `gate` is the condition that lets a card leave the column — the admin
     * console shows it so an operator knows what "moving" a card actually runs.
     *
     * @var array<int, WorkflowStage>
     */
    public const STAGES = [
        [
            'id' => 'trigger',
            'name' => 'Task Trigger',
            'description' => 'Capture ideas & issues',
            'owner' => 'Ingress',
            'gate' => 'Intent parsed, repository resolved',
            'trust' => 'neutral',
        ],
        [
            'id' => 'planning',
            'name' => 'AI Planning',
            'description' => 'Analyze & plan solution',
            'owner' => 'Planner',
            'gate' => 'Plan decomposed, risk scored',
            'trust' => 'cyan',
        ],
        [
            'id' => 'encoding',
            'name' => 'Multi-Agent Encoding',
            'description' => 'Parallel code generation',
            'owner' => 'Encoder pool',
            'gate' => 'Every step produces a diff, build passes',
            'trust' => 'cyan',
        ],
        [
            'id' => 'review',
            'name' => 'Review & Testing',
            'description' => 'Verify quality & security',
            'owner' => 'Reviewer + tests',
            'gate' => 'Analysis and suites green, sign-off captured',
            'trust' => 'green',
        ],
        [
            'id' => 'deploy',
            'name' => 'Cloud Deployment',
            'description' => 'Release to production',
            'owner' => 'Deployer',
            'gate' => 'Canary healthy, rollback armed',
            'trust' => 'green',
        ],
    ];

    /**
     * The stage a task lands in when its stored value is missing or wrong.
     *
     * A typo must never strand a card in a column that does not exist, so an
     * unrecognised stage degrades to intake rather than throwing.
     */
    public const FALLBACK_STAGE = 'trigger';

    /**
     * Agent node states. Glow is state, never style (spec §2 W2), so this is
     * what decides whether a node pulses, sits dim, or turns amber.
     *
     * @var array<string, array{label: string, tone: string, description: string}>
     */
    public const STATUSES = [
        'active' => [
            'label' => 'Active',
            'tone' => 'cyan',
            'description' => 'Holding work right now — its edge pulses.',
        ],
        'idle' => [
            'label' => 'Idle',
            'tone' => 'neutral',
            'description' => 'Available, no task in flight — dim, no pulse.',
        ],
        'blocked' => [
            'label' => 'Blocked',
            'tone' => 'amber',
            'description' => 'Waiting on a human — amber edge, no pulse.',
        ],
    ];

    /**
     * The metric bands the dashboard renders in two places.
     *
     * @var array<string, string>
     */
    public const BANDS = [
        'header' => 'Header band',
        'telemetry' => 'Telemetry strip',
    ];

    /**
     * The only tones a metric value may carry (spec §6 two-accent discipline).
     *
     * @var array<string, string>
     */
    public const TONES = [
        'cyan' => 'Cyan — machine intent',
        'green' => 'Green — human verified',
        'neutral' => 'Neutral — plain figure',
        'amber' => 'Amber — needs attention',
    ];

    /**
     * Suggested priorities. Free text is still allowed — an operator may need a
     * label this list has not met yet — but choosing from here keeps the tone
     * mapping predictable instead of accidental.
     *
     * @var array<int, string>
     */
    public const PRIORITIES = ['Feature', 'Bug', 'Planning', 'Coding', 'Testing', 'Deploy', 'Release', 'Blocked'];

    /**
     * Every stage, in pipeline order.
     *
     * @return array<int, WorkflowStage>
     */
    public static function stages(): array
    {
        return self::STAGES;
    }

    /**
     * The stage identifiers, in pipeline order.
     *
     * @return array<int, string>
     */
    public static function stageIds(): array
    {
        return array_column(self::STAGES, 'id');
    }

    /**
     * Stage id => "01 · Task Trigger", for a `<select>`.
     *
     * @return array<string, string>
     */
    public static function stageOptions(): array
    {
        $options = [];

        foreach (self::STAGES as $index => $stage) {
            $options[$stage['id']] = sprintf('%02d · %s', $index + 1, $stage['name']);
        }

        return $options;
    }

    /**
     * One stage by id, or null when the id is not part of the pipeline.
     *
     * @return WorkflowStage|null
     */
    public static function stage(string $id): ?array
    {
        foreach (self::STAGES as $stage) {
            if ($stage['id'] === $id) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Coerce a stored stage value onto the pipeline.
     */
    public static function normaliseStage(?string $value): string
    {
        $stage = strtolower(trim((string) $value));

        return in_array($stage, self::stageIds(), true) ? $stage : self::FALLBACK_STAGE;
    }

    /**
     * A stage's display name, falling back to the raw value.
     */
    public static function stageName(string $id): string
    {
        return self::stage($id)['name'] ?? ucfirst($id);
    }

    /**
     * Zero-based position of a stage, used for progress bands and ordering.
     */
    public static function stageIndex(string $id): int
    {
        $index = array_search(self::normaliseStage($id), self::stageIds(), true);

        return $index === false ? 0 : $index;
    }

    /**
     * Status id => label, for a `<select>`.
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return array_map(fn (array $status): string => $status['label'], self::STATUSES);
    }

    /**
     * Coerce a stored status onto a known state; unknown values read as active
     * because an agent with a typo is still an agent holding work.
     */
    public static function normaliseStatus(?string $value): string
    {
        $status = strtolower(trim((string) $value));

        return array_key_exists($status, self::STATUSES) ? $status : 'active';
    }

    /**
     * The tone a status renders in.
     */
    public static function statusTone(string $status): string
    {
        return self::STATUSES[self::normaliseStatus($status)]['tone'];
    }

    /**
     * The human label of a status.
     */
    public static function statusLabel(string $status): string
    {
        return self::STATUSES[self::normaliseStatus($status)]['label'];
    }

    /**
     * Band id => label, for a `<select>`.
     *
     * @return array<string, string>
     */
    public static function bandOptions(): array
    {
        return self::BANDS;
    }

    /**
     * Coerce a stored band; anything unknown lands in the header band.
     */
    public static function normaliseBand(?string $value): string
    {
        $band = strtolower(trim((string) $value));

        return array_key_exists($band, self::BANDS) ? $band : 'header';
    }

    /**
     * Tone id => label, for a `<select>`.
     *
     * @return array<string, string>
     */
    public static function toneOptions(): array
    {
        return self::TONES;
    }

    /**
     * Coerce a stored tone. Unknown values stay cyan — the machine-intent
     * accent is the safe default, and a metric never silently turns amber.
     */
    public static function normaliseTone(?string $value): string
    {
        $tone = strtolower(trim((string) $value));

        return array_key_exists($tone, self::TONES) ? $tone : 'cyan';
    }

    /**
     * The legacy tone rotation, kept so rows written before tones were stored
     * keep rendering with the accent they had.
     */
    public static function legacyTone(int $sortOrder): string
    {
        return match ($sortOrder % 3) {
            1 => 'green',
            2 => 'neutral',
            default => 'cyan',
        };
    }

    /**
     * Map a priority label onto the palette: amber for work a human must act
     * on, green for work that has cleared a human gate, cyan for everything
     * else. This is the board's only colour decision (spec §6).
     */
    public static function toneForPriority(string $priority): string
    {
        return match (strtolower(trim($priority))) {
            'bug', 'blocked', 'urgent', 'hotfix', 'security', 'warning', 'failed' => 'amber',
            'deploy', 'release', 'verified', 'reviewed', 'done', 'complete', 'shipped' => 'green',
            default => 'cyan',
        };
    }
}
