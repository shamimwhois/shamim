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

        'role' => env('PORTFOLIO_AUTHOR_ROLE', 'Senior Software Engineer'),

        'tagline' => 'I design and build backends that stay fast, understandable, and cheap to change — and I write about how.',

        'location' => env('PORTFOLIO_AUTHOR_LOCATION', 'Dhaka, Bangladesh'),

        'availability' => env('PORTFOLIO_AUTHOR_AVAILABILITY', 'Open to senior platform and backend roles'),

        'years_of_experience' => (int) env('PORTFOLIO_AUTHOR_YEARS', 15),

        'bio' => 'Senior engineer specialising in Laravel, distributed systems, and developer tooling. I care about the boring parts: migrations that are reversible, queries that scale, and interfaces a new teammate can understand on their first day.',

        'long_bio' => 'I have spent the last nine years building and scaling web platforms, mostly in the Laravel and PHP ecosystem, with detours into TypeScript, Go, and whatever the problem actually needed. Most of my work has been on the unglamorous middle layer — the part that sits between a product idea and a database that has to hold up under load for the next three years.

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
                'role' => 'Lead engineer',
                'summary' => 'Double-entry accounting engine handling multi-currency settlement for a payments platform.',
                'outcome' => 'Cut month-end reconciliation from 6 hours to 11 minutes by replacing a cron-driven batch with an event-sourced projection built on database-backed queues.',
                'stack' => ['Laravel', 'PostgreSQL', 'Redis', 'Horizon'],
                'url' => 'https://github.com/example/atlas-ledger',
            ],
            [
                'name' => 'Meridian API',
                'role' => 'Architect',
                'summary' => 'Public REST API serving 40M requests a month across three product teams.',
                'outcome' => 'Reduced p95 latency from 840ms to 190ms and eliminated a recurring incident class by introducing a query-budget layer that fails builds, not requests.',
                'stack' => ['Laravel', 'MySQL', 'OpenTelemetry', 'Kubernetes'],
                'url' => 'https://github.com/example/meridian-api',
            ],
            [
                'name' => 'Forge CLI',
                'role' => 'Author',
                'summary' => 'Internal developer CLI that scaffolds services with migrations, CI, and observability pre-wired.',
                'outcome' => 'Brought new-service bootstrap time from two weeks of copy-paste to under an hour, and made the platform team\'s conventions the path of least resistance.',
                'stack' => ['PHP', 'Symfony Console', 'Docker', 'GitHub Actions'],
                'url' => 'https://github.com/example/forge-cli',
            ],
            [
                'name' => 'QueueLens',
                'role' => 'Maintainer',
                'summary' => 'Open-source metrics exporter that turns Horizon queue telemetry into Prometheus dashboards.',
                'outcome' => 'Gave platform teams a queue-depth SLO out of the box; adopted by four internal teams in its first quarter and now shipped with the default platform image.',
                'stack' => ['PHP', 'Prometheus', 'Redis Streams', 'Grafana'],
                'url' => 'https://github.com/example/queuelens',
            ],
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
                        'title' => 'Senior Software Engineer',
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
