<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Author Profile
    |--------------------------------------------------------------------------
    |
    | The site owner's professional profile. This is configuration rather than
    | content: it describes the person, not the writing, and changes rarely.
    |
    */

    'author' => [

        'name' => env('PORTFOLIO_AUTHOR_NAME', 'Shamim Hossain'),

        /*
        | Short form used in the header wordmark and page titles.
        */
        'short_name' => env('PORTFOLIO_AUTHOR_SHORT_NAME', 'shamim'),

        'role' => env('PORTFOLIO_AUTHOR_ROLE', 'Full Stack Developer'),

        'tagline' => 'I design and build web applications end to end — backends that stay fast and interfaces that stay clear — and I write about how.',

        'location' => env('PORTFOLIO_AUTHOR_LOCATION', 'Dhaka, Bangladesh'),

        'availability' => env('PORTFOLIO_AUTHOR_AVAILABILITY', 'Open to full stack developer roles'),

        'years_of_experience' => (int) env('PORTFOLIO_AUTHOR_YEARS', 15),

        'bio' => 'Full stack developer specialising in Laravel, Livewire, and developer tooling. I care about the boring parts: migrations that are reversible, queries that scale, and interfaces a new teammate can understand on their first day.',

        'long_bio' => 'I have spent the last nine years building and scaling web platforms end to end, mostly in the Laravel and PHP ecosystem with Livewire, Alpine, and Tailwind interfaces on top, and detours into TypeScript, Go, and whatever the problem actually needed. Most of my work has been on the unglamorous middle layer — the part that sits between a product idea and a database that has to hold up under load for the next three years — but I am just as comfortable owning the screen the user actually touches.

I write here because writing is how I find out whether I actually understand something. The posts are the long version of arguments I have had with myself about indexing strategy, queue design, and why the obvious solution is often the wrong one.',

        /*
        |----------------------------------------------------------------------
        | Expertise
        |----------------------------------------------------------------------
        |
        | Each entry is a capability, not a technology logo.
        |
        */

        'expertise' => [
            [
                'title' => 'Backend architecture',
                'description' => 'Service boundaries, transactional integrity, and domain modelling that survives its second year of feature requests.',
                'icon' => 'cube-transparent',
                'tags' => ['Laravel', 'PHP 8.5', 'DDD-lite', 'REST'],
            ],
            [
                'title' => 'Database performance',
                'description' => 'Index design, query plans, and eliminating N+1s before they reach production rather than after the pager goes off.',
                'icon' => 'circle-stack',
                'tags' => ['PostgreSQL', 'MySQL', 'Redis', 'EXPLAIN'],
            ],
            [
                'title' => 'Reactive interfaces',
                'description' => 'Server-driven UI with Livewire where a SPA would be over-engineering, and knowing which is which.',
                'icon' => 'bolt',
                'tags' => ['Livewire 4', 'Alpine.js', 'Tailwind 4', 'Flux'],
            ],
            [
                'title' => 'Testing & quality',
                'description' => 'Test suites that catch regressions rather than assert implementation details, backed by static analysis that runs in CI.',
                'icon' => 'beaker',
                'tags' => ['Pest', 'PHPStan', 'Pint', 'CI/CD'],
            ],
            [
                'title' => 'Observability',
                'description' => 'Structured logging, tracing, and metrics that answer "what is slow" without a debugging session.',
                'icon' => 'signal',
                'tags' => ['OpenTelemetry', 'Horizon', 'Sentry'],
            ],
            [
                'title' => 'Technical writing',
                'description' => 'Documentation and architecture decision records that make the reasoning recoverable a year later.',
                'icon' => 'pencil-square',
                'tags' => ['ADRs', 'API design', 'Mentoring'],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Selected work
        |----------------------------------------------------------------------
        |
        | Outcome first, mechanism second. Stack chips are supporting detail.
        | Four entries render as the Selected Work bento: 7+5 columns, then
        | 6+6 — matching the reference's asymmetric two-row grid. `url` is
        | rendered as the card's pill link; follow the links config's rule
        | and keep it a real placeholder host, never a dead relative path.
        */

        'projects' => [
            [
                'name' => 'Atlas Ledger',
                'slug' => 'atlas-ledger',
                'role' => 'Lead engineer',
                'summary' => 'Double-entry accounting engine handling multi-currency settlement for a payments platform.',
                'outcome' => 'Cut month-end reconciliation from 6 hours to 11 minutes by replacing a cron-driven batch with an event-sourced projection built on database-backed queues.',
                'stack' => ['Laravel', 'PostgreSQL', 'Redis', 'Horizon'],
                'url' => 'https://github.com/example/atlas-ledger',
                'docs' => [
                    [
                        'slug' => 'overview',
                        'title' => 'Overview',
                        'summary' => 'What the ledger is, the batch it replaced, and the constraints that shaped it.',
                        'body' => <<<'MD'
                            ## The problem

                            Settlement ran as one nightly batch. Every currency pair was reconciled by walking a month of transactions in memory, so the job grew with the calendar and failed in the hours nobody was watching. A failed run meant a day of books that could not be closed.

                            ## What it is

                            An append-only double-entry ledger with a projection layer. Transfers write balanced entries to a journal, and projections fold those entries into the balances and statements the finance team reads.

                            ## Constraints

                            - An entry is never edited or deleted. A correction is a new compensating entry.
                            - Every write is idempotent, keyed by the transfer's external reference.
                            - The read model must be rebuildable from the journal without the source systems.
                            - Reconciliation has to finish before the finance team starts its day.

                            ## Outcome

                            Month-end reconciliation fell from six hours to eleven minutes, and a failed projection is replayed rather than investigated at 2am.
                            MD,
                    ],
                    [
                        'slug' => 'architecture',
                        'title' => 'Architecture',
                        'summary' => 'Journal, projections, queue ownership and the invariants that keep the books balanced.',
                        'body' => <<<'MD'
                            ## Write path

                            A transfer is validated, converted to a pair of journal entries, and committed in one database transaction. The external reference is unique, so a retried request returns the original transfer instead of writing a second one.

                            ## Read model

                            Projections are ordinary tables with a checkpoint per stream. Each projection handler receives ordered journal events, applies them, and persists its checkpoint in the same transaction as the change. Replaying a stream from zero is therefore safe and repeatable.

                            ## Queues

                            Projection work runs on database-backed queues. The queue is a delivery mechanism, not the source of truth: a lost job is replayed from the journal, and duplicate delivery is absorbed by the handler's checkpoint.

                            ## Invariants

                            - Debits equal credits for every transfer.
                            - A projection never reads the current journal; it folds events in order.
                            - No handler writes outside its own projection and checkpoint.
                            - Balance reads are served from projections, never recomputed per request.
                            MD,
                    ],
                    [
                        'slug' => 'operations',
                        'title' => 'Operations',
                        'summary' => 'Lag, replays, and the month-end runbook the finance team follows.',
                        'body' => <<<'MD'
                            ## Health signals

                            Two numbers matter: journal lag (entries written but not yet projected) and checkpoint age. Both are exported as gauges, and an alert fires when lag exceeds fifteen minutes or a checkpoint has not moved during a settlement window.

                            ## Replaying a projection

                            1. Pause the projection's queue consumer.
                            2. Truncate the projection and reset its checkpoint to zero.
                            3. Resume the consumer and watch checkpoint age fall.

                            Replays are idempotent by construction, so the operator does not need to know how far the projection got before it stopped.

                            ## Month-end

                            Close the period by freezing new transfers for the window, waiting for journal lag to reach zero, then running the reconciliation report. A non-zero difference is an incident, not a rounding problem: the journal is the source of truth and the report is a projection of it.
                            MD,
                    ],
                ],
            ],
            [
                'name' => 'Meridian API',
                'slug' => 'meridian-api',
                'role' => 'Architect',
                'summary' => 'Public REST API serving 40M requests a month across three product teams.',
                'outcome' => 'Reduced p95 latency from 840ms to 190ms and eliminated a recurring incident class by introducing a query-budget layer that fails builds, not requests.',
                'stack' => ['Laravel', 'MySQL', 'OpenTelemetry', 'Kubernetes'],
                'url' => 'https://github.com/example/meridian-api',
                'docs' => [
                    [
                        'slug' => 'overview',
                        'title' => 'Overview',
                        'summary' => 'A public API with three internal consumers and one contract to keep honest.',
                        'body' => <<<'MD'
                            ## The surface

                            Meridian is the public REST API behind three product teams. It serves roughly forty million requests a month, with each team owning endpoints inside one shared contract: versioning, pagination, error shapes and rate limits are platform decisions, not per-team ones.

                            ## The problem

                            Latency crept up one endpoint at a time. A feature would add an eager load, a serialiser would add a relationship, and the p95 moved without anybody deciding it should. The recurring incident was always the same: a heavy query shipping during a peak window.

                            ## What changed

                            The API now declares a query budget per endpoint: a maximum number of queries and a maximum estimated row count. The budget is checked in CI against seeded data, so a regression fails the pull request rather than the pager.

                            ## Outcome

                            p95 fell from 840ms to 190ms over two quarters, and the timeout incident class stopped recurring.
                            MD,
                    ],
                    [
                        'slug' => 'query-budget',
                        'title' => 'The query budget',
                        'summary' => 'How budgets are declared, measured and enforced before a request reaches production.',
                        'body' => <<<'MD'
                            ## Declaring a budget

                            Every endpoint names the number of queries it may run and the largest result set it may materialise. The numbers are deliberately small: a list endpoint gets one query for the page and one for its relationships, not one per row.

                            ## Measuring

                            The test suite enables the query log, calls the endpoint with a representative fixture, and fails when either budget is exceeded. The failure message prints the queries that ran, so the fix is visible without reproducing the test locally.

                            ## Enforcing in production

                            The same budget is registered as a request listener. A breach is logged with the endpoint and the query count, and the response is still served: the budget is a design guard, not a runtime kill switch. The alert is what makes the next regression visible.

                            ## Why not a query cache

                            Caching would have hidden the problem rather than fixing it. A cache turns a slow query into a stale answer and makes the next engineer debug two systems instead of one.
                            MD,
                    ],
                    [
                        'slug' => 'operations',
                        'title' => 'Operations',
                        'summary' => 'SLOs, tracing and the on-call path for a shared public contract.',
                        'body' => <<<'MD'
                            ## Service levels

                            Availability is measured per endpoint group, not per pod. The error budget is consumed by 5xx responses and by requests that exceed the latency target, because a request that takes eight seconds has failed even when it eventually succeeds.

                            ## Tracing

                            Every request carries a trace id through the HTTP layer, the query listener and the queue jobs it dispatches. The trace is the first artefact in an incident review: it answers which query was slow, not just which endpoint was.

                            ## Changing the contract

                            Additive changes ship behind a version. A field is never repurposed, and a removal is announced for one release before it is enforced. Three consumers means a breaking change is a coordinated release, not a deploy.

                            ## On-call

                            The runbook starts with the endpoint's budget report, then the slow-query log, then the database's own plan for the offending statement. Restarting pods is the last step, not the first.
                            MD,
                    ],
                ],
            ],
            [
                'name' => 'Forge CLI',
                'slug' => 'forge-cli',
                'role' => 'Author',
                'summary' => 'Internal developer CLI that scaffolds services with migrations, CI, and observability pre-wired.',
                'outcome' => 'Brought new-service bootstrap time from two weeks of copy-paste to under an hour, and made the platform team\'s conventions the path of least resistance.',
                'stack' => ['PHP', 'Symfony Console', 'Docker', 'GitHub Actions'],
                'url' => 'https://github.com/example/forge-cli',
                'docs' => [
                    [
                        'slug' => 'overview',
                        'title' => 'Overview',
                        'summary' => 'A scaffold that ships the platform conventions instead of documenting them.',
                        'body' => <<<'MD'
                            ## The problem

                            A new service used to start as a copy of the last new service. Whatever that repository had drifted into — an old CI action, a missing health endpoint, a Dockerfile with a root user — became the starting point for the next one.

                            ## What it is

                            Forge is an internal CLI that generates a service from a template the platform team owns. The generated repository already has migrations, a test pipeline, structured logging, a health endpoint, a Dockerfile and an environment manifest.

                            ## Design rule

                            If a convention matters, it is in the template. A document that says "remember to add the health check" is a convention that will be forgotten; a template that already has one is a convention that cannot be skipped.

                            ## Outcome

                            Bootstrap time went from two weeks of copy-paste and review to under an hour, and platform review now starts from a known shape.
                            MD,
                    ],
                    [
                        'slug' => 'commands',
                        'title' => 'Commands',
                        'summary' => 'The four verbs Forge exposes and what each one touches.',
                        'body' => <<<'MD'
                            ## `forge new`

                            Creates a service from the platform template. It asks for a name and an owner, renders the template, initialises the repository, and prints the first three steps a developer needs.

                            ## `forge add`

                            Adds an optional capability — a queue worker, a scheduled command, an S3 disk — by applying the same patch the platform team uses in its reference service. It refuses to run when the target file has local changes.

                            ## `forge check`

                            Compares a repository against the template and reports drift: missing health checks, an outdated CI action, a Dockerfile that runs as root. The report is advisory; fixing is a separate, reviewable pull request.

                            ## `forge upgrade`

                            Applies a template upgrade to an existing service, one commit per capability, so a reviewer can accept the queue change and reject the logging change independently.
                            MD,
                    ],
                    [
                        'slug' => 'maintenance',
                        'title' => 'Maintenance',
                        'summary' => 'How the template is versioned and rolled out without breaking existing services.',
                        'body' => <<<'MD'
                            ## Versioning

                            The template is versioned like a library. A change that requires every service to act is a major version; a new optional capability is a minor one. `forge new` always uses the latest version, and an existing service records the version it was generated from.

                            ## Rollout

                            A template change is proved on the platform team's own service first. It reaches other services only through `forge upgrade`, which is a pull request, never a push.

                            ## Deprecations

                            A removed convention gets one release of `forge check` warnings before it disappears from the template. The warning names the replacement, so the upgrade path is in the output rather than in a wiki page.

                            ## Ownership

                            The platform team owns the template; product teams own their generated services. A bug in generated code is fixed in the template and rolled forward, not patched into each repository.
                            MD,
                    ],
                ],
            ],
            [
                'name' => 'QueueLens',
                'slug' => 'queuelens',
                'role' => 'Maintainer',
                'summary' => 'Open-source metrics exporter that turns Horizon queue telemetry into Prometheus dashboards.',
                'outcome' => 'Gave platform teams a queue-depth SLO out of the box; adopted by four internal teams in its first quarter and now shipped with the default platform image.',
                'stack' => ['PHP', 'Prometheus', 'Redis Streams', 'Grafana'],
                'url' => 'https://github.com/example/queuelens',
                'docs' => [
                    [
                        'slug' => 'overview',
                        'title' => 'Overview',
                        'summary' => 'Queue depth, wait time and failure rate as first-class Prometheus metrics.',
                        'body' => <<<'MD'
                            ## The problem

                            Horizon tells you how many jobs are waiting. It does not tell Prometheus, so a platform team either scrapes Redis itself or finds out about a queue backlog when a customer does.

                            ## What it is

                            QueueLens is a small exporter that reads Horizon's Redis structures and publishes queue depth, oldest-job age, throughput and failure counts as Prometheus metrics. A bundled Grafana dashboard turns those into a queue-depth SLO.

                            ## Design rule

                            Every metric names its queue and its connection. An aggregate that hides which queue is backed up is a dashboard that looks healthy while one worker starves.

                            ## Outcome

                            Four internal teams adopted it in the first quarter, and it now ships with the default platform image, so a new service gets queue visibility without adding an exporter.
                            MD,
                    ],
                    [
                        'slug' => 'metrics',
                        'title' => 'Metrics',
                        'summary' => 'The four series the exporter publishes and how to read them together.',
                        'body' => <<<'MD'
                            ## `queuelens_depth`

                            Jobs waiting per queue. Useful on its own only when paired with throughput: a depth of 500 is normal for a queue draining 2,000 jobs a minute and an incident for one draining 20.

                            ## `queuelens_oldest_job_seconds`

                            Age of the oldest waiting job. This is the metric that catches a stalled worker: depth grows slowly, but the oldest job's age grows without bound.

                            ## `queuelens_processed_total`

                            A counter of completed jobs by queue and status. Rate it to get throughput and failure ratio; the raw counter is only useful for restart detection.

                            ## `queuelens_failed_total`

                            A counter of failed attempts. A failure ratio above one percent is the alert, not an absolute number, because traffic differs by an order of magnitude between queues.
                            MD,
                    ],
                    [
                        'slug' => 'operations',
                        'title' => 'Operations',
                        'summary' => 'Scrape configuration, cardinality limits and the runbook for a backed-up queue.',
                        'body' => <<<'MD'
                            ## Scraping

                            The exporter listens on its own port and is scraped like any other target. It reads Redis with a dedicated connection and never writes to the queue; a failed scrape must not affect job processing.

                            ## Cardinality

                            Queue and connection names are low-cardinality by design. The exporter refuses to label by job class by default, because a label with thousands of values is a metrics bill disguised as observability.

                            ## A backed-up queue

                            1. Check `queuelens_oldest_job_seconds` to distinguish a stall from a burst.
                            2. If the oldest job is ageing, inspect the worker's last heartbeat and restart the supervisor.
                            3. If depth is rising but the oldest job is young, the queue is simply under-provisioned; scale workers before changing the code.

                            ## Compatibility

                            The exporter supports Horizon's Redis schema for the current major version. A schema change is detected at boot and reported as an exporter error rather than as silently missing metrics.
                            MD,
                    ],
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | The site's own project
        |----------------------------------------------------------------------
        |
        | Appended to Selected work by ProjectCatalog rather than stored in the
        | projects list: its documentation is this repository's docs folder, and it
        | should stay reachable even when an operator replaces the work rows in the
        | database. `docs_source` is the switch that sends the detail page to
        | `docs/*.md` instead of the configured pages above.
        */

        'site_project' => [
            'name' => 'DevFolio',
            'slug' => 'devfolio',
            'role' => 'Design & build',
            'summary' => 'The site you are reading: an editorial portfolio, blog, community feed, marketplace, Developer Lab and Ideas platform in one Laravel application.',
            'outcome' => 'One codebase carrying six public products, a Livewire-first architecture, and a test suite that treats the interface as the contract — documented from the route map down to the last component.',
            'stack' => ['Laravel 13', 'Livewire 4', 'Tailwind 4', 'Flux UI', 'Pest'],
            'url' => null,
            'docs_source' => 'repository',
        ],

        /*
        |----------------------------------------------------------------------
        | Experience
        |----------------------------------------------------------------------
        |
        | The career timeline rendered by the about page's Experience section:
        | newest employer first, one or more roles per employer, and each
        | role's highlights with the supporting stack. Periods are plain
        | strings so the copy owns its own formatting.
        |
        */

        'experience' => [
            [
                'company' => 'Lattice Systems',
                'period' => 'Jun 2025 – Present',
                'roles' => [
                    [
                        'title' => 'Full Stack Developer',
                        'period' => 'Jun 2025 – Present',
                        'highlights' => [
                            [
                                'text' => 'Settlement platform — own the event-sourced accounting core behind multi-currency settlement, having replaced a nightly reconciliation batch with a database-backed projection.',
                                'stack' => ['Laravel', 'PostgreSQL', 'Redis', 'Horizon'],
                            ],
                            [
                                'text' => 'Public API platform — designed the query-budget layer that retired a recurring timeout incident class by failing builds, not customer requests.',
                                'stack' => ['MySQL', 'OpenTelemetry', 'Kubernetes'],
                            ],
                            [
                                'text' => 'Developer tooling — author the internal service-scaffolding CLI that makes the platform conventions the path of least resistance for every new service.',
                                'stack' => ['PHP', 'Symfony Console', 'GitHub Actions'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'company' => 'Northbrim Digital',
                'period' => 'Jun 2021 – Jun 2025',
                'roles' => [
                    [
                        'title' => 'Senior Backend Engineer',
                        'period' => 'Jun 2023 – Jun 2025',
                        'highlights' => [
                            [
                                'text' => 'Multi-tenant platform — owned queue design and backpressure for ingestion workloads, moving slow-path jobs behind explicit budgets instead of retries all the way down.',
                                'stack' => ['Laravel', 'Redis', 'Horizon', 'Redis Streams'],
                            ],
                            [
                                'text' => 'Led the observability rollout: structured logs, traces, and the dashboards that answer what is slow without a debugging session.',
                                'stack' => ['OpenTelemetry', 'Sentry', 'Grafana'],
                            ],
                            [
                                'text' => 'Mentored engineers across three product teams — design reviews, decision records, and the query-plan sessions that settled most performance debates.',
                                'stack' => ['PostgreSQL', 'EXPLAIN'],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Backend Engineer',
                        'period' => 'Jun 2021 – Jun 2023',
                        'highlights' => [
                            [
                                'text' => 'Rebuilt the reporting layer around indexed read models, cutting the heaviest monthly report from minutes to seconds without touching the transactional schema.',
                                'stack' => ['PostgreSQL', 'MySQL', 'Redis'],
                            ],
                            [
                                'text' => 'Extracted the first service boundaries out of a growing monolith — REST contracts with versioning discipline the frontend teams could build against.',
                                'stack' => ['Laravel', 'REST', 'PHPUnit'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'company' => 'Ravenport Labs',
                'period' => 'Jul 2017 – Jun 2021',
                'roles' => [
                    [
                        'title' => 'Software Engineer',
                        'period' => 'Jul 2017 – Jun 2021',
                        'highlights' => [
                            [
                                'text' => 'Built and ran the catalogue and checkout services of an e-commerce platform through two Black-Friday-scale peaks.',
                                'stack' => ['PHP', 'MySQL', 'Elasticsearch', 'Varnish'],
                            ],
                            [
                                'text' => 'Introduced automated testing to a codebase that had none — the suite that eventually let deploys happen on a Friday.',
                                'stack' => ['PHPUnit', 'GitHub Actions'],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Stats band
        |----------------------------------------------------------------------
        |
        | Static professional figures. Post counts are computed from the
        | database and appended at render time — never hardcode those.
        |
        */

        'stats' => [
            ['label' => 'Years engineering', 'value' => '9'],
            ['label' => 'Systems shipped', 'value' => '30+'],
            ['label' => 'Requests served monthly', 'value' => '40M'],
            ['label' => 'Engineers mentored', 'value' => '12'],
        ],

        /*
        |----------------------------------------------------------------------
        | Links
        |----------------------------------------------------------------------
        |
        | A consistent shape so the footer, the about page, and the share bar
        | can all iterate the same structure. A null `url` means the channel is
        | not published: it is filtered out of every renderer rather than
        | rendered as a dead link.
        |
        | `handle` is used for share intents that accept an account mention
        | (currently X/Twitter's `via` parameter).
        |
        */

        'links' => [
            'email' => [
                'label' => 'Email',
                'url' => env('PORTFOLIO_AUTHOR_EMAIL', 'mailto:hello@example.com'),
                'handle' => null,
            ],
            'github' => [
                'label' => 'GitHub',
                'url' => env('PORTFOLIO_AUTHOR_GITHUB', 'https://github.com/example'),
                'handle' => env('PORTFOLIO_AUTHOR_GITHUB_HANDLE', 'example'),
            ],
            'linkedin' => [
                'label' => 'LinkedIn',
                'url' => env('PORTFOLIO_AUTHOR_LINKEDIN', 'https://linkedin.com/in/example'),
                'handle' => null,
            ],
            'x' => [
                'label' => 'X',
                'url' => env('PORTFOLIO_AUTHOR_X', 'https://x.com/example'),
                'handle' => env('PORTFOLIO_AUTHOR_X_HANDLE', '@example'),
            ],
            'rss' => [
                'label' => 'RSS',
                'url' => '/feed.xml',
                'handle' => null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Sharing
    |--------------------------------------------------------------------------
    |
    | The share targets rendered in the post share bar. Order here is the order
    | on screen. Each target declares its own intent URL template; `{url}` and
    | `{title}` are substituted client-side so the canonical URL is always the
    | one currently being viewed.
    |
    | Targets are grouped so the UI can show the two or three that matter most
    | and tuck the rest behind a "more" toggle.
    |
    */

    'sharing' => [

        /*
        | Rendered inline. These are the channels this audience actually uses.
        */
        'primary' => [
            [
                'key' => 'x',
                'label' => 'X',
                'icon' => 'x',
                'intent' => 'https://x.com/intent/post?url={url}&text={title}{via}',
                'share_text' => '{title}',
            ],
            [
                'key' => 'linkedin',
                'label' => 'LinkedIn',
                'icon' => 'linkedin',
                'intent' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}',
            ],
            [
                'key' => 'hackernews',
                'label' => 'Hacker News',
                'icon' => 'hackernews',
                'intent' => 'https://news.ycombinator.com/submitlink?u={url}&t={title}',
            ],
        ],

        /*
        | Behind the "more" disclosure.
        */
        'secondary' => [
            [
                'key' => 'reddit',
                'label' => 'Reddit',
                'icon' => 'reddit',
                'intent' => 'https://www.reddit.com/submit?url={url}&title={title}',
            ],
            [
                'key' => 'facebook',
                'label' => 'Facebook',
                'icon' => 'facebook',
                'intent' => 'https://www.facebook.com/sharer/sharer.php?u={url}',
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'icon' => 'envelope',
                'intent' => 'mailto:?subject={title}&body={url}',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Blog
    |--------------------------------------------------------------------------
    */

    'blog' => [

        /*
        | Posts per page on the public index.
        */
        'per_page' => 9,

        /*
        | Posts per page in the author dashboard.
        */
        'dashboard_per_page' => 15,

        /*
        | Comments per page in the moderation queue.
        */
        'comments_per_page' => 20,

        /*
        | Number of related posts appended to a post.
        */
        'related_limit' => 3,

        /*
        | Posts shown in the "latest" strip on the landing page.
        */
        'latest_limit' => 6,

        /*
        | Posts shown in the "featured" strip on the landing page.
        */
        'featured_limit' => 3,

        /*
        | Minimum and maximum comment body length, in characters.
        */
        'comment_min_length' => 3,
        'comment_max_length' => 2000,

        /*
        | Average adult reading speed, in words per minute.
        */
        'words_per_minute' => 200,
    ],

];
