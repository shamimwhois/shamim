{{-- AI Workflow dashboard panel — docs/AI-WORKFLOW.md
     Stack: Laravel Blade + Tailwind CSS (aw-* tokens) + Alpine.js
     The section header (ordinal, heading, rule, lead) renders on the page
     canvas in ⚡about with the shared reveal animations; THIS component is
     only the instrument panel below it. The panel is themed rather than
     dark-only: its `aw-*` roles resolve to the page's own ink and accent
     scales, so the accent picker retunes it and both themes read correctly
     (docs/AI-WORKFLOW.md section 6). Content comes from
     AboutContent::aiWorkflow() via props; the five pipeline columns and the
     graph geometry are a fixed rendering contract (spec §3/§4/§5) and stay
     here.

     Compactness rules for this file (redesign brief §2):
     - no fixed column heights, no empty stage filler;
     - every paint that reaches an SVG attribute carries a literal
       fallback — an undefined custom property renders SVG fill as
       BLACK, which is the "black node" bug, so `var(x, hex)` is
       mandatory wherever a token reaches a presentation attribute;
     - SVG filter/gradient ids are namespaced per instance. --}}
@props([
    'eyebrow' => 'Autonomous Engineering',
    'cta' => ['title' => 'From idea to production, autonomously.', 'text' => 'Coordinate agents, automate testing, and ship with confidence.'],
    'tasks' => [],
    'agents' => [],
    'metrics' => ['header' => [], 'telemetry' => []],
])

@php
    // The five columns are the pipeline itself (spec §4): fixed order, fixed
    // exit gates. Names and gates come from App\Support\WorkflowTaxonomy so the
    // board, the graph and the admin console cannot drift apart; only the
    // visual treatment — accent intensity per stage's trust state — lives here.
    // Cyan is machine intent, green is human-verified, navy is unprocessed
    // intake.
    $stageVisuals = [
        'trigger' => ['border' => 'border-aw-line', 'background' => 'bg-aw-raised/50', 'badge' => 'bg-aw-raised text-aw-ink-muted', 'progressColor' => 'bg-aw-ink-faint'],
        'planning' => ['border' => 'border-aw-accent/25', 'background' => 'bg-aw-accent/5', 'badge' => 'bg-aw-accent text-aw-on-accent', 'progressColor' => 'bg-aw-accent'],
        'encoding' => ['border' => 'border-aw-accent/45', 'background' => 'bg-aw-accent/8', 'badge' => 'bg-aw-accent text-aw-on-accent animate-pulse motion-reduce:animate-none', 'progressColor' => 'bg-aw-accent'],
        'review' => ['border' => 'border-aw-verified/25', 'background' => 'bg-aw-verified/5', 'badge' => 'bg-aw-verified text-aw-on-accent', 'progressColor' => 'bg-aw-verified'],
        'deploy' => ['border' => 'border-aw-verified/45', 'background' => 'bg-aw-verified/8', 'badge' => 'bg-aw-verified text-aw-on-accent', 'progressColor' => 'bg-aw-verified'],
    ];

    $stages = array_map(
        fn (array $stage): array => $stage + $stageVisuals[$stage['id']],
        \App\Support\WorkflowTaxonomy::stages(),
    );

    $progressBands = ['trigger' => 10, 'planning' => 35, 'encoding' => 60, 'review' => 80, 'deploy' => 90, 'complete' => 100];

    // Metric value colours per tone keyword (spec §6 two-accent discipline).
    $metricClasses = ['cyan' => 'text-aw-accent-soft', 'green' => 'text-aw-verified', 'neutral' => 'text-aw-ink', 'amber' => 'text-aw-warning'];

    $boardTasks = collect($tasks)
        ->map(fn (array $task): array => $task + ['progress' => $progressBands[$task['stage'] ?? 'trigger'] ?? 0])
        ->values()
        ->all();

    // Seed the numbering so demo-created tasks continue after the highest
    // stored identifier instead of colliding with it.
    $nextId = 1241;
    foreach ($boardTasks as $taskRow) {
        if (preg_match('/(\d+)$/s', (string) ($taskRow['id'] ?? ''), $digits)) {
            $nextId = max($nextId, (int) $digits[1] + 1);
        }
    }

    // Demo event stream, labelled as demo on the page (brief §6: no fake
    // "live" metrics presented as production data).
    $logs = [
        ['time' => '14:23:12', 'agent' => 'Orchestrator', 'message' => 'Task #SH-1234 started.', 'color' => 'text-aw-accent-soft'],
        ['time' => '14:23:15', 'agent' => 'Research', 'message' => 'Analyzing existing implementation patterns...', 'color' => 'text-aw-accent-soft'],
        ['time' => '14:23:18', 'agent' => 'Planner', 'message' => 'Implementation plan prepared.', 'color' => 'text-aw-verified'],
        ['time' => '14:23:21', 'agent' => 'Coder', 'message' => 'Generating application code...', 'color' => 'text-aw-accent-soft'],
        ['time' => '14:23:26', 'agent' => 'Tester', 'message' => 'Running automated test suite...', 'color' => 'text-aw-warning'],
        ['time' => '14:23:31', 'agent' => 'Reviewer', 'message' => 'Checking code quality and security.', 'color' => 'text-aw-ink-muted'],
    ];

    // Agent nodes snap to the five fixed graph lanes (spec §5: the graph's
    // horizontal axis mirrors the board's stages — lane count is geometry).
    // Status is read, not assumed: a stored 'blocked' agent turns its node and
    // its edge amber, an 'idle' one goes dim, and only 'active' ones pulse.
    $laneCenters = [66, 128, 190, 252, 314];

    $agentSlots = collect($agents)
        ->take(count($laneCenters))
        ->values()
        ->map(function (array $agent, int $index) use ($laneCenters): array {
            $status = \App\Support\WorkflowTaxonomy::normaliseStatus($agent['status'] ?? null);

            return [
                'name' => (string) ($agent['name'] ?? ''),
                'description' => (string) ($agent['description'] ?? ''),
                'status' => $status,
                'statusLabel' => \App\Support\WorkflowTaxonomy::statusLabel($status),
                'statusTone' => \App\Support\WorkflowTaxonomy::statusTone($status),
                'top' => $laneCenters[$index] - 19,
                'cy' => $laneCenters[$index],
            ];
        })
        ->all();

    // Node and edge colour per status — the graph's only colour decision.
    // Every value carries a literal fallback: an SVG presentation attribute
    // with an unresolvable var() paints BLACK, it does not fail quietly.
    $statusColours = [
        'active' => 'var(--color-aw-accent, #10b981)',
        'idle' => 'var(--color-aw-ink-faint, #71716b)',
        'blocked' => 'var(--color-aw-warning, #b45309)',
    ];

    $activeAgents = collect($agentSlots)->where('status', 'active')->count();
    $blockedAgents = collect($agentSlots)->where('status', 'blocked')->count();

    $taskCount = count($boardTasks);
    $deployedCount = collect($boardTasks)->where('stage', 'deploy')->count();

    $workflowConfig = [
        'stages' => $stages,
        'tasks' => $boardTasks,
        'logs' => $logs,
        'nextId' => $nextId,
        'priorities' => \App\Support\WorkflowTaxonomy::PRIORITIES,
    ];

    $ctaHref = Route::has('contact') ? route('contact') : '#ai-workflow';

    // Namespaced SVG ids: two of these sections on one page must not share a
    // filter id, or the second graph reuses the first one's blur.
    static $awInstance = 0;
    $awInstance++;
    $uid = 'aw-svg-'.$awInstance;
@endphp

{{-- The instrument panel on the About page. The section semantics (id,
     heading, lead) live on the page canvas above — same idiom, colours and
     reveal animations as every other section — so this component is only
     the panel: rounded, bordered to sit on the shared canvas, and themed
     with the page's own ink and accent scales. --}}
<div
    x-data="aiWorkflow({{ Illuminate\Support\Js::from($workflowConfig) }})"
    class="relative isolate overflow-hidden rounded-2xl border border-ink-200 bg-aw-canvas text-aw-ink shadow-soft-2 dark:border-ink-800"
>
    {{-- Graph-paper grid only. No ambient glow blobs: glow is state, never
         style (spec §2 W2), and the grid carries the instrument reading.
         It sits at z-0 rather than behind the panel: the surface is opaque
         now, so a negative z-index would hide the grid entirely. --}}
    <div class="pointer-events-none absolute inset-0 z-0 overflow-hidden">
        <div
            class="absolute inset-0 opacity-[0.35] dark:opacity-[0.12]"
            style="background-image:linear-gradient(color-mix(in oklab, var(--color-aw-accent, #10b981) 25%, transparent) 1px,transparent 1px),linear-gradient(90deg,color-mix(in oklab, var(--color-aw-accent, #10b981) 25%, transparent) 1px,transparent 1px);background-size:40px 40px"
        ></div>
    </div>

    {{-- The glass edge: one accent hairline along the top, the same treatment
         as the glass modal, so the panel reads as lit glass rather than a
         filled rectangle in either theme. --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 z-10 h-px bg-gradient-to-r from-transparent via-aw-accent/50 to-transparent"></div>

    <div class="relative z-10 p-3 sm:p-4 lg:p-5">
        {{-- Instrument strip: the eyebrow and KPI cards, plus the run state
             badge. The section's heading and lead live on the page canvas
             above, themed like every other section. --}}
        <div class="mb-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.2em] text-aw-accent-soft">{{ $eyebrow }}</p>

                <div class="mt-2 flex flex-wrap items-stretch gap-2">
                    @foreach ($metrics['header'] ?? [] as $metric)
                        <div class="min-w-[88px] flex-1 rounded-lg border border-aw-line bg-aw-panel/60 px-3 py-2">
                            <p class="text-[10px] leading-4 text-aw-ink-muted">{{ $metric['label'] }}</p>
                            <p class="mt-0.5 font-mono text-lg font-bold leading-6 tabular-nums {{ $metricClasses[$metric['tone'] ?? 'cyan'] ?? 'text-aw-ink' }}">{{ $metric['value'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center gap-1.5">
                @if ($blockedAgents > 0)
                    <span class="inline-flex items-center gap-1 rounded-full border border-aw-warning/25 bg-aw-warning/8 px-2 py-0.5 font-mono text-[10px] font-semibold text-aw-warning">
                        {{ $blockedAgents }} blocked
                    </span>
                @endif

                {{-- The badge states the run's actual condition rather than
                     advertising itself (spec §2 W1). --}}
                <span @class([
                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-mono text-[10px] font-semibold',
                    'border-aw-accent/20 bg-aw-accent/7 text-aw-accent-soft' => $activeAgents > 0,
                    'border-aw-line bg-aw-card/60 text-aw-ink-muted' => $activeAgents === 0,
                ])>
                    <span @class([
                        'h-1.5 w-1.5 rounded-full',
                        'animate-pulse bg-aw-accent motion-reduce:animate-none' => $activeAgents > 0,
                        'bg-aw-ink-faint' => $activeAgents === 0,
                    ])></span>
                    {{ $activeAgents > 0 ? $activeAgents.' active' : 'All idle' }}
                </span>
            </div>
        </div>

        {{-- Linear stage list: the board is an enhancement over text (spec §11). --}}
        <ol class="sr-only">
            @foreach ($stages as $stage)
                <li>{{ $stage['name'] }} — {{ $stage['description'] }}</li>
            @endforeach
        </ol>

        {{-- Main workspace: board and graph side by side from xl (1280px) up.
             At lg the graph column is too narrow for its own labels, so the
             two panes stack there and the board gets the full width — the
             pipeline row still scrolls sideways inside that. --}}
        <div class="grid min-w-0 grid-cols-1 gap-4 sm:gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(21rem,1fr)]">

            {{-- LEFT: Interactive Kanban. Five columns stay in one row at
                 every width; below ~900px the row scrolls horizontally rather
                 than stacking, because a stacked pipeline is not a pipeline. --}}
            <div class="min-w-0 rounded-xl border border-aw-accent/15 bg-aw-panel/60 p-3 sm:p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg border border-aw-accent/20 bg-aw-accent/10 text-aw-accent-soft">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="16" rx="2"/>
                                <path d="M9 4v16M15 4v16"/>
                            </svg>
                        </span>
                        <div>
                            <h3 class="text-sm font-semibold text-aw-ink">Development Pipeline</h3>
                            <p class="text-[11px] leading-4 text-aw-ink-faint">AI-powered task orchestration</p>
                        </div>
                    </div>

                    <button
                        type="button"
                        x-on:click="toggleAutomation()"
                        :aria-pressed="automation ? 'true' : 'false'"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-aw-accent-soft focus-visible:ring-offset-2 focus-visible:ring-offset-aw-panel"
                        :class="automation ? 'border-aw-verified/30 bg-aw-verified/8 text-aw-verified' : 'border-aw-line bg-aw-card/60 text-aw-ink-muted'"
                    >
                        <span class="h-1.5 w-1.5 rounded-full" :class="automation ? 'animate-pulse bg-aw-verified motion-reduce:animate-none' : 'bg-aw-ink-faint'"></span>
                        <span x-text="automation ? 'Automation on' : 'Automation off'"></span>
                    </button>

                    <flux:modal.trigger name="workflow-new-task">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-aw-accent px-3 py-1.5 text-xs font-semibold text-aw-on-accent transition hover:bg-aw-accent-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-aw-accent-soft focus-visible:ring-offset-2 focus-visible:ring-offset-aw-panel"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            New task
                        </button>
                    </flux:modal.trigger>
                </div>

                {{-- Horizontally scrollable on narrow screens. The five columns
                     stay in one row at every width — a stacked pipeline is not a
                     pipeline — so below ~900px the row scrolls sideways. The
                     scroll container is focusable and labelled so a keyboard
                     user can reach it and knows it moves. --}}
                <div class="-mx-1 overflow-x-auto pb-2" tabindex="0" role="region" aria-label="{{ __('Development pipeline stages — scroll sideways to see every stage') }}">
                    <div class="grid min-w-[900px] grid-cols-5 gap-2 px-1">
                        <template x-for="(stage, stageIndex) in stages" :key="stage.id">
                            <div class="flex min-w-0 flex-col rounded-lg border shadow-[inset_0_1px_0_rgb(0_0_0/0.04)] backdrop-blur-md transition-colors dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.07)]"
                                :class="stage.border + ' ' + stage.background">

                                {{-- Stage header --}}
                                <div class="mb-2 rounded-md border border-aw-line bg-aw-canvas/70 p-2">
                                    <div class="mb-1.5 flex items-center justify-between gap-1.5">
                                        <span class="flex h-5 w-5 items-center justify-center rounded font-mono text-[10px] font-bold tabular-nums"
                                            :class="stage.badge"
                                            x-text="String(stageIndex + 1).padStart(2, '0')"></span>
                                        <span class="rounded border border-aw-line bg-aw-raised/40 px-1.5 py-0.5 font-mono text-[10px] tabular-nums text-aw-ink-muted"
                                            x-text="tasks.filter(task => task.stage === stage.id).length"></span>
                                    </div>
                                    <h4 class="text-[11px] font-semibold uppercase leading-4 tracking-wide text-aw-ink" x-text="stage.name"></h4>
                                    <p class="mt-0.5 text-[10px] leading-3.5 text-aw-ink-faint" x-text="stage.description"></p>
                                </div>

                                {{-- Task cards --}}
                                <div class="flex flex-1 flex-col gap-2">
                                    <template x-for="task in tasks.filter(item => item.stage === stage.id)" :key="task.id">
                                        <article class="group rounded-lg border border-aw-line bg-aw-card p-2.5 transition duration-200 ease-out hover:-translate-y-0.5 motion-reduce:transition-none motion-reduce:hover:translate-y-0 hover:border-aw-accent/30 hover:bg-aw-raised">
                                            <div class="mb-1.5 flex items-center justify-between gap-1.5">
                                                <span class="font-mono text-[10px] tabular-nums text-aw-ink-faint" x-text="task.id"></span>
                                                <span class="rounded px-1.5 py-0.5 text-[10px] font-medium leading-3.5"
                                                    :class="priorityClass(task)"
                                                    x-text="task.priority"></span>
                                            </div>

                                            <h5 class="text-xs font-semibold leading-4 text-aw-ink line-clamp-2" x-text="task.title"></h5>
                                            <p class="mt-1 text-[11px] leading-4 text-aw-ink-muted line-clamp-2" x-text="task.description"></p>

                                            <p x-show="task.target" class="mt-1.5 truncate font-mono text-[10px] text-aw-ink-faint" x-text="task.target"></p>

                                            <div class="mt-1.5 flex flex-wrap gap-1">
                                                <template x-for="tag in task.tags" :key="tag">
                                                    <span class="rounded border border-aw-line bg-aw-raised/50 px-1 py-0.5 text-[10px] leading-3.5 text-aw-ink-muted" x-text="tag"></span>
                                                </template>
                                            </div>

                                            <div class="mt-2 flex items-center justify-between gap-1.5 border-t border-aw-line pt-2">
                                                <div class="flex min-w-0 items-center gap-1 text-[10px] text-aw-ink-muted">
                                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full"
                                                        :class="cardDot(task)"></span>
                                                    <span class="truncate" x-text="task.agent"></span>
                                                </div>
                                                <span class="shrink-0 font-mono text-[10px] tabular-nums text-aw-ink-faint" x-text="task.progress + '%'"></span>
                                            </div>

                                            <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-aw-raised">
                                                <div class="h-full rounded-full transition-all duration-500 ease-out motion-reduce:transition-none"
                                                    :class="stage.progressColor"
                                                    :style="`width: ${task.progress}%`"></div>
                                            </div>

                                            <button
                                                type="button"
                                                x-on:click="advanceTask(task)"
                                                class="mt-2 w-full rounded-md border border-aw-line bg-aw-raised/40 px-2 py-1.5 text-[11px] font-medium text-aw-ink-muted transition hover:border-aw-accent/30 hover:text-aw-accent-soft focus:outline-none focus-visible:ring-1 focus-visible:ring-aw-accent-soft"
                                            >
                                                <span x-text="stageIndex === stages.length - 1 ? 'Mark complete' : 'Move to next stage →'"></span>
                                            </button>
                                        </article>
                                    </template>

                                    <div x-show="tasks.filter(task => task.stage === stage.id).length === 0"
                                        class="rounded-lg border border-dashed border-aw-line px-2 py-5 text-center text-[11px] text-aw-ink-faint">
                                        No tasks
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-aw-line pt-2.5 text-[11px] text-aw-ink-faint">
                    {{-- Honest labelling: the rows come from the database, the
                         stage moves on this page are client-side only. --}}
                    <span class="flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-aw-accent"></span>
                        {{ __('Fully automated demo — the pipeline runs itself in your browser; steer any card by hand.') }}
                    </span>
                    <span class="font-mono tabular-nums" x-text="`${tasks.length} tasks · ${tasks.filter(task => task.stage === 'deploy' || task.stage === 'complete').length} at deploy`">{{ $taskCount }} tasks · {{ $deployedCount }} at deploy</span>
                </div>
            </div>

            {{-- RIGHT: Agent collaboration graph --}}
            <div class="min-w-0 rounded-xl border border-aw-accent/15 bg-aw-panel/60 p-3 sm:p-4">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg border border-aw-accent/20 bg-aw-accent/10 text-aw-accent-soft">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <circle cx="12" cy="12" r="3"/>
                                <circle cx="5" cy="5" r="2"/>
                                <circle cx="19" cy="5" r="2"/>
                                <circle cx="5" cy="19" r="2"/>
                                <circle cx="19" cy="19" r="2"/>
                                <path d="m7 6.5 3 4M17 6.5l-3 4M7 17.5l3-4M17 17.5l-3-4"/>
                            </svg>
                        </span>
                        <div>
                            <h3 class="text-sm font-semibold text-aw-ink">Agent Collaboration</h3>
                            <p class="text-[11px] leading-4 text-aw-ink-faint">Logic flow &amp; orchestration</p>
                        </div>
                    </div>

                </div>

                {{-- Linear equivalent of the graph for assistive tech (spec §11). --}}
                <ul class="sr-only">
                    <li>Task input feeds the orchestrator, which fans work out to the agents below; every agent hands its result to deployment.</li>
                    @foreach ($agentSlots as $slot)
                        <li>{{ $slot['name'] }} — {{ $slot['description'] }} ({{ $slot['statusLabel'] }})</li>
                    @endforeach
                </ul>

                {{-- SVG graph: responsive viewBox, all paints carry literal
                     fallbacks (an unresolvable var() paints SVG black), and
                     every filter id is namespaced to this instance. The
                     drawing holds a minimum width so its labels stay
                     legible on a narrow screen; below that the canvas
                     scrolls rather than shrinking the type to nothing. --}}
                <div class="relative min-w-0 overflow-x-auto overflow-y-hidden rounded-lg border border-aw-line bg-aw-panel/70">
                    <div class="pointer-events-none absolute inset-0 opacity-30"
                        style="background-image:radial-gradient(color-mix(in oklab, var(--color-aw-accent, #10b981) 55%, transparent) .8px, transparent .8px);background-size:18px 18px"></div>

                    <svg class="relative z-10 block h-auto w-full min-w-[420px]" viewBox="0 0 540 372" role="img" aria-label="AI agent workflow graph" focusable="false">
                        <defs>
                            <filter id="{{ $uid }}-glow" x="-80%" y="-80%" width="260%" height="260%">
                                <feGaussianBlur stdDeviation="4" result="blur"/>
                                <feMerge>
                                    <feMergeNode in="blur"/>
                                    <feMergeNode in="SourceGraphic"/>
                                </feMerge>
                            </filter>
                        </defs>

                        {{-- Connection paths. Every edge inherits the colour of
                             its source agent's status (spec §5): cyan for
                             machine intent, muted for idle, amber dashed for
                             a handoff that is waiting on a human. --}}
                        <g fill="none" stroke-linecap="round">
                            <path d="M104 190 H151" stroke="var(--color-aw-accent, #10b981)" stroke-width="2"/>

                            @foreach ($agentSlots as $slot)
                                @php
                                    $edgeColour = $statusColours[$slot['status']];
                                    $edgeWidth = $slot['status'] === 'active' ? 1.8 : 1.4;
                                    $edgeOpacity = $slot['status'] === 'active' ? 1 : 0.6;
                                @endphp

                                @if ($slot['cy'] === 190)
                                    <path d="M204 190 H266" stroke="{{ $edgeColour }}" stroke-width="{{ $edgeWidth }}" stroke-opacity="{{ $edgeOpacity }}" @if ($slot['status'] === 'blocked') stroke-dasharray="5 4" @endif/>
                                    <path d="M374 190 H439" stroke="{{ $edgeColour }}" stroke-width="{{ $edgeWidth }}" stroke-opacity="{{ $edgeOpacity }}" @if ($slot['status'] === 'blocked') stroke-dasharray="5 4" @endif/>
                                @else
                                    <path d="M204 190 C235 190 228 {{ $slot['cy'] }} 266 {{ $slot['cy'] }}" stroke="{{ $edgeColour }}" stroke-width="{{ $edgeWidth }}" stroke-opacity="{{ $edgeOpacity }}" @if ($slot['status'] === 'blocked') stroke-dasharray="5 4" @endif/>
                                    <path d="M374 {{ $slot['cy'] }} C415 {{ $slot['cy'] }} 403 190 439 190" stroke="{{ $edgeColour }}" stroke-width="{{ $edgeWidth }}" stroke-opacity="{{ $edgeOpacity }}" @if ($slot['status'] === 'blocked') stroke-dasharray="5 4" @endif/>
                                @endif
                            @endforeach

                            {{-- In-flight pulses. They are the only looping
                                 motion (spec §8), they exist only while an
                                 agent is actually holding work, and
                                 reduced-motion users see static edges. --}}
                            @if ($activeAgents > 0)
                                <circle r="3" fill="var(--color-aw-accent-soft, #047857)" filter="url(#{{ $uid }}-glow)" class="motion-reduce:hidden">
                                    <animateMotion dur="2.6s" repeatCount="indefinite" path="M104 190 H151"/>
                                </circle>
                            @endif
                            @foreach ($agentSlots as $slot)
                                @continue ($slot['status'] !== 'active')

                                @if ($slot['cy'] === 190)
                                    <circle r="3" fill="var(--color-aw-accent-soft, #047857)" filter="url(#{{ $uid }}-glow)" class="motion-reduce:hidden">
                                        <animateMotion dur="3s" repeatCount="indefinite" path="M204 190 H266"/>
                                    </circle>
                                @else
                                    <circle r="3" fill="var(--color-aw-accent-soft, #047857)" filter="url(#{{ $uid }}-glow)" class="motion-reduce:hidden">
                                        <animateMotion dur="{{ 2.6 + $loop->index * 0.3 }}s" repeatCount="indefinite" path="M204 190 C235 190 228 {{ $slot['cy'] }} 266 {{ $slot['cy'] }}"/>
                                    </circle>
                                @endif
                            @endforeach
                        </g>

                        {{-- Input node (rounded ingress, spec §5) --}}
                        <g>
                            <rect x="12" y="153" width="92" height="74" rx="10" fill="var(--color-aw-card, #ffffff)" stroke="var(--color-aw-accent, #10b981)" stroke-opacity=".8"/>
                            <circle cx="58" cy="172" r="8" fill="var(--color-aw-accent, #10b981)" fill-opacity=".12" stroke="var(--color-aw-accent, #10b981)"/>
                            <path d="M54 172h8M58 168v8" stroke="var(--color-aw-accent-soft, #047857)" stroke-width="1.4"/>
                            <text x="58" y="194" text-anchor="middle" fill="var(--color-aw-ink, #171716)" font-size="11" font-weight="600">Task Input</text>
                            <text x="58" y="209" text-anchor="middle" fill="var(--color-aw-ink-muted, #575753)" font-size="8">Issue / Request</text>
                        </g>

                        {{-- Orchestrator --}}
                        <g filter="url(#{{ $uid }}-glow)">
                            <circle cx="178" cy="190" r="28" fill="var(--color-aw-panel, #f7f7f6)" stroke="var(--color-aw-accent, #10b981)" stroke-width="1.6"/>
                            @if ($activeAgents > 0)
                                <circle cx="178" cy="190" r="20" fill="none" stroke="var(--color-aw-accent, #10b981)" stroke-opacity=".4" class="motion-reduce:hidden">
                                    <animate attributeName="r" values="18;25;18" dur="3s" repeatCount="indefinite"/>
                                    <animate attributeName="stroke-opacity" values=".5;.1;.5" dur="3s" repeatCount="indefinite"/>
                                </circle>
                            @endif
                            <rect x="167" y="183" width="22" height="16" rx="4" fill="none" stroke="var(--color-aw-accent-soft, #047857)" stroke-width="1.5"/>
                            <circle cx="173" cy="190" r="1.5" fill="var(--color-aw-accent-soft, #047857)"/>
                            <circle cx="183" cy="190" r="1.5" fill="var(--color-aw-accent-soft, #047857)"/>
                            <path d="M173 195h10M178 178v5" stroke="var(--color-aw-accent-soft, #047857)" stroke-width="1.5"/>
                        </g>
                        <text x="178" y="233" text-anchor="middle" fill="var(--color-aw-ink, #171716)" font-size="11" font-weight="600">Orchestrator</text>
                        <text x="178" y="247" text-anchor="middle" fill="var(--color-aw-ink-muted, #575753)" font-size="9">Routes tasks</text>

                        {{-- Agent nodes. The stroke and the status dot both read
                             the agent's stored status (spec §5), so a blocked
                             agent is visible without hovering. --}}
                        @foreach ($agentSlots as $slot)
                            @php
                                $nodeColour = $statusColours[$slot['status']];
                                $nodeOpacity = match ($slot['status']) {
                                    'active' => '.7',
                                    'blocked' => '.85',
                                    default => '.3',
                                };
                            @endphp

                            <g @if ($slot['status'] === 'active') filter="url(#{{ $uid }}-glow)" @endif>
                                <rect x="266" y="{{ $slot['top'] }}" width="108" height="38" rx="8" fill="var(--color-aw-card, #ffffff)" stroke="{{ $nodeColour }}" stroke-opacity="{{ $nodeOpacity }}"/>
                                <circle cx="279" cy="{{ $slot['top'] + 13 }}" r="4" fill="{{ $nodeColour }}"/>
                                <text x="289" y="{{ $slot['top'] + 15 }}" fill="var(--color-aw-ink, #171716)" font-size="9.5" font-weight="600">{{ $slot['name'] }}</text>
                                <text x="278" y="{{ $slot['top'] + 29 }}" fill="var(--color-aw-ink-muted, #575753)" font-size="8">{{ $slot['description'] }}</text>
                                @if ($slot['status'] === 'blocked')
                                    <rect x="266" y="{{ $slot['top'] }}" width="108" height="38" rx="8" fill="none" stroke="var(--color-aw-warning, #b45309)" stroke-opacity=".35" stroke-dasharray="4 3"/>
                                @endif
                            </g>
                        @endforeach

                        {{-- Deployment node (the human-verified gate → green, spec §5) --}}
                        <g>
                            <rect x="439" y="153" width="89" height="74" rx="10" fill="var(--color-aw-card, #ffffff)" stroke="var(--color-aw-verified, #4d7c0f)" stroke-opacity=".8"/>
                            <path d="M478 170v13m-6-6 6-7 6 7M468 184h20" fill="none" stroke="var(--color-aw-verified, #4d7c0f)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <text x="483" y="202" text-anchor="middle" fill="var(--color-aw-ink, #171716)" font-size="10" font-weight="600">Deployment</text>
                            <text x="483" y="215" text-anchor="middle" fill="var(--color-aw-ink-muted, #575753)" font-size="8">CI / CD</text>
                        </g>
                    </svg>
                </div>

                {{-- Status legend: colour is never the only signal (spec §2
                     W4), so each state carries its word here too. --}}
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] text-aw-ink-faint">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-aw-accent"></span>
                        {{ __('Active — handoff in flight') }}
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-aw-ink-faint"></span>
                        {{ __('Idle') }}
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-aw-warning"></span>
                        {{ __('Blocked — waiting on a human') }}
                    </span>
                </div>

                <div class="mt-3 grid grid-cols-1 gap-2 min-[22rem]:grid-cols-3">
                    @foreach ($metrics['telemetry'] ?? [] as $metric)
                        <div class="rounded-lg border border-aw-line bg-aw-card/50 p-2">
                            <p class="text-[10px] leading-4 text-aw-ink-muted">{{ $metric['label'] }}</p>
                            <p class="mt-0.5 font-mono text-base font-semibold leading-5 tabular-nums {{ $metricClasses[$metric['tone'] ?? 'cyan'] ?? 'text-aw-ink' }}">{{ $metric['value'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Execution logs: one compact, full-width strip below the workspace. --}}
        <div class="mt-4 overflow-hidden rounded-xl border border-aw-accent/15 bg-aw-panel/60">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-aw-line px-4 py-2.5">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-aw-accent-soft" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <path d="m4 7 5 5-5 5M12 17h8"/>
                    </svg>
                    <h3 class="text-sm font-semibold text-aw-ink">Execution Logs</h3>
                    <span class="rounded border border-aw-line bg-aw-card/60 px-1.5 py-0.5 font-mono text-[10px] text-aw-ink-faint">
                        {{ __('Demo stream') }}
                    </span>
                </div>
                <button type="button"
                    x-on:click="logsVisible = !logsVisible"
                    class="text-[11px] text-aw-accent-soft transition hover:text-aw-ink focus:outline-none focus-visible:ring-1 focus-visible:ring-aw-accent-soft"
                    x-text="logsVisible ? 'Hide logs' : 'Show logs'"></button>
            </div>

            <div x-show="logsVisible" x-transition class="grid gap-x-6 gap-y-1 px-4 py-2.5 font-mono text-[10px] leading-4 sm:grid-cols-2 sm:text-[11px] lg:grid-cols-3">
                <template x-for="(log, index) in logs" :key="index">
                    <div class="flex min-w-0 gap-2">
                        <span class="shrink-0 tabular-nums text-aw-ink-faint" x-text="log.time"></span>
                        <span class="shrink-0" :class="log.color" x-text="`[${log.agent}]`"></span>
                        <span class="min-w-0 truncate text-aw-ink-muted" x-text="log.message"></span>
                    </div>
                </template>
                <div class="col-span-full flex items-center gap-1.5 pt-0.5 text-aw-accent-soft">
                    <span class="h-1 w-1 animate-pulse rounded-full bg-aw-accent motion-reduce:animate-none"></span>
                    Waiting for next event…
                </div>
            </div>
        </div>

        {{-- Slim closing line: the CTA copy stays operator-managed, the band
             does not cost the section a second card. --}}
        <div class="mt-4 flex flex-col gap-2 rounded-xl border border-aw-accent/15 bg-aw-panel/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="min-w-0 truncate text-sm text-aw-ink-muted">{{ $cta['text'] }}</p>
            <a href="{{ $ctaHref }}"
                class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-aw-accent px-3.5 py-2 text-xs font-semibold text-aw-on-accent transition hover:bg-aw-accent-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-aw-accent-soft focus-visible:ring-offset-2 focus-visible:ring-offset-aw-canvas">
                Explore workflow
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M5 12h14m-6-6 6 6-6 6"/>
                </svg>
            </a>
        </div>

        {{-- New-task dialog: the glass variant of the shared modal shell. It
             lives inside the board's x-data scope so the form talks straight
             to the same Alpine state the cards read. --}}
        <x-app.ui.modal
            name="workflow-new-task"
            variant="glass"
            title="New task"
            description="It enters the pipeline at Task Trigger — move it through the stages from its card."
            icon="sparkles"
            maxWidth="md"
        >
            <div class="space-y-4">
                <div>
                    <label for="wf-new-task-title" class="mb-1.5 block text-xs font-medium text-aw-ink-muted">{{ __('Task title') }}</label>
                    <input
                        id="wf-new-task-title"
                        type="text"
                        x-model="newTaskTitle"
                        x-on:keydown.enter="createTask()"
                        placeholder="{{ __('e.g. Redesign the billing settings page') }}"
                        autocomplete="off"
                        autofocus
                        class="w-full rounded-lg px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-aw-accent-soft/60"
                    />
                </div>

                <div>
                    <label for="wf-new-task-priority" class="mb-1.5 block text-xs font-medium text-aw-ink-muted">{{ __('Priority') }}</label>
                    <select
                        id="wf-new-task-priority"
                        x-model="newTaskPriority"
                        class="w-full rounded-lg px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-aw-accent-soft/60"
                    >
                        @foreach (\App\Support\WorkflowTaxonomy::PRIORITIES as $priority)
                            <option value="{{ $priority }}">{{ $priority }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-[11px] leading-4 text-aw-ink-faint">
                        {{ __('Priority sets the card accent: amber for bugs and blocks, green for releases.') }}
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <flux:modal.close>
                        <flux:button variant="ghost" size="sm">
                            {{ __('Cancel') }}
                        </flux:button>
                    </flux:modal.close>

                    <button
                        type="button"
                        x-on:click="createTask()"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-aw-accent px-3.5 py-2 text-xs font-semibold text-aw-on-accent transition hover:bg-aw-accent-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-aw-accent-soft focus-visible:ring-offset-2 focus-visible:ring-offset-aw-panel"
                    >
                        {{ __('Create task') }}
                    </button>
                </div>
            </div>
        </x-app.ui.modal>
    </div>
</div>

<script>
    function aiWorkflow(config) {
        config = config || {};

        const progressBands = { trigger: 10, planning: 35, encoding: 60, review: 80, deploy: 90, complete: 100 };
        const stageAgents = { planning: 'Planner agent', encoding: 'Coder agent', review: 'Tester agent', deploy: 'Deploy agent' };

        return {
            logsVisible: true,

            // Full automation: the pipeline advances itself, oldest task
            // first. Paused while the tab is hidden and off by default for
            // reduced-motion users — a self-moving board is exactly the kind
            // of ambient motion that setting exists to stop.
            automation: true,
            automationTimer: null,
            newTaskTitle: '',
            newTaskPriority: 'Feature',
            nextId: config.nextId || 1241,
            stages: config.stages || [],
            tasks: (config.tasks || []).map(task => ({ ...task, tags: task.tags || [] })),
            logs: config.logs || [],

            priorityClass(task) {
                return {
                    cyan: 'bg-aw-accent/10 text-aw-accent-soft',
                    green: 'bg-aw-verified/10 text-aw-verified',
                    amber: 'bg-aw-warning/10 text-aw-warning',
                    neutral: 'bg-aw-raised text-aw-ink-muted',
                }[task.tone] || 'bg-aw-accent/10 text-aw-accent-soft';
            },

            cardDot(task) {
                if (task.tone === 'amber') return 'bg-aw-warning';

                return ['deploy', 'complete'].includes(task.stage) ? 'bg-aw-verified' : 'bg-aw-accent';
            },

            init() {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    this.automation = false;
                }

                this.onVisibility = () => (document.hidden ? this.stopAutomation() : this.startAutomation());
                document.addEventListener('visibilitychange', this.onVisibility);

                if (this.automation) {
                    this.startAutomation();
                }
            },

            destroy() {
                this.stopAutomation();
                document.removeEventListener('visibilitychange', this.onVisibility);
            },

            startAutomation() {
                this.stopAutomation();

                if (! this.automation) return;

                this.automationTimer = setInterval(() => this.automateStep(), 2600);
            },

            stopAutomation() {
                if (this.automationTimer) {
                    clearInterval(this.automationTimer);
                    this.automationTimer = null;
                }
            },

            toggleAutomation() {
                this.automation = ! this.automation;

                if (this.automation) {
                    this.startAutomation();
                    this.addLog('Orchestrator', 'Automation resumed.', 'text-aw-accent-soft');
                } else {
                    this.stopAutomation();
                    this.addLog('Orchestrator', 'Automation paused.', 'text-aw-warning');
                }
            },

            automateStep() {
                // The oldest unfinished task advances; when the pipeline
                // drains, the queue restarts so the run never stalls.
                const pending = [...this.tasks].reverse().find(task => task.stage !== 'complete');

                if (pending) {
                    this.advanceTask(pending);

                    return;
                }

                this.tasks.forEach(task => {
                    task.stage = 'trigger';
                    task.progress = progressBands.trigger;
                    task.agent = 'Task queue';
                });

                this.addLog('Orchestrator', 'Run complete — queue restarted.', 'text-aw-verified');
            },

            createTask() {
                const title = this.newTaskTitle.trim();

                if (!title) return;

                const priority = this.newTaskPriority;

                this.tasks.unshift({
                    id: '#SH-' + this.nextId++,
                    title: title,
                    description: 'New task awaiting AI planning.',
                    stage: 'trigger',
                    priority: priority,
                    tone: this.toneFor(priority),
                    tags: ['New task'],
                    agent: 'Task queue',
                    target: null,
                    progress: progressBands.trigger,
                });

                this.addLog('Orchestrator', `New task created: ${title}`, 'text-aw-accent-soft');

                this.newTaskTitle = '';
                this.newTaskPriority = 'Feature';
                this.$dispatch('modal-close', { name: 'workflow-new-task' });
            },

            // Mirrors App\Support\WorkflowTaxonomy::toneForPriority() — amber
            // for work a human must act on, green for cleared gates, cyan for
            // machine intent. A Bug card must not render as machine intent.
            toneFor(priority) {
                const key = String(priority).trim().toLowerCase();

                if (['bug', 'blocked', 'urgent', 'hotfix', 'security', 'warning', 'failed'].includes(key)) return 'amber';
                if (['deploy', 'release', 'verified', 'reviewed', 'done', 'complete', 'shipped'].includes(key)) return 'green';

                return 'cyan';
            },

            advanceTask(task) {
                const currentIndex = this.stages.findIndex(stage => stage.id === task.stage);

                if (currentIndex === -1) return;

                if (currentIndex === this.stages.length - 1) {
                    task.stage = 'complete';
                    task.progress = progressBands.complete;
                    task.agent = 'Completed';
                    this.addLog('Deployment', `${task.id} marked as completed.`, 'text-aw-verified');
                    return;
                }

                const nextStage = this.stages[currentIndex + 1];
                task.stage = nextStage.id;
                task.progress = progressBands[nextStage.id] ?? task.progress;
                task.agent = stageAgents[nextStage.id] || 'AI agent';

                this.addLog('Orchestrator', `${task.id} moved to ${nextStage.name}.`, 'text-aw-accent-soft');
            },

            addLog(agent, message, color) {
                this.logs.unshift({
                    time: new Date().toLocaleTimeString('en-GB', {
                        hour12: false,
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit'
                    }),
                    agent,
                    message,
                    color
                });

                this.logs = this.logs.slice(0, 8);
            }
        };
    }
</script>
