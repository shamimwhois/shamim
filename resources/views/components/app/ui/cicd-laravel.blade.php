@props([
    /** Ordinal for the section marker. */
    'number' => 2,
    'label' => 'CI/CD & Laravel',
    'heading' => 'Every commit earns its deploy',
    'lead' => 'A Laravel release is a chain of small, provable steps: style, types, tests, assets — and then one atomic switch. This is the pipeline I run, and why each gate is where it is.',
    'headingId' => 'cicd-heading',
])

@php
    /**
     * CI/CD & Laravel — section 02 of the landing page.
     *
     * ## What this band is arguing
     *
     * Not "I know GitHub Actions". The claim is narrower and harder: a Laravel
     * release can be made boring. Every stage below exists because it removes
     * a way for a deploy to surprise you — a formatting drift, an untyped
     * relation, a cache built against the previous release, a worker holding
     * code that no longer exists on disk.
     *
     * ## The plate reads top to bottom as a run
     *
     *   1. **The chain** — eight stage cards left to right, each with its own
     *      status pill (Completed / Running / Pending), joined by a connector
     *      that lights as the run passes. The chain *is* the rail: the cards
     *      are the same eight `<button>`s the old sidebar listed, in the same
     *      order, controlling the same readout.
     *   2. **The mind map** — under the chain, a dotted bus with a synapse per
     *      stage dropping to a single core: every stage acts on the same
     *      application, which is what separates a mind map from a flowchart.
     *      The packet on the bus carries direction; the dashed red edge from
     *      Verify back to Release is the rollback, and it skips Deploy on
     *      purpose — a rollback does not re-deploy, it re-points.
     *   3. **The readout** — one panel per stage with the command it runs,
     *      server-rendered, plus the argument for why that gate sits there.
     *   4. **The console** — how far the run is, what it printed, and the
     *      details an operator checks first.
     *
     * ## Layout contract
     *
     * The plate is ONE column at every width. An earlier revision split it in
     * two at ≥1024px, which quietly broke the section: the run column was
     * ~590px wide while the eight-card track needs 780px, so the chain
     * scrolled sideways on a desktop, and the console's three panels were
     * crushed into that same narrow column. The console now carries its own
     * internal grid instead, so the chain gets the full plate and the log
     * pane gets the width a log pane needs.
     *
     * ## State contract
     *
     * Two attributes carry all the state, and this file is the only place
     * they mean anything:
     *
     *   - `data-cursor` on `.cicd-plate` is the run. It paints every status
     *     pill, checklist row and log line.
     *   - `data-stage` is the reader's selection, and it shows exactly one
     *     readout panel — nothing else, so the demo can advance while a
     *     stage stays open.
     *
     * Both are server-rendered at 1, so the plate is correct before Alpine
     * boots and stays readable with JavaScript disabled. `data-status` on a
     * cell/row is the resolved status of that one item: Alpine writes it, the
     * stylesheet paints it, and the server writes the same value first.
     */

    $stages = [
        [
            'key' => 'commit',
            'name' => 'Commit',
            'kind' => 'step',
            'command' => 'git push origin main',
            'title' => 'A commit is a promise with a timestamp',
            'note' => 'Everything downstream is derived from one immutable identifier, so "what did we ship" is never a question about branches or a developer\'s laptop. The commit is the unit of work; the rest of the pipeline only reads it.',
            'desc' => 'Push to main',
            'meta' => 'git · main',
            'check' => 'Commit pushed',
            'seconds' => 1,
            'icon' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4" stroke-linecap="round"/>',
        ],
        [
            'key' => 'prepare',
            'name' => 'Prepare',
            'kind' => 'step',
            'command' => 'composer install --prefer-dist',
            'title' => 'One environment, one cache key',
            'note' => 'Composer and npm both key their caches on the lockfile, so a fresh runner is not a cold one. The step does two installs in parallel because they are independent dependency trees.',
            'desc' => 'Install PHP and JS dependencies',
            'meta' => 'composer · npm',
            'check' => 'Composer + npm cache warm',
            'seconds' => 2,
            'icon' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        ],
        [
            'key' => 'static',
            'name' => 'Static analysis',
            'kind' => 'gate',
            'command' => 'pint --test · phpstan analyse',
            'title' => 'Style before logic',
            'note' => 'Pint runs first because it is cheap and loud; a formatting failure never reaches PHPStan. PHPStan then guards the types the next three stages will build on.',
            'desc' => 'PSR-12 and static types',
            'meta' => 'pint · phpstan',
            'check' => 'No formatting or type errors',
            'seconds' => 4,
            'icon' => '<path d="M12 3v18M3 12h18M7 7l10 10M17 7l-10 10" stroke-linecap="round" stroke-linejoin="round"/>',
        ],
        [
            'key' => 'tests',
            'name' => 'Tests',
            'kind' => 'gate',
            'command' => 'php artisan test --parallel',
            'title' => 'Behaviour, not implementation',
            'note' => '03–05 run concurrently: Static analysis, Tests, and Build fan out from one trigger and rejoin at Release.',
            'desc' => 'Feature and unit coverage',
            'meta' => 'pest · parallel',
            'check' => 'All tests green',
            'seconds' => 5,
            'icon' => '<path d="M9 12l2 2 4-4"/><path d="M3 12h6v6H3zM15 6h6v6h-6z" stroke-linecap="round" stroke-linejoin="round"/>',
        ],
        [
            'key' => 'build',
            'name' => 'Build',
            'kind' => 'gate',
            'command' => 'npm run build',
            'title' => 'Artifacts are built, not copied',
            'note' => 'The asset build is a separate lane because it is the one stage that can legitimately fail on a frontend-only change. It produces the fingerprinted files the deploy will serve.',
            'desc' => 'Vite build, fingerprinted assets',
            'meta' => 'vite · manifest',
            'check' => 'Build artifacts produced',
            'seconds' => 4,
            'icon' => '<path d="M6 3v18M18 9a9 9 0 0 0-9 9"/><path d="M12 14v4M9 16h6" stroke-linecap="round" stroke-linejoin="round"/>',
        ],
        [
            'key' => 'release',
            'name' => 'Release',
            'kind' => 'step',
            'command' => 'releases/<sha>/ · optimize',
            'title' => 'Build once, release many',
            'note' => 'The release is a directory, not a tarball on the way to production — it is the thing the deploy will point at. optimize runs here so the config and route caches belong to this release.',
            'desc' => 'Package and optimize',
            'meta' => 'release · optimize',
            'check' => 'Release directory ready',
            'seconds' => 3,
            'icon' => '<path d="M12 3v18M5 10h14M5 14h14" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 7l5 5 5-5" stroke-linecap="round" stroke-linejoin="round"/>',
        ],
        [
            'key' => 'deploy',
            'name' => 'Deploy',
            'kind' => 'step',
            'command' => 'migrate --force · queue:restart',
            'title' => 'The switch is a symlink',
            'note' => 'Deploy does two things that must happen in order: the schema moves forward, then the workers are told to pick up the new code. Nothing here re-copies the release — the symlink already did that.',
            'desc' => 'Symlink, migrate, restart workers',
            'meta' => 'vercel · symlink',
            'check' => 'Release live, workers restarted',
            'seconds' => 3,
            'icon' => '<path d="M3 12h18M12 3v18M8 6l-5 6 5 6M16 6l5 6-5 6" stroke-linecap="round" stroke-linejoin="round"/>',
        ],
        [
            'key' => 'verify',
            'name' => 'Verify',
            'kind' => 'check',
            'command' => 'GET /up · smoke tests',
            'title' => 'The deploy is not done until it answers',
            'note' => 'The health check is the first thing the verifier hits, because it is the cheapest proof that the new code is actually serving. A failing smoke test rolls the symlink back without re-running any other stage.',
            'desc' => 'Health check and smoke tests',
            'meta' => 'GET /up',
            'check' => 'Health check green',
            'seconds' => 2,
            'icon' => '<circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>',
        ],
    ];

    /** The labels on the right-hand rail of the plate. */
    $chips = [
        'GitHub Actions',
        'ubuntu-latest',
        'PHP 8.5',
        'Node 22',
        'Composer cache',
        'npm cache',
    ];

    /** The three notes under the plate. */
    $notes = [
        [
            'label' => 'No maintenance page',
            'title' => 'The switch is a symlink',
            'body' => 'The release is assembled beside the live one and switched in a single rename, so the site is never serving a half-copied tree. The down page is reserved for schema work that cannot be done in place.',
        ],
        [
            'label' => 'No stale caches',
            'title' => 'Caches are built, not warmed',
            'body' => 'optimize runs inside the deploy, after the code lands and before traffic moves. A config cache built against the previous release is a bug wearing a speed-up\'s clothes.',
        ],
        [
            'label' => 'No orphaned workers',
            'title' => 'Workers are told, not killed',
            'body' => 'queue:restart lets Horizon finish the job in hand and then pick up the new code, so a queue worker never serves a release that no longer exists on disk.',
        ],
    ];

    // ---- geometry for the mind-map SVG --------------------------------------
    //
    // Everything here is DERIVED from the two numbers the stylesheet uses for
    // the chain, so a spoke can never drift away from the card it belongs to:
    //
    //   $trackMinWidth  = .cicd-track  { min-width }   (cicd.css)
    //   $cardGap        = .cicd-cell   { margin-right } (cicd.css)
    //
    // The map's SVG is stretched to the track's rendered width
    // (`preserveAspectRatio="none"`), so its viewBox is the track's MINIMUM
    // width: at that width the spokes land on the card centres, and because
    // the cards are `flex: 1` with a FIXED gap, the centres barely move as
    // the track grows — the alignment holds across the whole range.
    // 982, not 980: the cells refuse to shrink below 100px each, so 8 × 100
    // plus the seven 26px connectors is the track's true minimum. Deriving
    // the spokes from 980 while the content floors at 982 put every spoke
    // ~2px off its card once the wire SVG stretched to the rendered width.
    // This number and `min-width` on `.cicd-track` change together.
    $trackMinWidth = 982;
    $cardGap = 26;
    $cardCount = count($stages);

    // The map's own proportions.
    $mapHeight = 148;
    $wireWidth = $trackMinWidth;

    // The bus runs through the middle of the map. A spoke per stage drops onto
    // it from the track above, and the core sits on the bus at the centre.
    $busY = (int) round($mapHeight * 0.62);          // 92

    // The card geometry, and therefore the spoke geometry.
    $cardWidth = ($trackMinWidth - ($cardGap * ($cardCount - 1))) / $cardCount;

    $spokes = [];
    for ($i = 0; $i < $cardCount; $i++) {
        $spokes[] = round(($i * ($cardWidth + $cardGap)) + ($cardWidth / 2), 2);
    }

    // The bus runs a little past the first and last stage, so the chain
    // reads as entering the bus and leaving it.
    $busStart = 20;
    $busEnd = $wireWidth - 20;

    // The rollback edge: from the last stage on the bus back toward the release
    // stage, skipping Deploy. Drawn as a hairpin that dips BELOW the bus and
    // comes back up to it, so the edge reads as a path turning around rather
    // than as the bus changing colour — the whole point of the edge is that
    // it runs against the flow.
    $rollbackStartX = $spokes[$cardCount - 1];      // Verify
    $rollbackEndX = $spokes[5];                      // Release

    $rollbackDrop = 22;
    $rollbackRadius = 8;
    $rollbackBottom = $busY + $rollbackDrop;

    $rollback = "M{$rollbackStartX} {$busY}"
        . " L{$rollbackStartX} " . ($rollbackBottom - $rollbackRadius)
        . " Q{$rollbackStartX} {$rollbackBottom} " . ($rollbackStartX - $rollbackRadius) . " {$rollbackBottom}"
        . ' L' . ($rollbackEndX + $rollbackRadius) . " {$rollbackBottom}"
        . " Q{$rollbackEndX} {$rollbackBottom} {$rollbackEndX} " . ($rollbackBottom - $rollbackRadius)
        . " L{$rollbackEndX} {$busY}";

    // The rollback label sits on the curve, not inside it, so it can be read
    // without overlapping the bus. Positioned as a percentage of the wire
    // width so it scales with the viewBox.
    $rollbackMidX = ($rollbackStartX + $rollbackEndX) / 2;
    $rollbackLabelX = ($rollbackMidX / $wireWidth) * 100;
    $rollbackLabelY = ($rollbackBottom / $mapHeight) * 100;

    $cortex = 'M60 480 C 200 300, 280 360, 470 360 C 650 360, 800 220, 1020 240 C 1180 256, 1260 360, 1360 420 L 1380 520 L 60 520 Z';
    $fissure = 'M60 480 C 200 300, 280 360, 470 360 C 650 360, 800 220, 1020 240 C 1180 256, 1260 360, 1360 420';
    $sulci = [
        'M200 470 C 260 430, 340 430, 420 470',
        'M820 250 C 900 230, 980 230, 1060 250',
        'M1180 430 C 1220 400, 1280 400, 1320 430',
    ];

    // Synapses: one per stage, dropping from the track onto the bus. Positions
    // are derived from the same step the spokes use, so the picture stays
    // honest when the track is rewritten.
    $synapses = [];
    foreach ($spokes as $spokeX) {
        $sx = $spokeX;
        $sy = $busY - 26;
        $synapses[] = [$sx, $sy];
    }

    // ---- console content ---------------------------------------------------------

    $logLines = [
        ['stage' => 1, 'first' => true, 'text' => 'Triggering workflow on push to main', 'seconds' => 0],
        ['stage' => 1, 'first' => false, 'text' => 'concurrency: group: release, cancel-in-progress: true', 'seconds' => 0],
        ['stage' => 1, 'first' => false, 'text' => 'Running on ubuntu-latest', 'seconds' => 0],
        ['stage' => 2, 'first' => true, 'text' => '$ composer install --prefer-dist', 'seconds' => 0],
        ['stage' => 2, 'first' => false, 'text' => 'Loading composer repositories with package information', 'seconds' => 0],
        ['stage' => 2, 'first' => false, 'text' => 'Installing dependencies (including require-dev) from lock file', 'seconds' => 0],
        ['stage' => 2, 'first' => false, 'text' => '$ npm ci', 'seconds' => 0],
        ['stage' => 2, 'first' => false, 'text' => 'added 142 packages in 8s', 'seconds' => 0],
        ['stage' => 0, 'first' => false, 'text' => '', 'seconds' => 0],
        ['stage' => 3, 'first' => true, 'text' => '$ pint --test', 'seconds' => 0],
        ['stage' => 3, 'first' => false, 'text' => 'No errors detected! Nice work.', 'seconds' => 0],
        ['stage' => 3, 'first' => false, 'text' => '$ phpstan analyse', 'seconds' => 0],
        ['stage' => 3, 'first' => false, 'text' => 'No errors!', 'seconds' => 0],
        ['stage' => 0, 'first' => false, 'text' => '', 'seconds' => 0],
        ['stage' => 4, 'first' => true, 'text' => '$ php artisan test --parallel', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => 'PASS  Tests\Feature\HomeCicdSectionTest', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => '  ✔ the pipeline section sits between the hero and the writing section', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => '  ✔ the rail lists all eight stages in pipeline order', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => '  ✔ every stage carries the Laravel command a reader can go and run', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => '  ✔ the gates are named as gates and the parallel lanes are declared', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => '  ✔ the section is labelled by its heading for assistive technology', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => 'PASS  Tests\Unit\AuthorProfileTest', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => '  ✔ builds the profile from configuration', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => '  ✔ exposes an expert area by slug', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => 'PASS  20 tests', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => 'No tests failed.', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => 'Deposit: 25 tests', 'seconds' => 0],
        ['stage' => 4, 'first' => false, 'text' => 'Duration: 2.42s', 'seconds' => 0],
        ['stage' => 0, 'first' => false, 'text' => '', 'seconds' => 0],
        ['stage' => 5, 'first' => true, 'text' => '$ npm run build', 'seconds' => 0],
        ['stage' => 5, 'first' => false, 'text' => 'vite v5.2.11 — 빌드 완료 in 2.12s', 'seconds' => 0],
        ['stage' => 5, 'first' => false, 'text' => '✓ 24 modules transformed.', 'seconds' => 0],
        ['stage' => 5, 'first' => false, 'text' => '✓ manifest.json  2.4kb', 'seconds' => 0],
        ['stage' => 5, 'first' => false, 'text' => '✓ favicon.svg  1.1kb', 'seconds' => 0],
        ['stage' => 5, 'first' => false, 'text' => '✓ assets/app.css  94kb', 'seconds' => 0],
        ['stage' => 5, 'first' => false, 'text' => '✓ assets/app.js  186kb', 'seconds' => 0],
        ['stage' => 0, 'first' => false, 'text' => '', 'seconds' => 0],
        ['stage' => 6, 'first' => true, 'text' => 'Preparing release a1b2c3d4', 'seconds' => 0],
        ['stage' => 6, 'first' => false, 'text' => '$ php artisan optimize', 'seconds' => 0],
        ['stage' => 6, 'first' => false, 'text' => 'Config cache cleared!', 'seconds' => 0],
        ['stage' => 6, 'first' => false, 'text' => 'Config cache created successfully.', 'seconds' => 0],
        ['stage' => 6, 'first' => false, 'text' => 'Route cache cleared!', 'seconds' => 0],
        ['stage' => 6, 'first' => false, 'text' => 'Routes cached successfully.', 'seconds' => 0],
        ['stage' => 6, 'first' => false, 'text' => 'Compiled views cleared!', 'seconds' => 0],
        ['stage' => 6, 'first' => false, 'text' => 'Compiled views cache created successfully.', 'seconds' => 0],
        ['stage' => 6, 'first' => false, 'text' => 'Application compiled successfully.', 'seconds' => 0],
        ['stage' => 6, 'first' => false, 'text' => 'Release prepared: releases/a1b2c3d4/', 'seconds' => 0],
        ['stage' => 0, 'first' => false, 'text' => '', 'seconds' => 0],
        ['stage' => 7, 'first' => true, 'text' => '$ ln -sfn releases/a1b2c3d4 current', 'seconds' => 0],
        ['stage' => 7, 'first' => false, 'text' => '✓ atomic switch · php-fpm reloaded', 'seconds' => 0],
        ['stage' => 7, 'first' => false, 'text' => '$ php artisan queue:restart', 'seconds' => 0],
        ['stage' => 7, 'first' => false, 'text' => '✓ Horizon rolling restart · previous release kept for rollback', 'seconds' => 0],
        ['stage' => 0, 'first' => false, 'text' => '', 'seconds' => 0],
        ['stage' => 8, 'first' => true, 'text' => 'Verifying deployment', 'seconds' => 0],
        ['stage' => 8, 'first' => false, 'text' => 'GET /up → 200 OK · 142ms', 'seconds' => 0],
        ['stage' => 8, 'first' => false, 'text' => 'Smoke tests passed · 4 assertions', 'seconds' => 0],
        ['stage' => 8, 'first' => false, 'text' => '✓ Verified · a1b2c3d4 live', 'seconds' => 0],
    ];

    $testLines = [
        ['glyph' => '>', 'text' => 'Deprecations: 0', 'tone' => 'prompt'],
        ['glyph' => '✓', 'text' => 'Tests: 6 passed (8 assertions)', 'tone' => 'ok'],
        ['glyph' => '✓', 'text' => 'Remote: 2 passed', 'tone' => 'ok'],
        ['glyph' => '✓', 'text' => 'Everything OK', 'tone' => 'ok'],
    ];

    $deployLines = [
        ['glyph' => '>', 'text' => 'ln -sfn releases/a1b2c3d4 current', 'tone' => 'prompt'],
        ['glyph' => '✓', 'text' => 'atomic switch · php-fpm reloaded', 'tone' => 'ok'],
        ['glyph' => '>', 'text' => 'php artisan queue:restart', 'tone' => 'prompt'],
        ['glyph' => '✓', 'text' => 'Horizon rolling restart · previous release kept for rollback', 'tone' => 'ok'],
    ];

    $environmentRows = [
        ['name' => 'Production', 'detail' => 'current → a1b2c3d4', 'state' => 'Live'],
        ['name' => 'Staging', 'detail' => 'current → a1b2c3d4', 'state' => 'Live'],
        ['name' => 'PR previews', 'detail' => '2 open · ephemeral', 'state' => 'Idle'],
    ];

    /**
     * The demo clock, derived from the stage table so the checklist, the
     * duration readout and the log timings all agree.
     */
    $seconds = array_column($stages, 'seconds');
@endphp

<section
    id="cicd"
    class="relative border-t border-ink-200 py-20 lg:py-24 dark:border-ink-800"
    aria-labelledby="{{ $headingId }}"
    x-data="cicdPipeline({{ Illuminate\Support\Js::from(['seconds' => $seconds]) }})"
>
    <div class="shell">
        {{-- ============================================================== --
         | Header — the marker, the claim, the controls that drive the demo,
         | and, on the right, the stack chips over the repo status card.
         --}}
        <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start lg:gap-x-14" data-reveal>
            <div class="min-w-0">
                <x-app.ui.section-marker :number="$number" :label="$label" />

                <h2 id="{{ $headingId }}" class="mt-5 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50 sm:text-4xl lg:text-5xl">
                    {{ $heading }}
                </h2>

                <p class="mt-4 max-w-2xl text-base leading-relaxed text-ink-500 dark:text-ink-400 sm:text-lg">
                    {{ $lead }}
                </p>

                <div class="mt-7 flex flex-wrap items-center gap-3">
                    {{-- Run state rides on `data-playing`, like every other
                         state on the plate: the stylesheet swaps the icon,
                         and the server-rendered value is the correct one
                         before Alpine boots. --}}
                    <button type="button" class="cicd-btn cicd-btn--primary" data-playing="false" :data-playing="playing ? 'true' : 'false'" x-on:click="toggle()">
                        <svg class="cicd-btn__icon cicd-btn__icon--play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
                            <path d="M8 5.5v13l11-6.5z" />
                        </svg>
                        <svg class="cicd-btn__icon cicd-btn__icon--pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
                            <path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z" />
                        </svg>
                        <span x-text="playing ? 'Pause' : 'Run Demo'">Run Demo</span>
                    </button>

                    <button type="button" class="cicd-btn cicd-btn--ghost" x-on:click="reset()">
                        <svg class="cicd-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M3 12a9 9 0 1 0 3-6.7" />
                            <path d="M3 4v5h5" />
                        </svg>
                        Reset
                    </button>

                    {{-- The badge is the plate's own clock, surfaced where an
                         operator would look for it: next to the controls,
                         not buried in the console. --}}
                    <span class="cicd-live" data-state="queued" :data-state="workflowState()">
                        <span class="cicd-live__dot" aria-hidden="true"></span>
                        <span class="cicd-live__word" x-text="workflowStatus()">Queued</span>
                        <span class="cicd-live__time" x-text="duration()">0s (queued)</span>
                    </span>
                </div>
            </div>

            <div class="flex flex-col items-start gap-5 sm:items-end lg:items-end">
                <dl class="cicd-stats">
                    <div class="cicd-stats__item">
                        <dt>Stages</dt>
                        <dd>08</dd>
                    </div>
                    <div class="cicd-stats__item">
                        <dt>Gates</dt>
                        <dd>03</dd>
                    </div>
                    <div class="cicd-stats__item">
                        <dt>Switch</dt>
                        <dd>atomic</dd>
                    </div>
                </dl>

                <ul class="cicd-chips" aria-label="Toolchain">
                    @foreach ($chips as $chip)
                        <li class="cicd-chip">{{ $chip }}</li>
                    @endforeach
                </ul>

                {{-- The repo card. It is part of the demo, so it says DEMO on
                     it; its workflow cell is bound to the same clock as the
                     chain below, which is what makes the two read as one
                     instrument rather than two decorations. --}}
                <div class="cicd-repo">
                    <span class="cicd-repo__tag">demo</span>

                    <div class="cicd-repo__id">
                        <span class="cicd-repo__mark" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="currentColor" focusable="false">
                                <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23a11.5 11.5 0 0 1 6 0c2.295-1.552 3.3-1.23 3.3-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
                            </svg>
                        </span>

                        <div class="cicd-repo__name">
                            <p>
                                shamimwhois/shamim
                                <span class="cicd-repo__badge">Public</span>
                            </p>
                            <p class="cicd-repo__meta">main · a1b2c3d4 · Laravel 13</p>
                        </div>
                    </div>

                    <dl class="cicd-repo__cells">
                        <div>
                            <dt>Last push</dt>
                            <dd>2 mins ago</dd>
                        </div>
                        <div>
                            <dt>Workflow</dt>
                            <dd class="cicd-repo__state">
                                <span class="cicd-state-dot" data-status="queued" :data-status="workflowState()" aria-hidden="true"></span>
                                <span x-text="workflowStatus()">Queued</span>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        {{-- ============================================================== --
         | The plate. `data-stage` is the selection (which stage the reader
         | asked about); `data-cursor` is the run (how far it has come).
         | Alpine only ever rewrites those two attributes.
         |
         | One column at every width: the chain needs the whole plate to
         | show eight stages without scrolling, and the console carries
         | its own internal grid rather than borrowing a column.
         --}}
        <div
            class="cicd-plate mt-10"
            data-stage="1"
            data-cursor="1"
            :data-stage="stage"
            :data-cursor="cursor"
        >
            {{-- ---------------------------------------------------------- --
             | Band 1 — the chain, laid over the digital cortex.
             --}}
            <div class="cicd-run" x-ref="run">
                {{-- The field: a dot grid, an aurora wash, the brain, its
                     lattice, a scan sweep, and the pool of light the core
                     sits in. Pure texture — aria-hidden, no bindings, no
                     pointers. --}}
                <div class="cicd-field" aria-hidden="true">
                    <div class="cicd-field__grid"></div>
                    <div class="cicd-field__aurora"></div>
                    <div class="cicd-field__glow cicd-field__glow--fine"></div>

                    <svg class="cicd-cortex" viewBox="0 0 1440 520" preserveAspectRatio="xMidYMid slice" focusable="false">
                        <defs>
                            <linearGradient id="cicd-cortex-fill" x1="0" y1="0" x2="0.4" y2="1">
                                <stop offset="0%"   class="cicd-cortex-top" />
                                <stop offset="100%" class="cicd-cortex-bottom" />
                            </linearGradient>

                            <linearGradient id="cicd-scan-fill" x1="0" y1="0" x2="1" y2="0">
                                <stop offset="0%"   class="cicd-scan-edge" />
                                <stop offset="50%"   class="cicd-scan-core" />
                                <stop offset="100%"  class="cicd-scan-edge" />
                            </linearGradient>

                            <linearGradient id="cicd-scan-fill-fine" x1="0" y1="0" x2="1" y2="0">
                                <stop offset="0%"   class="cicd-scan-edge" />
                                <stop offset="42%"   class="cicd-scan-core" opacity="0.7" />
                                <stop offset="58%"   class="cicd-scan-core" opacity="0.7" />
                                <stop offset="100%"  class="cicd-scan-edge" />
                            </linearGradient>

                            <pattern id="cicd-lattice" width="20" height="20" patternUnits="userSpaceOnUse">
                                <circle class="cicd-lattice-dot" cx="1.5" cy="1.5" r="1.05" />
                            </pattern>

                            <clipPath id="cicd-cortex-clip">
                                <path d="{{ $cortex }}" />
                            </clipPath>
                        </defs>

                        <path d="{{ $cortex }}" fill="url(#cicd-cortex-fill)" />

                        <g clip-path="url(#cicd-cortex-clip)">
                            <rect width="1440" height="520" fill="url(#cicd-lattice)" />
                            <rect class="cicd-scan" width="1440" height="70" fill="url(#cicd-scan-fill)" />
                            <rect class="cicd-scan cicd-scan--fine" width="1440" height="70" fill="url(#cicd-scan-fill-fine)" />
                        </g>

                        <path d="{{ $cortex }}" class="cicd-cortex-outline" />
                        <path d="{{ $fissure }}" class="cicd-cortex-fissure" />

                        @foreach ($sulci as $sulcus)
                            <path d="{{ $sulcus }}" class="cicd-cortex-sulcus" />
                        @endforeach

                        @foreach ($synapses as $index => [$sx, $sy])
                            <circle
                                class="cicd-synapse"
                                cx="{{ $sx }}"
                                cy="{{ $sy }}"
                                r="2.4"
                                style="animation-delay: {{ $index * 0.7 }}s"
                            />
                            <circle
                                class="cicd-synapse cicd-synapse--fine"
                                cx="{{ $sx + 7 }}"
                                cy="{{ $sy - 5 }}"
                                r="1.1"
                                style="animation-delay: {{ $index * 0.7 + 0.4 }}s"
                            />
                        @endforeach
                    </svg>
                </div>

                {{-- The chain and its mind map scroll as one track, so a
                     spoke never drifts from the card it belongs to; below
                     the track's minimum width the row scrolls sideways
                     rather than crushing eight cards into six. --}}
                <div
                    class="cicd-scroll"
                    role="region"
                    aria-label="{{ __('Pipeline stages — scroll sideways to see every stage') }}"
                    tabindex="0"
                >
                    <div class="cicd-track">
                        <ol class="cicd-cards">
                            @foreach ($stages as $index => $stage)
                                @php
                                    $position = $index + 1;
                                    $isGate = $stage['kind'] === 'gate';
                                    $initialStatus = $position === 1 ? 'running' : 'pending';
                                    $initialLabel = $position === 1 ? 'Running' : 'Pending';
                                @endphp

                                {{-- Status lives on the cell, not the button:
                                     it colours the card, its dot and the
                                     connector into the next stage, and the
                                     stylesheet reads it from one place. --}}
                                <li class="cicd-cell" data-status="{{ $initialStatus }}" :data-status="statusOf({{ $position }})">
                                    <button
                                        type="button"
                                        class="cicd-card"
                                        aria-pressed="{{ $position === 1 ? 'true' : 'false' }}"
                                        :aria-pressed="stage === {{ $position }} ? 'true' : 'false'"
                                        aria-controls="cicd-stage-{{ $position }}"
                                        x-on:click="select({{ $position }})"
                                        x-on:focus="playing ? null : (stage = {{ $position }})"
                                    >
                                        <span class="cicd-card__top">
                                            <span class="cicd-row__ord">{{ str_pad((string) $position, 2, '0', STR_PAD_LEFT) }}</span>
                                            @if ($stage['kind'] !== 'step')
                                                <span @class(['cicd-card__stamp', 'is-gate' => $isGate, 'is-check' => $stage['kind'] === 'check'])>{{ $stage['kind'] }}</span>
                                            @endif
                                        </span>

                                        <span class="cicd-card__icon" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                                {!! $stage['icon'] !!}
                                            </svg>
                                        </span>

                                        <span class="cicd-row__name">{{ $stage['name'] }}</span>

                                        <span class="cicd-card__desc">{{ $stage['desc'] }}</span>

                                        <span class="cicd-card__state">
                                            <span class="cicd-state-dot" aria-hidden="true"></span>
                                            <span class="cicd-card__status" x-text="statusLabel({{ $position }})">{{ $initialLabel }}</span>
                                        </span>

                                        <span class="cicd-card__foot">
                                            <span @class(['cicd-row__kind', 'is-gate' => ($isGate || ($stage['kind2'] ?? null) === 'gate')])>{{ $stage['kind2'] ?? $stage['kind'] }}</span>
                                            <span class="cicd-card__meta">{{ $stage['meta'] }}</span>
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ol>

                        {{-- The mind map: one bus, a spoke per stage, the core
                             the bus passes through, and the one edge that runs
                             against the flow. All geometry, no bindings — the
                             state reaches it through the cards above. --}}
                        <div class="cicd-map" aria-hidden="true">
                            <svg
                                class="cicd-wires"
                                viewBox="0 0 {{ $wireWidth }} {{ $mapHeight }}"
                                preserveAspectRatio="none"
                                fill="none"
                                focusable="false"
                            >
                                <g class="cicd-spokes">
                                    @foreach ($spokes as $spokeX)
                                        <path class="cicd-spoke" d="M{{ $spokeX }} 0 V{{ $busY }}" />
                                    @endforeach
                                </g>

                                <path class="cicd-bus" d="M{{ $busStart }} {{ $busY }} H{{ $busEnd }}" pathLength="100" />
                                <path class="cicd-packet" d="M{{ $busStart }} {{ $busY }} H{{ $busEnd }}" pathLength="100" />
                                <path class="cicd-rollback" d="{{ $rollback }}" pathLength="100" />
                            </svg>

                            <span class="cicd-core">
                                <span class="cicd-core__pulse" aria-hidden="true"></span>
                                <span class="cicd-core__dot" aria-hidden="true"></span>
                                Laravel
                            </span>

                            <span
                                class="cicd-rollback-label"
                                style="left: {{ $rollbackLabelX }}%; top: {{ $rollbackLabelY }}%"
                            >ROLLBACK</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ---------------------------------------------------------- --
             | Band 2 — the readout. One panel per stage, all eight in the
             | markup; the stylesheet shows the one the selection points at.
             --}}
            <div class="cicd-readout" aria-live="polite">
                @foreach ($stages as $index => $stage)
                    @php
                        $isGate = $stage['kind'] === 'gate';
                    @endphp

                    <div class="cicd-readout__panel" data-panel="{{ $index + 1 }}" id="cicd-stage-{{ $index + 1 }}">
                        <div class="cicd-readout__head">
                            <p class="cicd-readout__eyebrow">
                                <span class="cicd-readout__ord">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                <span aria-hidden="true" class="cicd-readout__dot">·</span>
                                <span>{{ $stage['name'] }}</span>
                                <span aria-hidden="true" class="cicd-readout__dot">·</span>
                                <span class="cicd-readout__kind">{{ $stage['kind'] }}</span>
                            </p>

                            <p class="cicd-readout__meta">
                                @if ($isGate)
                                    <span class="cicd-badge cicd-badge--gate">blocking</span>
                                @elseif ($stage['kind'] === 'check')
                                    <span class="cicd-badge cicd-badge--check">last word</span>
                                @endif
                                <span class="cicd-badge">{{ $stage['seconds'] }}s</span>
                            </p>
                        </div>

                        <h3 class="cicd-readout__title">{{ $stage['title'] }}</h3>
                        <p class="cicd-readout__note">{{ $stage['note'] }}</p>

                        <p class="cicd-readout__command">
                            <span aria-hidden="true" class="cicd-readout__prompt">$</span>
                            <code>{{ $stage['command'] }}</code>
                            <span aria-hidden="true" class="cicd-readout__caret"></span>
                        </p>
                    </div>
                @endforeach
            </div>

            {{-- ---------------------------------------------------------- --
             | Band 3 — the console: how far the run is, what it printed,
             | and the details an operator checks first.
             --}}
            <div class="cicd-console">
                {{-- Pipeline progress ------------------------------------ --}}
                <section class="cicd-panel cicd-panel--progress" aria-labelledby="cicd-progress-title">
                    <header class="cicd-panel__head">
                        <h3 id="cicd-progress-title">Pipeline Progress</h3>
                        <span class="cicd-panel__figure" x-text="percent + '%'">0%</span>
                    </header>

                    <div
                        class="cicd-bar"
                        role="progressbar"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="0"
                        :aria-valuenow="percent"
                        aria-label="Pipeline progress"
                    >
                        <div class="cicd-bar__fill" style="width: 0%" :style="'width: ' + percent + '%'"></div>
                    </div>

                    <ol class="cicd-checks">
                        @foreach ($stages as $index => $stage)
                            @php
                                $position = $index + 1;
                                $initialStatus = $position === 1 ? 'running' : 'pending';
                                $initialTime = $position === 1 ? '…' : '—';
                            @endphp

                            <li class="cicd-check" data-status="{{ $initialStatus }}" :data-status="statusOf({{ $position }})">
                                <span class="cicd-check__mark" role="img" :aria-label="statusWord({{ $position }})" aria-label="{{ $position === 1 ? 'running' : 'pending' }}"></span>
                                <span class="cicd-check__label">{{ $stage['check'] }}</span>
                                <span class="cicd-check__time" x-text="stepTime({{ $position }})">{{ $initialTime }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>

                {{-- Build logs (tabbed) ---------------------------------- --}}
                <section class="cicd-panel cicd-panel--logs" aria-labelledby="cicd-logs-title">
                    <header class="cicd-panel__head">
                        <h3 id="cicd-logs-title">Build Logs</h3>
                        <span class="cicd-panel__figure cicd-panel__figure--muted">demo run</span>
                    </header>

                    {{-- The tablist carries `data-tab`; the stylesheet shows
                         exactly the pane that matches it. Server-rendered at
                         `logs`, so one pane is visible with JavaScript off
                         and the ARIA pairing is never broken. --}}
                    <div class="cicd-tabs" role="tablist" aria-label="Pipeline output" data-tab="logs" :data-tab="tab">
                        <button
                            type="button"
                            role="tab"
                            id="cicd-tab-logs"
                            aria-controls="cicd-pane-logs"
                            aria-selected="true"
                            :aria-selected="tab === 'logs' ? 'true' : 'false'"
                            x-on:click="tab = 'logs'"
                        >Build Logs</button>

                        {{-- The tabs are always visible — only the panes
                             hide. Active styling keys off aria-selected, so
                             the state lives in one attribute, like the rest
                             of the plate. --}}
                        <button
                            type="button"
                            role="tab"
                            id="cicd-tab-tests"
                            aria-controls="cicd-pane-tests"
                            aria-selected="false"
                            :aria-selected="tab === 'tests' ? 'true' : 'false'"
                            x-on:click="tab = 'tests'"
                        >Tests</button>

                        <button
                            type="button"
                            role="tab"
                            id="cicd-tab-deploy"
                            aria-controls="cicd-pane-deploy"
                            aria-selected="false"
                            :aria-selected="tab === 'deploy' ? 'true' : 'false'"
                            x-on:click="tab = 'deploy'"
                        >Deploy</button>

                        <button
                            type="button"
                            role="tab"
                            id="cicd-tab-environments"
                            aria-controls="cicd-pane-environments"
                            aria-selected="false"
                            :aria-selected="tab === 'environments' ? 'true' : 'false'"
                            x-on:click="tab = 'environments'"
                        >Environments</button>
                    </div>

                    <div class="cicd-term">
                        <div class="cicd-term__chrome" aria-hidden="true">
                            <span class="cicd-term__dots"><i></i><i></i><i></i></span>
                            <span class="cicd-term__file">laravel-ci.yml</span>
                            <span class="cicd-term__live" data-state="queued" :data-state="workflowState()">
                                <span class="cicd-term__lamp"></span>
                                <span x-text="workflowStatus()">Queued</span>
                            </span>
                        </div>

                        <div class="cicd-term__pane" role="tabpanel" id="cicd-pane-logs" aria-labelledby="cicd-tab-logs" data-pane="logs">
                            <ol class="cicd-lines">
                                @foreach ($logLines as $index => $line)
                                    @php
                                        $initialStatus = $line['stage'] < 1 ? 'done' : ($line['stage'] > 1 ? 'pending' : ($line['first'] ? 'running' : 'pending'));
                                        $initialTime = $initialStatus === 'running' ? '…' : '—';
                                    @endphp

                                    <li class="cicd-line" data-status="{{ $initialStatus }}" :data-status="lineState({{ $line['stage'] }}, {{ $line['first'] ? 'true' : 'false' }})">
                                        <span class="cicd-line__no">{{ $index + 1 }}</span>
                                        <span class="cicd-line__text"><span class="cicd-line__chevron" aria-hidden="true">&gt;</span>{{ $line['text'] }}</span>
                                        <span
                                            class="cicd-line__mark"
                                            role="img"
                                            aria-label="{{ $initialStatus }}"
                                            :aria-label="lineState({{ $line['stage'] }}, {{ $line['first'] ? 'true' : 'false' }})"
                                        ></span>
                                        <span class="cicd-line__time" x-text="lineTime({{ $line['stage'] }}, {{ $line['first'] ? 'true' : 'false' }}, {{ $line['seconds'] }})">{{ $initialTime }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>

                        <div class="cicd-term__pane" role="tabpanel" id="cicd-pane-tests" aria-labelledby="cicd-tab-tests" data-pane="tests">
                            <ol class="cicd-lines cicd-lines--static">
                                @foreach ($testLines as $line)
                                    <li class="cicd-line cicd-line--{{ $line['tone'] }}">
                                        <span class="cicd-line__no" aria-hidden="true">{{ $line['glyph'] }}</span>
                                        <span class="cicd-line__text">{{ $line['text'] }}</span>
                                    </li>

                                @endforeach
                            </ol>
                        </div>

                        <div class="cicd-term__pane" role="tabpanel" id="cicd-pane-deploy" aria-labelledby="cicd-tab-deploy" data-pane="deploy">
                            <ol class="cicd-lines cicd-lines--static">
                                @foreach ($deployLines as $line)
                                    <li class="cicd-line cicd-line--{{ $line['tone'] }}">
                                        <span class="cicd-line__no" aria-hidden="true">{{ $line['glyph'] }}</span>
                                        <span class="cicd-line__text">{{ $line['text'] }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>

                        <div class="cicd-term__pane" role="tabpanel" id="cicd-pane-environments" aria-labelledby="cicd-tab-environments" data-pane="environments">
                            <ul class="cicd-envs">
                                @foreach ($environmentRows as $row)
                                    <li class="cicd-env">
                                        <span class="cicd-env__name">{{ $row['name'] }}</span>
                                        <span class="cicd-env__detail">{{ $row['detail'] }}</span>
                                        <span @class(['cicd-env__state', 'is-live' => $row['state'] === 'Live'])>{{ $row['state'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </section>

                {{-- Workflow details ------------------------------------- --}}
                <section class="cicd-panel cicd-panel--details" aria-labelledby="cicd-details-title">
                    <header class="cicd-panel__head">
                        <h3 id="cicd-details-title">Workflow Details</h3>
                        <a
                            class="cicd-panel__link"
                            href="https://github.com/shamimwhois/shamim"
                            target="_blank"
                            rel="noopener noreferrer"
                        >View on GitHub <span aria-hidden="true">↗</span></a>
                    </header>

                    <dl class="cicd-details">
                        <div>
                            <dt>Repository</dt>
                            <dd>shamimwhois/shamim</dd>
                        </div>
                        <div>
                            <dt>Branch</dt>
                            <dd>main</dd>
                        </div>
                        <div>
                            <dt>Commit</dt>
                            <dd class="cicd-details__mono">a1b2c3d4</dd>
                        </div>
                        <div>
                            <dt>Workflow</dt>
                            <dd>Laravel CI/CD</dd>
                        </div>
                        <div>
                            <dt>Trigger</dt>
                            <dd>Push / Pull Request</dd>
                        </div>
                        <div>
                            <dt>Started at</dt>
                            <dd class="cicd-details__mono">Oct 23, 2026, 10:42 AM</dd>
                        </div>
                        <div>
                            <dt>Duration</dt>
                            <dd class="cicd-details__value">
                                <span class="cicd-state-dot" data-status="queued" :data-status="workflowState()" aria-hidden="true"></span>
                                <span x-text="duration()">0s (queued)</span>
                            </dd>
                        </div>
                        <div>
                            <dt>Environment</dt>
                            <dd><span class="cicd-env-pill">Production</span></dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>

        {{-- ============================================================== --
         | The three claims the pipeline buys. These are the sentences the
         | diagram is evidence for, so they sit under it, not inside it.
         --}}
        <div class="cicd-claims mt-10" data-reveal>
            @foreach ($notes as $index => $note)
                <div class="cicd-claim">
                    <p class="cicd-claim__label">
                        {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }} — {{ $note['label'] }}
                    </p>
                    <h3 class="cicd-claim__title">{{ $note['title'] }}</h3>
                    <p class="cicd-claim__body">{{ $note['body'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

<script>
    /**
     * The demo clock behind the CI/CD plate.
     *
     * One number — `cursor` — is the run; everything derives from it:
     * status words, checklist timings, log states, the progress bar, the
     * duration and the repo card's workflow cell. `stage` is only the
     * reader's selection, moved by focus and clicks, never by the clock, so
     * the aria-live readout changes when *they* act. The run plays itself
     * once when the plate comes into view — except for a reader who asked
     * for reduced motion, who gets a still plate and a Run Demo button.
     *
     * `cicdPipeline` is the FACTORY, not the component: it returns the
     * object that `x-data` binds to. Alpine calls that object's `init()`
     * when the component is created and `destroy()` when it is removed, so
     * neither is wired up by hand here.
     */
    function cicdPipeline(config) {
        const seconds = (config && config.seconds) || [0, 0, 0, 0, 0, 0, 0, 0];

        const format = (total) => {
            const minutes = Math.floor(total / 60);
            const rest = total % 60;

            return minutes ? `${minutes}m ${rest}s` : `${rest}s`;
        };

        return {
            stage: 1,
            cursor: 1,
            finished: false,
            playing: false,
            pinned: false,
            tab: 'logs',
            timer: null,
            observer: null,
            seconds,

            statusOf(index) {
                if (this.finished || index < this.cursor) return 'done';
                if (index === this.cursor) return 'running';

                return 'pending';
            },

            statusWord(index) {
                return { done: 'completed', running: 'running', pending: 'pending' }[this.statusOf(index)];
            },

            statusLabel(index) {
                return { done: 'Completed', running: 'Running', pending: 'Pending' }[this.statusOf(index)];
            },

            lineState(stage, first) {
                if (this.finished || stage < this.cursor) return 'done';
                if (stage > this.cursor || ! first) return 'pending';

                return 'running';
            },

            lineTime(stage, first, lineSeconds) {
                const state = this.lineState(stage, first);

                if (state === 'done') return format(lineSeconds);
                return state === 'running' ? '…' : '—';
            },

            stepTime(index) {
                const state = this.statusOf(index);

                if (state === 'done') return format(this.seconds[index - 1]);
                return state === 'running' ? '…' : '—';
            },

            // A getter, not a method: the markup reads `percent` as a
            // property in three places (bar width, aria-valuenow, figure).
            get percent() {
                if (this.finished) return 100;

                return Math.round(((this.cursor - 1) / this.seconds.length) * 100);
            },

            duration() {
                let elapsed = 0;

                for (let index = 0; index < this.cursor - 1; index++) {
                    elapsed += this.seconds[index];
                }

                if (this.finished) {
                    elapsed = this.seconds.reduce((total, value) => total + value, 0);
                }

                const suffix = this.finished
                    ? ''
                    : (this.playing
                        ? ' (running)'
                        : (this.cursor > 1 ? ' (paused)' : ' (queued)'));

                return format(elapsed) + suffix;
            },

            // Success once the run clears, Running only while the clock is
            // actually ticking, Paused when a reader has steered or stopped
            // it mid-pipeline, Queued before anything happens. The cell never
            // claims a run it is not in.
            workflowState() {
                if (this.finished) return 'success';
                if (this.playing) return 'running';
                if (this.cursor > 1) return 'paused';

                return 'queued';
            },

            workflowStatus() {
                return {
                    success: 'Success',
                    running: 'Running',
                    paused: 'Paused',
                    queued: 'Queued',
                }[this.workflowState()];
            },

            select(index) {
                this.pinned = true;
                this.finished = false;
                this.stage = index;
                this.cursor = index;
            },

            toggle() {
                if (this.playing) {
                    this.pause();
                } else {
                    this.play();
                }
            },

            play() {
                window.clearInterval(this.timer);

                if (this.finished) {
                    this.finished = false;
                    this.cursor = 1;
                    this.stage = 1;
                }

                this.pinned = false;
                this.playing = true;

                this.timer = window.setInterval(() => {
                    if (this.cursor >= this.seconds.length) {
                        this.finish();
                        return;
                    }

                    this.cursor++;

                    if (! this.pinned) {
                        this.stage = this.cursor;
                    }
                }, 1100);
            },

            finish() {
                window.clearInterval(this.timer);
                this.timer = null;
                this.playing = false;
                this.finished = true;
                this.cursor = this.seconds.length;

                if (! this.pinned) {
                    this.stage = this.cursor;
                }
            },

            pause() {
                window.clearInterval(this.timer);
                this.timer = null;
                this.playing = false;
            },

            reset() {
                this.pause();
                this.pinned = false;
                this.finished = false;
                this.stage = 1;
                this.cursor = 1;
                this.tab = 'logs';
            },

            init() {
                const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                if (! reduced && 'IntersectionObserver' in window) {
                    this.observer = new IntersectionObserver((entries) => {
                        for (const entry of entries) {
                            if (! entry.isIntersecting) continue;

                            this.observer.disconnect();
                            this.play();
                        }
                    }, { threshold: 0.5 });

                    this.observer.observe(this.$refs.run);
                }

                this.onVisibility = () => {
                    if (document.hidden) this.pause();
                };

                document.addEventListener('visibilitychange', this.onVisibility);
            },

            destroy() {
                this.pause();

                if (this.observer) this.observer.disconnect();

                document.removeEventListener('visibilitychange', this.onVisibility);
            },
        };
    }
</script>
