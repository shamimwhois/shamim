<?php

namespace Database\Seeders;

use App\Enums\ProductLicense;
use App\Enums\ProductStatus;
use App\Enums\ReviewStatus;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductVersion;
use App\Models\MarketplaceReview;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a browsable marketplace catalogue.
 *
 * The corpus is deliberately uneven: most listings are published, a few are
 * waiting on review, one is a private draft and one is archived. That spread is
 * what makes the admin console demonstrate a real moderation queue instead of a
 * table where every row has the same status.
 *
 * Ratings are not seeded as a number on the product — they are the sum of the
 * approved reviews attached below, so a listing with no reviews has no rating.
 */
class MarketplaceProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = $this->accounts();

        foreach ($this->products() as $definition) {
            $category = MarketplaceCategory::query()
                ->where('slug', $definition['category'])
                ->first();

            $currentVersion = collect($definition['versions'])->firstWhere('current', true)
                ?? $definition['versions'][0];

            $product = MarketplaceProduct::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'marketplace_category_id' => $category?->id,
                    'user_id' => $accounts[$definition['creator']]->id,
                    'name' => $definition['name'],
                    'tagline' => $definition['tagline'],
                    'description' => $definition['description'],
                    'price_cents' => $definition['price_cents'],
                    'currency' => $definition['currency'] ?? 'USD',
                    'license' => $definition['license'],
                    'version' => $currentVersion['version'],
                    'framework' => $definition['framework'],
                    'language' => $definition['language'],
                    'install_command' => $definition['install_command'],
                    'demo_url' => $definition['demo_url'] ?? null,
                    'docs_url' => $definition['docs_url'] ?? null,
                    'repository_url' => $definition['repository_url'] ?? null,
                    'tags' => $definition['tags'],
                    'status' => $definition['status'],
                    'is_featured' => $definition['featured'] ?? false,
                    'download_count' => $definition['downloads'] ?? 0,
                    'published_at' => $definition['status'] === ProductStatus::Published
                        ? now()->subDays($definition['age_days'])
                        : null,
                    'last_released_at' => now()->subDays($currentVersion['days']),
                ],
            );

            $this->seedVersions($product, $definition['versions']);
            $this->seedReviews($product, $definition['reviews'] ?? [], $accounts);
        }
    }

    /**
     * Attach the release history to a product.
     *
     * @param  array<int, array{version: string, notes: string, days: int, current?: bool}>  $versions
     */
    protected function seedVersions(MarketplaceProduct $product, array $versions): void
    {
        foreach ($versions as $release) {
            MarketplaceProductVersion::query()->updateOrCreate(
                [
                    'marketplace_product_id' => $product->id,
                    'version' => $release['version'],
                ],
                [
                    'release_notes' => $release['notes'],
                    'is_current' => $release['current'] ?? false,
                    'released_at' => now()->subDays($release['days']),
                ],
            );
        }
    }

    /**
     * Attach reviews to a product, skipping any the account already wrote.
     *
     * @param  array<int, array{by: string, rating: int, title: string, body: string, status: ReviewStatus}>  $reviews
     * @param  array<string, User>  $accounts
     */
    protected function seedReviews(MarketplaceProduct $product, array $reviews, array $accounts): void
    {
        foreach ($reviews as $review) {
            $author = $accounts[$review['by']];

            // One review per account per product is a database constraint, so a
            // re-seed must update the existing row rather than insert a second.
            MarketplaceReview::query()->updateOrCreate(
                [
                    'marketplace_product_id' => $product->id,
                    'user_id' => $author->id,
                ],
                [
                    'rating' => $review['rating'],
                    'title' => $review['title'],
                    'body' => $review['body'],
                    'status' => $review['status'],
                ],
            );
        }
    }

    /**
     * Resolve the seeded accounts by the key the corpus refers to them by.
     *
     * @return array<string, User>
     */
    protected function accounts(): array
    {
        $fallback = User::query()->orderBy('id')->firstOrFail();

        return [
            'admin' => User::query()->where('email', 'test@example.com')->first() ?? $fallback,
            'seller' => User::query()->where('email', 'seller@example.com')->first() ?? $fallback,
            'user' => User::query()->where('email', 'user@example.com')->first() ?? $fallback,
        ];
    }

    /**
     * The seeded product corpus.
     *
     * @return array<int, array{
     *     name: string,
     *     slug: string,
     *     category: string,
     *     creator: string,
     *     tagline: string,
     *     description: string,
     *     price_cents: int,
     *     currency?: string,
     *     license: ProductLicense,
     *     framework: string,
     *     language: string,
     *     install_command: string,
     *     demo_url?: string,
     *     docs_url?: string,
     *     repository_url?: string,
     *     tags: array<int, string>,
     *     status: ProductStatus,
     *     featured?: bool,
     *     downloads?: int,
     *     age_days: int,
     *     versions: array<int, array{version: string, notes: string, days: int, current?: bool}>,
     *     reviews?: array<int, array{by: string, rating: int, title: string, body: string, status: ReviewStatus}>
     * }>
     */
    protected function products(): array
    {
        return [
            [
                'name' => 'Activity Ledger',
                'slug' => 'activity-ledger',
                'category' => 'laravel-packages',
                'creator' => 'seller',
                'tagline' => 'An append-only audit trail for every Eloquent model you care about.',
                'description' => <<<'MD'
                Activity Ledger records what changed, who changed it and when — without a schema migration per model. Attach the trait, declare the attributes worth watching, and the package writes a normalised ledger row on create, update and delete.

                The ledger is append-only by design. Rows are never updated in place, which is what makes the history trustworthy in an audit: the only way to correct an entry is to write a compensating one.

                - Per-model attribute allow-lists
                - Actor resolution from the authenticated request
                - Request ID correlation across queued work
                - Query scopes for "everything that happened to this record"
                MD,
                'price_cents' => 0,
                'license' => ProductLicense::Mit,
                'framework' => 'Laravel 12 / 13',
                'language' => 'PHP',
                'install_command' => 'composer require devfolio/activity-ledger',
                'docs_url' => 'https://example.com/docs/activity-ledger',
                'repository_url' => 'https://github.com/devfolio/activity-ledger',
                'tags' => ['audit', 'eloquent', 'compliance'],
                'status' => ProductStatus::Published,
                'featured' => true,
                'downloads' => 12400,
                'age_days' => 240,
                'versions' => [
                    ['version' => '1.3.0', 'notes' => "Adds request-ID correlation for queued jobs.\nFixes an actor-resolution bug when the guard changes mid-request.", 'days' => 12, 'current' => true],
                    ['version' => '1.2.0', 'notes' => "Adds a `ledger()` query scope.\nDrops support for Laravel 11.", 'days' => 68],
                    ['version' => '1.0.0', 'notes' => 'First stable release.', 'days' => 210],
                ],
                'reviews' => [
                    [
                        'by' => 'user',
                        'rating' => 5,
                        'title' => 'Replaced a hand-rolled audit table',
                        'body' => 'We had three years of ad-hoc audit tables. This replaced all of them in an afternoon, and the compensating-entry rule forced us to think properly about corrections.',
                        'status' => ReviewStatus::Approved,
                    ],
                    [
                        'by' => 'admin',
                        'rating' => 4,
                        'title' => 'Solid, small surface area',
                        'body' => 'Does one thing and does it well. The docs could be clearer about pruning old rows, which we had to work out ourselves.',
                        'status' => ReviewStatus::Approved,
                    ],
                ],
            ],
            [
                'name' => 'Queue Lens',
                'slug' => 'queue-lens',
                'category' => 'developer-tools',
                'creator' => 'seller',
                'tagline' => 'See what your queue is actually doing, without a second database.',
                'description' => <<<'MD'
                Queue Lens is a read-only inspector for Laravel queues. It reads the queue connection you already have and presents throughput, wait time, failure clusters and retry storms in one screen.

                It never writes to your queue and never dispatches a job, so it is safe to mount on a production dashboard. The failure view groups exceptions by class and message so a hundred failures become one row with a count.

                - Per-queue throughput and p95 wait time
                - Failure clustering by exception signature
                - Slow-job ranking with payload excerpts
                - Optional Slack alert on failure rate
                MD,
                'price_cents' => 2900,
                'license' => ProductLicense::Commercial,
                'framework' => 'Laravel 12 / 13',
                'language' => 'PHP',
                'install_command' => 'composer require devfolio/queue-lens',
                'demo_url' => 'https://example.com/demo/queue-lens',
                'docs_url' => 'https://example.com/docs/queue-lens',
                'tags' => ['queues', 'observability', 'debugging'],
                'status' => ProductStatus::Published,
                'featured' => true,
                'downloads' => 3100,
                'age_days' => 150,
                'versions' => [
                    ['version' => '2.0.0', 'notes' => "Rewritten dashboard on Livewire 4.\nAdds failure clustering.", 'days' => 21, 'current' => true],
                    ['version' => '1.4.2', 'notes' => 'Fixes a memory leak on long polling intervals.', 'days' => 96],
                ],
                'reviews' => [
                    [
                        'by' => 'user',
                        'rating' => 5,
                        'title' => 'Found a retry storm in ten minutes',
                        'body' => 'Installed it during an incident and immediately saw one job retrying 4,000 times. Paid for itself before the install command finished.',
                        'status' => ReviewStatus::Approved,
                    ],
                ],
            ],
            [
                'name' => 'Flux Dashboard Blocks',
                'slug' => 'flux-dashboard-blocks',
                'category' => 'admin-templates',
                'creator' => 'admin',
                'tagline' => 'Thirty production-ready dashboard blocks built on Flux and Tailwind.',
                'description' => <<<'MD'
                A block library rather than a theme: each block is a self-contained Blade component you copy into your project, with no runtime dependency on this package.

                Blocks are designed light and dark together, and every interactive state — focus, hover, disabled, empty — is included. The point is that you spend your time on the product, not on rebuilding a table for the fourth time.

                - Metric tiles, chart cards, data tables and empty states
                - Filter toolbars with URL-persisted state
                - Settings and billing page scaffolds
                - Both themes designed, not inverted
                MD,
                'price_cents' => 5900,
                'license' => ProductLicense::Commercial,
                'framework' => 'Livewire 4 / Flux 2',
                'language' => 'Blade / PHP',
                'install_command' => 'composer require devfolio/flux-dashboard-blocks',
                'demo_url' => 'https://example.com/demo/flux-blocks',
                'docs_url' => 'https://example.com/docs/flux-blocks',
                'tags' => ['flux', 'tailwind', 'dashboard'],
                'status' => ProductStatus::Published,
                'featured' => true,
                'downloads' => 5400,
                'age_days' => 320,
                'versions' => [
                    ['version' => '3.1.0', 'notes' => "Adds six reporting blocks.\nFixes table overflow on narrow viewports.", 'days' => 9, 'current' => true],
                    ['version' => '3.0.0', 'notes' => 'Rebuilt against Flux 2. Breaking: component names are namespaced.', 'days' => 74],
                    ['version' => '2.4.1', 'notes' => 'Documentation corrections only.', 'days' => 180],
                ],
                'reviews' => [
                    [
                        'by' => 'user',
                        'rating' => 4,
                        'title' => 'Good starting point, opinionated in the right places',
                        'body' => 'The blocks are clean and the dark theme is genuinely designed rather than inverted. I rewrote the table block for our data shape, which was expected.',
                        'status' => ReviewStatus::Approved,
                    ],
                    [
                        'by' => 'seller',
                        'rating' => 5,
                        'title' => 'Saved us two weeks',
                        'body' => 'We shipped an internal admin in nine days. The filter toolbar alone was worth the price.',
                        'status' => ReviewStatus::Approved,
                    ],
                ],
            ],
            [
                'name' => 'Tenant Starter Kit',
                'slug' => 'tenant-starter-kit',
                'category' => 'starter-kits',
                'creator' => 'seller',
                'tagline' => 'Multi-tenant Laravel from the first migration, with tenant-aware queues and storage.',
                'description' => <<<'MD'
                A starter kit for products where tenancy is structural rather than bolted on. Tenant resolution happens in middleware, the database connection is switched per request, and queued jobs carry their tenant context.

                Storage paths, cache keys and scheduled commands are all tenant-aware out of the box. The kit includes a working invitation flow and a tenant switcher for accounts that belong to more than one organisation.

                - Single-database and database-per-tenant modes
                - Tenant-aware queues, cache and filesystem
                - Invitations and role assignment per tenant
                - Test helpers that boot a tenant in one line
                MD,
                'price_cents' => 8900,
                'license' => ProductLicense::Commercial,
                'framework' => 'Laravel 13',
                'language' => 'PHP',
                'install_command' => 'composer create-project devfolio/tenant-starter',
                'docs_url' => 'https://example.com/docs/tenant-starter',
                'tags' => ['multitenancy', 'saas', 'starter'],
                'status' => ProductStatus::Published,
                'downloads' => 890,
                'age_days' => 95,
                'versions' => [
                    ['version' => '1.0.0', 'notes' => 'First stable release.', 'days' => 6, 'current' => true],
                ],
                'reviews' => [
                    [
                        'by' => 'user',
                        'rating' => 4,
                        'title' => 'Tenancy where it belongs',
                        'body' => 'The queue and filesystem scoping is the part every other starter kit forgets. Waiting on the database-per-tenant docs before we commit.',
                        'status' => ReviewStatus::Pending,
                    ],
                ],
            ],
            [
                'name' => 'Inertia Form Kit',
                'slug' => 'inertia-form-kit',
                'category' => 'ui-components',
                'creator' => 'seller',
                'tagline' => 'Accessible form primitives that speak Inertia and Laravel validation.',
                'description' => <<<'MD'
                Form components that already know about validation errors, dirty state and submission progress. Drop them in and the wiring between your controller's `validate()` call and the field's error message is done.

                Every control is keyboard reachable and announces its error to a screen reader. The kit ships with text, select, checkbox, radio group, file upload and a repeatable field group.

                - Error binding from Inertia page props
                - Dirty-state tracking and unsaved-change guards
                - Repeatable field groups with stable keys
                - React and Vue entry points
                MD,
                'price_cents' => 4900,
                'license' => ProductLicense::Commercial,
                'framework' => 'Inertia 2',
                'language' => 'TypeScript',
                'install_command' => 'npm install @devfolio/inertia-form-kit',
                'demo_url' => 'https://example.com/demo/form-kit',
                'tags' => ['forms', 'accessibility', 'inertia'],
                'status' => ProductStatus::Published,
                'downloads' => 2200,
                'age_days' => 210,
                'versions' => [
                    ['version' => '2.2.0', 'notes' => "Adds the repeatable field group.\nImproves screen-reader error announcements.", 'days' => 33, 'current' => true],
                    ['version' => '2.0.0', 'notes' => 'Inertia 2 support. Vue 3 entry point rewritten.', 'days' => 140],
                ],
                'reviews' => [
                    [
                        'by' => 'user',
                        'rating' => 4,
                        'title' => 'The dirty-state guard is the standout',
                        'body' => 'We stopped losing half-filled forms the week we adopted this. Documentation for the file upload component is thin.',
                        'status' => ReviewStatus::Approved,
                    ],
                ],
            ],
            [
                'name' => 'Feature Flags for Laravel',
                'slug' => 'laravel-feature-flags',
                'category' => 'laravel-plugins',
                'creator' => 'seller',
                'tagline' => 'Flags with a database backing, percentage rollouts and a Blade directive.',
                'description' => <<<'MD'
                A small feature-flag layer that stores its state in your database and caches it. Flags can be on, off, or rolled out to a percentage of accounts with a stable hash, so a user does not flicker between variants.

                Targeting rules are declarative and testable. The package ships a console command for listing and toggling flags, which is what you actually reach for during an incident.

                - Boolean, percentage and account-targeted flags
                - Stable hashing per account
                - `@feature` Blade directive and a `Feature` facade
                - Artisan commands for listing and toggling
                MD,
                'price_cents' => 4500,
                'license' => ProductLicense::Commercial,
                'framework' => 'Laravel 12 / 13',
                'language' => 'PHP',
                'install_command' => 'composer require devfolio/laravel-feature-flags',
                'docs_url' => 'https://example.com/docs/feature-flags',
                'tags' => ['feature-flags', 'rollout', 'release'],
                'status' => ProductStatus::Published,
                'downloads' => 1800,
                'age_days' => 170,
                'versions' => [
                    ['version' => '1.5.0', 'notes' => "Adds account-targeted rules.\nCaches flag lookups for the request lifetime.", 'days' => 17, 'current' => true],
                    ['version' => '1.0.0', 'notes' => 'First stable release.', 'days' => 165],
                ],
                'reviews' => [
                    [
                        'by' => 'user',
                        'rating' => 5,
                        'title' => 'Exactly the right size',
                        'body' => 'No dashboard, no vendor dependency, no Kubernetes. A table, a cache and a Blade directive. That is all we wanted.',
                        'status' => ReviewStatus::Approved,
                    ],
                    [
                        'by' => 'admin',
                        'rating' => 4,
                        'title' => 'Stable hashing is the important part',
                        'body' => 'Percentage rollouts that stay consistent per account. We had written this badly twice before.',
                        'status' => ReviewStatus::Approved,
                    ],
                ],
            ],
            [
                'name' => 'Prompt Vault',
                'slug' => 'prompt-vault',
                'category' => 'ai-tools',
                'creator' => 'admin',
                'tagline' => 'A versioned prompt library with evaluations attached to each revision.',
                'description' => <<<'MD'
                Prompts are code: they change, they regress, and nobody remembers why a line was added. Prompt Vault keeps every revision, the evaluation run that justified it, and the model it was tested against.

                The CLI can replay a prompt against a fixture set and diff the output against the previous revision, which turns "this feels worse" into a reviewable change.

                - Versioned prompt files in the repository
                - Fixture-based evaluation runs
                - Output diffing between revisions
                - Model and parameter recorded per run
                MD,
                'price_cents' => 0,
                'license' => ProductLicense::Mit,
                'framework' => 'Framework-agnostic',
                'language' => 'PHP / TypeScript',
                'install_command' => 'composer require devfolio/prompt-vault',
                'repository_url' => 'https://github.com/devfolio/prompt-vault',
                'tags' => ['prompts', 'evaluation', 'llm'],
                'status' => ProductStatus::Published,
                'downloads' => 7600,
                'age_days' => 130,
                'versions' => [
                    ['version' => '0.9.0', 'notes' => "Adds output diffing.\nFixtures can now be loaded from a directory.", 'days' => 28, 'current' => true],
                ],
                'reviews' => [
                    [
                        'by' => 'user',
                        'rating' => 4,
                        'title' => 'Useful even without the evaluation harness',
                        'body' => 'We only use the versioning and diff, and that alone justified adopting it. The evaluation runner assumes more discipline than we have.',
                        'status' => ReviewStatus::Approved,
                    ],
                ],
            ],
            [
                'name' => 'Schema Sketch',
                'slug' => 'schema-sketch',
                'category' => 'developer-tools',
                'creator' => 'seller',
                'tagline' => 'Turn your migrations into a readable entity diagram.',
                'description' => <<<'MD'
                Schema Sketch reads your migration files and prints a diagram of tables, columns, indexes and foreign keys. It works from the migrations rather than a live connection, so it runs in CI without a database.

                The output is deterministic, which means a pull request that changes the schema also shows the schema change.

                - Tables, columns, indexes and foreign keys
                - SVG and Mermaid output
                - Deterministic ordering for clean diffs
                - Runs without a database connection
                MD,
                'price_cents' => 2500,
                'license' => ProductLicense::Commercial,
                'framework' => 'Laravel 12 / 13',
                'language' => 'PHP',
                'install_command' => 'composer require devfolio/schema-sketch',
                'docs_url' => 'https://example.com/docs/schema-sketch',
                'tags' => ['schema', 'documentation', 'diagrams'],
                'status' => ProductStatus::Published,
                'downloads' => 640,
                'age_days' => 260,
                'versions' => [
                    ['version' => '1.2.0', 'notes' => 'Adds Mermaid output.', 'days' => 44, 'current' => true],
                    ['version' => '1.0.0', 'notes' => 'First stable release.', 'days' => 250],
                ],
                'reviews' => [
                    [
                        'by' => 'admin',
                        'rating' => 5,
                        'title' => 'Deterministic output is the point',
                        'body' => 'We generate the diagram in CI and commit it, so every schema change is visible in the pull request. Exactly what a review needs.',
                        'status' => ReviewStatus::Pending,
                    ],
                ],
            ],
            [
                'name' => 'Audit Trail',
                'slug' => 'laravel-audit-trail',
                'category' => 'laravel-packages',
                'creator' => 'seller',
                'tagline' => 'Field-level diffs for regulated applications, with retention built in.',
                'description' => <<<'MD'
                Audit Trail records a before/after diff per changed field rather than a serialised model snapshot. That makes the ledger readable years later and keeps the table small.

                Retention is configurable per model, and pruning writes a summary row so the history still shows that something was removed.

                - Field-level before/after diffs
                - Per-model retention windows
                - Pruning that leaves a summary
                - Export to CSV for compliance reviews
                MD,
                'price_cents' => 3500,
                'license' => ProductLicense::Commercial,
                'framework' => 'Laravel 12 / 13',
                'language' => 'PHP',
                'install_command' => 'composer require devfolio/laravel-audit-trail',
                'docs_url' => 'https://example.com/docs/audit-trail',
                'tags' => ['audit', 'compliance', 'retention'],
                'status' => ProductStatus::Published,
                'downloads' => 1100,
                'age_days' => 300,
                'versions' => [
                    ['version' => '1.1.0', 'notes' => 'Adds CSV export.', 'days' => 58, 'current' => true],
                    ['version' => '1.0.0', 'notes' => 'First stable release.', 'days' => 295],
                ],
                'reviews' => [
                    [
                        'by' => 'user',
                        'rating' => 2,
                        'title' => 'Not what I expected',
                        'body' => 'This is an advert for a consultancy rather than a review. The package itself is fine but the pricing page was misleading.',
                        'status' => ReviewStatus::Rejected,
                    ],
                ],
            ],
            [
                'name' => 'Blade Icon Forge',
                'slug' => 'blade-icon-forge',
                'category' => 'developer-tools',
                'creator' => 'seller',
                'tagline' => 'Compile an icon set into Blade components with no runtime cost.',
                'description' => <<<'MD'
                Point Forge at a directory of SVGs and it generates one Blade component per icon, with a shared attribute bag and no runtime file reads. The icons become part of your build, not a dependency.

                It strips editor metadata, normalises `viewBox` values and can inline a sprite when a page uses many icons.

                - One component per icon
                - Sprite mode for icon-heavy pages
                - SVGO pipeline with sensible defaults
                - Regeneration command that reports what changed
                MD,
                'price_cents' => 0,
                'license' => ProductLicense::Mit,
                'framework' => 'Laravel 12 / 13',
                'language' => 'PHP',
                'install_command' => 'composer require devfolio/blade-icon-forge',
                'repository_url' => 'https://github.com/devfolio/blade-icon-forge',
                'tags' => ['icons', 'blade', 'build'],
                'status' => ProductStatus::Published,
                'downloads' => 4300,
                'age_days' => 60,
                'versions' => [
                    ['version' => '1.4.0', 'notes' => "Adds sprite mode.\nSkips non-SVG files instead of failing the build.", 'days' => 11, 'current' => true],
                ],
            ],
            [
                'name' => 'Edge Component Library',
                'slug' => 'edge-component-library',
                'category' => 'ui-components',
                'creator' => 'admin',
                'tagline' => 'Sixty accessible components for server-rendered applications.',
                'description' => <<<'MD'
                Components for applications that render on the server and enhance on the client. Everything works without JavaScript; the enhancement layer adds the behaviours that genuinely need it, like comboboxes and dialogs.

                Each component documents its keyboard contract and its ARIA wiring, because "accessible" is a claim that should be checkable.

                - Sixty components, progressive enhancement first
                - Documented keyboard and ARIA contracts
                - Both themes designed
                - Copy-paste source, no runtime dependency
                MD,
                'price_cents' => 3900,
                'license' => ProductLicense::Commercial,
                'framework' => 'Alpine 3 / Blade',
                'language' => 'Blade / JavaScript',
                'install_command' => 'npm install @devfolio/edge-components',
                'demo_url' => 'https://example.com/demo/edge-components',
                'tags' => ['components', 'accessibility', 'alpine'],
                'status' => ProductStatus::Published,
                'downloads' => 520,
                'age_days' => 40,
                'versions' => [
                    ['version' => '1.0.2', 'notes' => "Fixes focus trapping in the dialog.\nDocuments the combobox keyboard contract.", 'days' => 5, 'current' => true],
                ],
                'reviews' => [
                    [
                        'by' => 'seller',
                        'rating' => 4,
                        'title' => 'Progressive enhancement done properly',
                        'body' => 'The combobox works without JavaScript and improves when it loads. That is rarer than it should be.',
                        'status' => ReviewStatus::Pending,
                    ],
                ],
            ],
            [
                'name' => 'Vite Asset Auditor',
                'slug' => 'vite-asset-auditor',
                'category' => 'developer-tools',
                'creator' => 'seller',
                'tagline' => 'Find the import that doubled your bundle.',
                'description' => <<<'MD'
                A build-time report that attributes bundle weight to the import that caused it. It reads the Vite manifest and the module graph, then ranks the changes between two builds.

                Run it in CI and it comments on the pull request with the delta, which is usually enough to stop a stray `lodash` import before review.

                - Per-import weight attribution
                - Build-to-build delta ranking
                - CI summary output
                - No upload of source code
                MD,
                'price_cents' => 0,
                'license' => ProductLicense::Mit,
                'framework' => 'Vite 6 / 7',
                'language' => 'TypeScript',
                'install_command' => 'npm install -D @devfolio/vite-asset-auditor',
                'repository_url' => 'https://github.com/devfolio/vite-asset-auditor',
                'tags' => ['vite', 'performance', 'ci'],
                'status' => ProductStatus::Published,
                'downloads' => 900,
                'age_days' => 18,
                'versions' => [
                    ['version' => '0.6.0', 'notes' => 'Adds CI summary output.', 'days' => 3, 'current' => true],
                ],
            ],
            [
                'name' => 'SaaS Billing Starter',
                'slug' => 'saas-billing-starter',
                'category' => 'starter-kits',
                'creator' => 'seller',
                'tagline' => 'Subscriptions, seats and dunning wired end to end.',
                'description' => <<<'MD'
                A billing scaffold for subscription products: plans, seats, trials, proration and the dunning emails nobody wants to write twice. It ships with a fake payment provider so the whole flow is testable without network access.

                The provider interface is the seam — swap the fake for a real gateway without touching the domain code.

                - Plans, seats and trials
                - Proration on plan change
                - Dunning sequence with retry schedule
                - Fake provider for tests
                MD,
                'price_cents' => 12900,
                'license' => ProductLicense::Extended,
                'framework' => 'Laravel 13',
                'language' => 'PHP',
                'install_command' => 'composer create-project devfolio/saas-billing-starter',
                'docs_url' => 'https://example.com/docs/saas-billing',
                'tags' => ['billing', 'subscriptions', 'saas'],
                'status' => ProductStatus::PendingReview,
                'downloads' => 0,
                'age_days' => 2,
                'versions' => [
                    ['version' => '0.9.0', 'notes' => 'Release candidate for review.', 'days' => 2, 'current' => true],
                ],
            ],
            [
                'name' => 'Livewire Table Kit',
                'slug' => 'livewire-table-kit',
                'category' => 'ui-components',
                'creator' => 'seller',
                'tagline' => 'Sortable, filterable, paginated tables without writing the same component twice.',
                'description' => <<<'MD'
                A table component that owns the parts every dashboard table needs: sorting, filtering, pagination and an empty state. Columns are declared, not hand-written, so a new table is a dozen lines.

                Filter state lives in the URL, which means a filtered view is shareable and the back button behaves.

                - Declarative columns and filters
                - URL-persisted table state
                - Row actions with confirmation
                - Bulk selection with a bounded action bar
                MD,
                'price_cents' => 5500,
                'license' => ProductLicense::Commercial,
                'framework' => 'Livewire 4',
                'language' => 'PHP',
                'install_command' => 'composer require devfolio/livewire-table-kit',
                'tags' => ['livewire', 'tables', 'dashboard'],
                'status' => ProductStatus::PendingReview,
                'downloads' => 0,
                'age_days' => 4,
                'versions' => [
                    ['version' => '1.0.0', 'notes' => 'Submitted for review.', 'days' => 4, 'current' => true],
                ],
            ],
            [
                'name' => 'PHP Enum Toolkit',
                'slug' => 'php-enum-toolkit',
                'category' => 'laravel-packages',
                'creator' => 'admin',
                'tagline' => 'Labels, colours and option lists for backed enums.',
                'description' => <<<'MD'
                The small set of helpers every backed enum ends up growing: a label, a badge colour, an option list for a select, and a validation rule. One interface, one trait, no magic.

                It exists so that behaviour lives on the enum instead of in a view that has to `match` over every case.

                - `HasLabel`, `HasColor` and `HasOptions` interfaces
                - A `Enum` validation rule
                - No reflection at runtime
                - Framework-agnostic
                MD,
                'price_cents' => 0,
                'license' => ProductLicense::Mit,
                'framework' => 'PHP 8.3+',
                'language' => 'PHP',
                'install_command' => 'composer require devfolio/php-enum-toolkit',
                'repository_url' => 'https://github.com/devfolio/php-enum-toolkit',
                'tags' => ['enums', 'php', 'utilities'],
                'status' => ProductStatus::PendingReview,
                'downloads' => 0,
                'age_days' => 1,
                'versions' => [
                    ['version' => '1.0.0', 'notes' => 'Submitted for review.', 'days' => 1, 'current' => true],
                ],
            ],
            [
                'name' => 'Portfolio Theme Pack',
                'slug' => 'portfolio-theme-pack',
                'category' => 'admin-templates',
                'creator' => 'seller',
                'tagline' => 'A restrained portfolio theme with a writing-first layout.',
                'description' => <<<'MD'
                A theme for developers who publish more than they ship marketing pages. The layout is built around long-form reading: a measured column, a real table of contents, and typography that survives a code block.

                Still in progress — the case-study template is unfinished and the theme is not submitted for review yet.

                - Reading-first layout
                - Light and dark designed together
                - Case-study template (in progress)
                MD,
                'price_cents' => 1900,
                'license' => ProductLicense::Commercial,
                'framework' => 'Blade / Tailwind 4',
                'language' => 'Blade',
                'install_command' => 'composer require devfolio/portfolio-theme-pack',
                'tags' => ['theme', 'portfolio', 'writing'],
                'status' => ProductStatus::Draft,
                'downloads' => 0,
                'age_days' => 6,
                'versions' => [
                    ['version' => '0.3.0', 'notes' => 'Work in progress.', 'days' => 6, 'current' => true],
                ],
            ],
            [
                'name' => 'Legacy Admin Theme',
                'slug' => 'legacy-admin-theme',
                'category' => 'admin-templates',
                'creator' => 'admin',
                'tagline' => 'The original Bootstrap admin theme, kept for existing licence holders.',
                'description' => <<<'MD'
                The first theme published here, withdrawn from the catalogue when the Flux-based blocks replaced it. It remains available to existing licence holders and receives security fixes only.

                It is listed as archived rather than deleted so that its release history and reviews stay readable.

                - Bootstrap 5 admin shell
                - Security fixes only
                - No new features
                MD,
                'price_cents' => 2900,
                'license' => ProductLicense::Commercial,
                'framework' => 'Bootstrap 5',
                'language' => 'Blade / JavaScript',
                'install_command' => 'composer require devfolio/legacy-admin-theme',
                'tags' => ['bootstrap', 'legacy', 'theme'],
                'status' => ProductStatus::Archived,
                'downloads' => 2100,
                'age_days' => 720,
                'versions' => [
                    ['version' => '4.8.0', 'notes' => 'Security fix for a reflected XSS in the search field.', 'days' => 120, 'current' => true],
                    ['version' => '4.7.0', 'notes' => 'Final feature release.', 'days' => 400],
                ],
                'reviews' => [
                    [
                        'by' => 'user',
                        'rating' => 3,
                        'title' => 'Fine for what it was',
                        'body' => 'It did the job in 2022. The Flux blocks are a better starting point now, and the maintainer says as much.',
                        'status' => ReviewStatus::Approved,
                    ],
                ],
            ],
        ];
    }
}
