<?php

namespace Database\Seeders;

use App\Actions\Posts\CreatePost;
use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a browsable blog.
 *
 * Posts are written through the same {@see CreatePost} action the editor uses,
 * so the seeded data exercises the real content pipeline — the derived
 * representations are produced by production code, not by a shortcut.
 */
class PostSeeder extends Seeder
{
    public function __construct(protected CreatePost $createPost) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $author = User::query()->orderBy('id')->firstOrFail();

        foreach ($this->posts() as $index => $definition) {
            $category = Category::query()->where('slug', $definition['category'])->first();

            $tagIds = Tag::query()
                ->whereIn('slug', $definition['tags'])
                ->pluck('id')
                ->all();

            $post = $this->createPost->handle($author, [
                'title' => $definition['title'],
                'slug' => $definition['slug'],
                'excerpt' => $definition['excerpt'],
                'body' => $definition['body'],
                'category_id' => $category?->id,
                'status' => $definition['status'],
                'is_featured' => $definition['featured'] ?? false,
            ], $tagIds);

            if ($definition['status'] === PostStatus::Published) {
                $post->forceFill([
                    'published_at' => now()->subDays($definition['age_days']),
                    'view_count' => $definition['views'] ?? 0,
                ])->save();
            } else {
                $post->forceFill([
                    'updated_at' => now()->subDays($definition['age_days']),
                ])->save();
            }
        }
    }

    /**
     * The seeded post corpus.
     *
     * @return array<int, array{
     *     title: string, slug: string, excerpt: string, body: string,
     *     category: string, tags: array<int, string>, status: PostStatus,
     *     featured?: bool, age_days: int, views?: int
     * }>
     */
    protected function posts(): array
    {
        return [
            [
                'title' => 'The N+1 Query You Ship Every Sprint',
                'slug' => 'the-n-plus-one-query-you-ship-every-sprint',
                'excerpt' => 'Nine out of ten performance regressions I have diagnosed in production were the same bug wearing different clothes: an unloaded relation inside a loop that nobody noticed because the staging database has 40 rows.',
                'category' => 'databases',
                'tags' => ['eloquent', 'performance', 'laravel', 'postgresql'],
                'status' => PostStatus::Published,
                'featured' => true,
                'age_days' => 4,
                'views' => 2841,
                'body' => <<<'MARKDOWN'
                The bug is almost never a slow query. It is a fast query executed four hundred times.

                ## The shape of the problem

                A blog index renders nine posts, each with a category and up to four tags. The naive implementation looks harmless:

                ```php
                $posts = Post::query()->published()->recent()->paginate(9);

                foreach ($posts as $post) {
                    echo $post->category->name;      // one query per post
                    foreach ($post->tags as $tag) {   // one query per post
                        echo $tag->name;
                    }
                }
                ```

                That is one query to fetch the page, then one per post for the category, then one per post for the tags. Twenty-eight queries for a nine-row page. On a staging database with forty posts it completes in 12ms and nobody looks twice.

                ## Why it survives code review

                Lazy loading is not a mistake anyone makes deliberately. It is a mistake the ORM actively encourages, because the syntax for the loaded and unloaded cases is identical. `$post->category` reads the same whether it costs zero queries or one, and review focuses on the algorithm rather than the access pattern.

                This is a property of the tool, not a failure of discipline. Design the system as if the developer will not notice — because they will not.

                ## Three defences, in order of reliability

                ### 1. Make the query count assertable in tests

                The most reliable defence is a test that fails when the query count changes:

                ```php
                test('the blog index does not issue one query per post', function () {
                    Post::factory()->count(9)->published()->create();

                    DB::enableQueryLog();

                    $this->get(route('blog.index'))->assertOk();

                    expect(DB::getQueryLog())->toHaveCount(3);
                });
                ```

                The exact number matters less than the fact that it is pinned. A change from three to twenty-eight fails the suite, and the failure message names the page.

                ### 2. Disable lazy loading outside production

                Laravel can be told to throw instead of silently querying:

                ```php
                // app/Providers/AppServiceProvider.php
                public function boot(): void
                {
                    Model::preventLazyLoading(! app()->isProduction());
                }
                ```

                This turns a silent performance defect into an exception with a stack trace pointing at the exact property access. The cost is that some legitimate lazy loads now throw and must be wrapped in `->loadMissing()`.

                That friction is the point. It forces an explicit decision about every relation access in a loop.

                ### 3. Push the shape into a query-budget layer

                For the highest-traffic endpoints, make the budget part of the contract:

                ```php
                $posts = Post::query()
                    ->published()
                    ->with(['category:id,name,slug', 'tags:id,name,slug'])
                    ->recent()
                    ->paginate(9);
                ```

                Selecting only the columns you render keeps the payload small, and the `with()` makes the eager load unconditional — it cannot be skipped by a later refactor that removes the loop.

                ## What I do differently now

                I stopped treating N+1 as a performance issue and started treating it as a **correctness** issue. A page that issues twenty-eight queries will behave differently on a database a hundred times larger, which means the staging environment is not testing the production behaviour. That is a correctness gap, not a tuning opportunity.

                The concrete change: every listing endpoint gets a query-count assertion on the day it is written, not on the day it becomes slow.
                MARKDOWN,
            ],
            [
                'title' => 'Stop Using Publish Timestamps as Ordering Keys',
                'slug' => 'stop-using-publish-timestamps-as-ordering-keys',
                'excerpt' => 'Sorting by published_at looks correct until two posts share a timestamp, and then pagination starts silently duplicating and dropping rows.',
                'category' => 'databases',
                'tags' => ['postgresql', 'performance', 'eloquent'],
                'status' => PostStatus::Published,
                'age_days' => 11,
                'views' => 1673,
                'body' => <<<'MARKDOWN'
                This one is subtle enough that it survives load testing, code review, and production for months before anyone notices.

                ## The symptom

                A reader reports a post they saw on page two is now missing. You check: the post is published, the filters are correct, and the row exists. Nothing is broken — the reader saw a phantom.

                ## The cause

                Pagination with an offset assumes a stable total order. Ordering by `published_at` alone does not provide one.

                ```sql
                SELECT * FROM posts
                WHERE status = 'published'
                ORDER BY published_at DESC
                LIMIT 9 OFFSET 9;
                ```

                If three posts share the same `published_at` — which happens constantly with scheduled publishing or a batch import — the database is free to return them in any order within the tie. Page one might show post A before post B; page two might show B before A and therefore also include A again.

                The reader did not misremember. The database genuinely returned the row twice.

                ## Why it is hard to catch

                Three properties conspire:

                1. **It requires a tie.** With distinct timestamps the order is stable and everything works.
                2. **It requires a page boundary.** A tie in the middle of a page is invisible.
                3. **It requires a second request.** A single page load is always self-consistent.

                So the bug needs a specific combination of data shape and user behaviour. Your test suite creates three posts with `fake()->dateTimeBetween()` and never hits it.

                ## The fix

                Make the sort key total by appending a tiebreaker:

                ```php
                #[Scope]
                protected function recent(Builder $query): void
                {
                    $query->orderByDesc('published_at')->orderByDesc('id');
                }
                ```

                ```sql
                SELECT * FROM posts
                WHERE status = 'published'
                ORDER BY published_at DESC, id DESC
                LIMIT 9 OFFSET 9;
                ```

                Now every row has a deterministic position. `id` is unique, so the ordering is total, and pagination is stable.

                ## Why not just use id

                Because the fallback has to agree with the primary key. If you order by `published_at DESC, id DESC`, a later post with a lower id still sorts first. If you ordered by `id DESC` alone, a backdated post — one you published today but dated last month — would appear at the top, which is wrong.

                The tiebreaker must be monotonically increasing in *insert* order, not in *publish* order, so that the immediately-next-inserted row wins a tie consistently.

                ## The test that catches it

                ```php
                test('pagination is stable when posts share a publish timestamp', function () {
                    $timestamp = now()->subDay();

                    Post::factory()->count(20)->published()->create([
                        'published_at' => $timestamp,
                    ]);

                    $pageOne = $this->get(route('blog.index'))->assertOk()
                        ->viewData('posts')->pluck('id');

                    $pageTwo = $this->get(route('blog.index', ['page' => 2]))->assertOk()
                        ->viewData('posts')->pluck('id');

                    expect($pageOne->intersect($pageTwo))->toBeEmpty();
                });
                ```

                The assertion is intersection-emptiness, not a specific ordering. That way the test does not encode an implementation detail — it encodes the property that actually matters: a row appears on exactly one page.

                ## The general rule

                Any time you paginate with an offset, the `ORDER BY` must be a total order. If the columns in it can tie, the pagination is not stable, and "not stable" means "users will see duplicated and missing rows under a load pattern you have not tested yet."

                Add the tiebreaker on the day you write the query. It costs one column in the sort and removes a class of bug that is genuinely painful to reproduce.
                MARKDOWN,
            ],
            [
                'title' => 'Server-Driven UI Is Not a Downgrade',
                'slug' => 'server-driven-ui-is-not-a-downgrade',
                'excerpt' => 'The reflex to reach for a SPA has a cost most teams never price in: a duplicated domain model and a second place for authorisation to be wrong.',
                'category' => 'frontend',
                'tags' => ['livewire', 'alpine-js', 'api-design', 'laravel'],
                'status' => PostStatus::Published,
                'age_days' => 18,
                'views' => 3104,
                'body' => <<<'MARKDOWN'
                Somewhere in the last decade "modern frontend" became a synonym for "React", and the default answer to "we need interactivity" became "we need a SPA." I want to make the case that for a large class of applications this trades a real problem for two invented ones.

                ## What the SPA actually buys you

                Be precise about it, because the benefits are real and only sometimes present:

                - **Rich interaction at 60fps** without a round trip. Genuinely necessary for editors, canvases, and drag-heavy tools.
                - **Offline capability** with a local-first data model.
                - **Decoupled release cadence** between a client team and an API team.
                - **Reusable client logic** across web and mobile.

                If you have none of these, you are paying the cost without collecting the benefit.

                ## What it costs

                ### A duplicated domain model

                Your backend has validation rules. Your form now needs the same rules so it can fail fast. Now there are two implementations, and they diverge.

                ```php
                // app/Livewire/.../PostEditor.php — one source of truth
                $validated = $this->validate([
                    'title' => ['required', 'string', 'max:255'],
                    'body' => ['required', 'string'],
                ]);
                ```

                With a SPA, that array exists twice: once here and once in a TypeScript schema file. The client copy is the one users see; the server copy is the one that actually protects the system. Keeping them aligned is ongoing work that produces no user-visible feature.

                ### A second authorisation surface

                Every endpoint the SPA calls is a public endpoint, even the ones only your dashboard uses.

                ```php
                // With Livewire: authorisation runs inside the component method.
                Gate::authorize('update', $post);
                ```

                With a SPA, that same check must also exist as middleware on the API route, and the component must not assume the check happened. Two places, one of which will eventually be forgotten on a new endpoint.

                ### A loading state for everything

                Server-rendered pages arrive complete. SPA screens arrive empty and then fill in, so every view needs a skeleton, an error state, and an empty state — three designs per screen instead of one.

                ## What server-driven UI actually looks like

                The point is not "no JavaScript." It is that the **authoritative state stays on the server** and the client sends small intents.

                ```php
                <?php

                use App\Models\Post;
                use Livewire\Component;

                new class extends Component {
                    public string $search = '';
                    public ?string $category = null;

                    #[Url]
                    public int $page = 1;

                    public function updatedSearch(): void
                    {
                        $this->page = 1;
                    }

                    public function render()
                    {
                        return view('livewire.blog-index', [
                            'posts' => Post::query()
                                ->published()
                                ->with(['category', 'tags'])
                                ->when($this->search, fn ($q) => $q->search($this->search))
                                ->when($this->category, fn ($q) => $q->inCategory($this->category))
                                ->recent()
                                ->paginate(9),
                        ]);
                    }
                };
                ```

                The search term, the filter, and the page live in PHP. Validation and authorisation live in PHP. There is exactly one implementation of every rule, and it is the one that runs.

                The client receives a diff and patches the DOM. On a warm connection that is 40–80ms — fast enough that the interaction reads as instant, and slow enough that you notice when you have built something that should have been a plain link.

                ## Where I still reach for a SPA

                Saying "never" would be its own kind of reflex. I reach for a client-side framework when:

                - The interaction is continuous rather than discrete — a canvas, a timeline editor, a drag-to-reorder board with hundreds of items.
                - The app must work offline.
                - There is a genuinely separate client team with a genuinely separate release cadence, and the coordination cost of a monolith exceeds the duplication cost of an API.

                Notice that all three are about the *shape* of the work, not about how modern it feels.

                ## The question to ask

                Instead of "should we use React," ask: **where does the authoritative state live, and how many places enforce the rules?**

                If the answer is "in the database, enforced in one place," then a server-rendered interface with targeted reactivity is not a downgrade. It is the short path to a system with fewer ways to be wrong.
                MARKDOWN,
            ],
            [
                'title' => 'Your Queue Retry Policy Is a Data Loss Bug',
                'slug' => 'your-queue-retry-policy-is-a-data-loss-bug',
                'excerpt' => 'Retrying a non-idempotent job turns a transient network blip into a duplicate charge. The fix is not more retries; it is making the job safe to run twice.',
                'category' => 'backend',
                'tags' => ['queues', 'horizon', 'concurrency', 'laravel'],
                'status' => PostStatus::Published,
                'age_days' => 26,
                'views' => 2247,
                'body' => <<<'MARKDOWN'
                A job fails because an upstream API times out. The queue retries it. The API had actually processed the request and the response was lost in transit. Now the customer has been charged twice, and the retry policy that was supposed to make the system resilient is the thing that broke it.

                ## Retries are not a resilience feature

                They are a **duplicate delivery** feature. That is their entire mechanism. Every retry is a second chance for the side effect to fire, and the framework has no way to know whether it already did.

                This is the same problem distributed systems have had forever — at-least-once delivery — and the only real solution is to make the consumer handle duplicates correctly.

                ## Making a job idempotent

                The principle is to derive an idempotency key from the *intent* of the operation, persist it before the side effect, and check it before repeating the work.

                ```php
                final class ChargeCustomer implements ShouldQueue
                {
                    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

                    public int $tries = 5;
                    public array $backoff = [10, 30, 120, 600];

                    public function __construct(
                        public readonly int $invoiceId,
                        public readonly string $idempotencyKey,
                    ) {}

                    public function handle(PaymentGateway $gateway): void
                    {
                        $alreadyCharged = DB::transaction(function (): bool {
                            $row = ChargeAttempt::query()
                                ->where('idempotency_key', $this->idempotencyKey)
                                ->lockForUpdate()
                                ->first();

                            if ($row !== null && $row->completed_at !== null) {
                                return true;
                            }

                            ChargeAttempt::query()->updateOrCreate(
                                ['idempotency_key' => $this->idempotencyKey],
                                ['invoice_id' => $this->invoiceId, 'attempted_at' => now()],
                            );

                            return false;
                        });

                        if ($alreadyCharged) {
                            return; // Safe no-op: a previous attempt succeeded.
                        }

                        $result = $gateway->charge(
                            invoiceId: $this->invoiceId,
                            idempotencyKey: $this->idempotencyKey,
                        );

                        ChargeAttempt::query()
                            ->where('idempotency_key', $this->idempotencyKey)
                            ->update(['completed_at' => now(), 'reference' => $result->reference]);
                    }
                }
                ```

                Three properties make this work:

                1. The key is **derived from intent**, not generated per attempt. `"invoice:{$invoiceId}:charge"` is stable across retries; a UUID generated in `handle()` is not.
                2. The record is written **inside a transaction with a row lock**, so two concurrent workers cannot both pass the check.
                3. The consumer is **safe to run twice**, so the retry policy becomes what it should have been all along — a way to recover from transient failures rather than a source of new ones.

                ## The idempotency key is not the same as the job id

                Laravel gives every job a unique id. It is tempting to use it as the key. Do not: retries of the same job share an id, but a **re-dispatch** after a failure — which is what happens when you call `->release()` or a supervisor restarts the worker — does not. A key derived from the job id therefore gives you the duplicate protection you expect in one path and none in the other, which is worse than no protection because it looks like it works.

                ## Where the key comes from

                | Operation | Key shape | Why |
                | --- | --- | --- |
                | Charge an invoice | `invoice:{id}:charge` | One charge per invoice, ever |
                | Send a receipt | `invoice:{id}:receipt:{email}` | One receipt per recipient, re-sendable to a new address |
                | Sync a record | `sync:{resource}:{id}:{version}` | Re-syncing the same version is a no-op |
                | Send a welcome email | `user:{id}:welcome` | Once per user |

                The key encodes the *thing that must happen once*, not the *attempt*.

                ## What to do about jobs you cannot make idempotent

                Some side effects genuinely cannot be de-duplicated — sending an SMS, calling a third party with no idempotency support. For those:

                - Set `$tries = 1` and accept the failure. A manual retry with human judgement is better than an automatic one that duplicates.
                - Or move the deduplication outward: check "was this sent" in your own records before calling, and accept that the window between check and call is a real risk you have documented rather than ignored.

                What you must not do is leave `$tries` at the default and hope.

                ## The audit I run on every new queue

                Four questions, in order:

                1. What happens if this job runs twice?
                2. What happens if it runs twice **concurrently**?
                3. What happens if it fails after the side effect but before the bookkeeping?
                4. What is the idempotency key, and is it derived from intent rather than from the attempt?

                If any answer is "I am not sure," the retry policy is currently a data-loss bug waiting for the right network blip.
                MARKDOWN,
            ],
            [
                'title' => 'Reversible Migrations Are a Team Sport',
                'slug' => 'reversible-migrations-are-a-team-sport',
                'excerpt' => 'A down() method that drops the column it just created is not reversible. It is a rollback that loses data, which is worse than no rollback at all.',
                'category' => 'databases',
                'tags' => ['mysql', 'postgresql', 'deployment', 'ci-cd'],
                'status' => PostStatus::Published,
                'age_days' => 34,
                'views' => 1408,
                'body' => <<<'MARKDOWN'
                Every migration generator emits a `down()` stub. Most teams never exercise it. The ones that do often discover, mid-incident, that rolling back destroys the data the migration was carefully preserving.

                ## The stub is not a rollback

                ```php
                public function up(): void
                {
                    Schema::table('users', function (Blueprint $table) {
                        $table->string('timezone')->nullable()->after('email');
                    });
                }

                public function down(): void
                {
                    Schema::table('users', function (Blueprint $table) {
                        $table->dropColumn('timezone');
                    });
                }
                ```

                This is fine for a column nobody has used yet. It is data loss the moment anyone has.

                ## Reversibility is about data, not schema

                A migration is reversible when running `down()` leaves the system in the same *state* it was in before `up()`. Schema shape is the easy half. The hard half is: what happened to the data, and what happens to it on the way back?

                Three categories, with different answers:

                ### Additive and derivable — genuinely reversible

                Adding a nullable column, or a column with a default. Rolling back drops it and loses only what was written. If nothing has been written, or the value is recomputable, the rollback is safe.

                ### Additive and non-derivable — reversible with a backup step

                Adding a column users have since filled in. Rolling back must preserve those values somewhere recoverable:

                ```php
                public function up(): void
                {
                    Schema::create('users_timezone_backup', function (Blueprint $table) {
                        $table->foreignId('user_id')->primary();
                        $table->string('timezone')->nullable();
                        $table->timestamp('captured_at');
                    });

                    Schema::table('users', function (Blueprint $table) {
                        $table->string('timezone')->nullable()->after('email');
                    });
                }

                public function down(): void
                {
                    DB::table('users')
                        ->whereNotNull('timezone')
                        ->orderBy('id')
                        ->chunk(500, function ($users): void {
                            DB::table('users_timezone_backup')->upsert(
                                $users->map(fn ($user) => [
                                    'user_id' => $user->id,
                                    'timezone' => $user->timezone,
                                    'captured_at' => now(),
                                ])->all(),
                                ['user_id'],
                                ['timezone', 'captured_at'],
                            );
                        });

                    Schema::table('users', function (Blueprint $table) {
                        $table->dropColumn('timezone');
                    });
                }
                ```

                The backup table survives the rollback, so no value is lost. It is dropped in a separate, deliberate migration once the rollback window has closed.

                ### Destructive — not reversible, and should say so

                Renaming a column, changing a type, dropping a table. The honest answer is that these cannot be rolled back automatically, and the migration should refuse rather than pretend:

                ```php
                public function down(): void
                {
                    throw new RuntimeException(
                        'This migration cannot be reversed automatically: the old '
                        .'`location` column was dropped. Restore from the pre-deploy '
                        .'snapshot, or re-create the column manually.'
                    );
                }
                ```

                A migration that throws on rollback is **better** than one that silently loses data, because the deployment pipeline stops instead of proceeding into an inconsistent state.

                ## The expand / contract pattern

                For genuinely irreversible changes, split them so no single step is irreversible:

                | Step | Migration | Reversible |
                | --- | --- | --- |
                | 1. Expand | Add `location_new`, backfill from `location` | Yes, drops the new column |
                | 2. Migrate reads | Application reads `location_new`, falls back to `location` | Yes, revert code |
                | 3. Migrate writes | Application writes both columns | Yes, revert code |
                | 4. Verify | Assert no writes have touched `location` for N days | n/a |
                | 5. Contract | Drop `location` | No — and by now, no reason to |

                The critical property is that steps 1 through 3 are deployable independently and each is reversible. The irreversible step happens only after the data has been proven to flow through the new path.

                ## Making it a team practice

                The tooling only helps if someone exercises it. Two cheap habits:

                **Run the rollback in CI.** Every migration is applied, rolled back, and reapplied as part of the pipeline:

                ```bash
                php artisan migrate --no-interaction
                php artisan migrate:rollback --step=1 --no-interaction
                php artisan migrate --no-interaction
                ```

                This catches the "down() references a table that up() renamed" class of bug immediately, on every pull request, instead of during an incident.

                **Require the rollback note.** Every migration pull request answers one question in the description: *what happens to the data on rollback?* The answer is either "nothing is written yet," "it is preserved in `<table>`," or "it is irreversible, here is the recovery procedure." Writing it down is what turns a stub into a policy.

                ## The point

                Reversibility is not a property of the `down()` method. It is a property of the migration *and* the data *and* the deployment sequence together. Treating it as a checkbox on the method is how teams end up with rollback procedures they are afraid to run — which is the same as having none.
                MARKDOWN,
            ],
            [
                'title' => 'Indexes Are Not Free and Your Write Path Knows It',
                'slug' => 'indexes-are-not-free-and-your-write-path-knows-it',
                'excerpt' => 'Adding an index makes one read faster and every write slower. Most teams only ever measure the first half of that trade.',
                'category' => 'databases',
                'tags' => ['postgresql', 'performance', 'observability'],
                'status' => PostStatus::Published,
                'age_days' => 48,
                'views' => 1893,
                'body' => <<<'MARKDOWN'
                Every index is a bet that you will read from the table more often, and more painfully, than you write to it. That bet is usually right. It is not always right, and nobody checks.

                ## What an index costs

                An index is a second data structure that must be kept consistent with the first. Every `INSERT` writes the row *and* every index entry. Every `UPDATE` on an indexed column removes and reinserts an index entry. Every `DELETE` removes from all of them.

                Concretely, for a table with six single-column indexes:

                | Operation | Write amplification |
                | --- | --- |
                | `INSERT` | 1 row + 6 index entries |
                | `UPDATE indexed_col` | 1 row + 2 index entries (delete + insert) |
                | `DELETE` | 1 row tombstone + 6 index tombstones |

                On a read-heavy table this is irrelevant. On a write-heavy table with a high insert rate — an events table, an audit log, a metrics buffer — it can be the difference between sustaining the load and not.

                ## Finding indexes nobody uses

                PostgreSQL tracks index usage per table:

                ```sql
                SELECT
                    schemaname,
                    relname              AS table_name,
                    indexrelname         AS index_name,
                    idx_scan             AS times_used,
                    pg_size_pretty(pg_relation_size(indexrelid)) AS size
                FROM pg_stat_user_indexes
                WHERE idx_scan = 0
                  AND indexrelname NOT LIKE '%_pkey'
                ORDER BY pg_relation_size(indexrelid) DESC;
                ```

                Anything with `idx_scan = 0` after a full traffic cycle — including your monthly report run — is pure cost. It is slowing every write and occupying storage none of your reads benefit from.

                ## Finding indexes that are redundant with each other

                A composite index on `(a, b)` already serves every query that would use an index on `(a)` alone. If both exist, the single-column one is strictly dead weight.

                ```sql
                SELECT
                    a.indexrelid::regclass AS redundant_index,
                    b.indexrelid::regclass AS covering_index,
                    pg_size_pretty(pg_relation_size(a.indexrelid)) AS wasted_space
                FROM pg_index a
                JOIN pg_index b
                  ON a.indrelid = b.indrelid
                 AND a.indexrelid <> b.indexrelid
                 AND a.indkey::text LIKE b.indkey::text || '%'
                WHERE a.indexrelid <> a.indisprimary;
                ```

                The leading-token match is what makes `(a)` redundant against `(a, b)` but not the reverse.

                ## Right-sizing the index you keep

                Column order in a composite index is not arbitrary. The leftmost column must be the one your queries filter on, and the order after that should follow selectivity:

                ```sql
                -- Serves: WHERE status = ? AND published_at > ?
                CREATE INDEX posts_status_published_at_index
                    ON posts (status, published_at DESC);

                -- Also serves: WHERE status = ?
                -- Does NOT serve: WHERE published_at > ?
                ```

                `status` has low cardinality — maybe four distinct values — but it is almost always present in the filter, so it leads. `published_at` is highly selective and used for ordering, so it follows and provides the sort for free.

                ## Partial indexes for skewed data

                When queries only ever touch a small subset of rows, index only that subset:

                ```sql
                -- Only published posts are ever read publicly.
                CREATE INDEX posts_published_recent_index
                    ON posts (published_at DESC)
                    WHERE status = 'published' AND deleted_at IS NULL;
                ```

                If ninety percent of rows are drafts and are never publicly queried, this index is a fraction of the size and faster to maintain — because the database never writes an entry for a row it will never be asked about.

                The trade is that the query must include the exact same predicate for the planner to use it. An equivalent `WHERE status = 'published'` will, but `WHERE status IN ('published')` will not.

                ## The measurement loop

                Do not add an index because a query is slow. Do this instead:

                1. Capture the actual plan for the actual query with realistic data volume: `EXPLAIN (ANALYZE, BUFFERS)`.
                2. Identify whether the cost is a sequential scan (wants an index), a bad row estimate (wants `ANALYZE` or a statistics target), or an N+1 at the application layer (wants `with()`, not an index).
                3. Add the index only for case one.
                4. Measure the write path before and after.

                Step four is the one people skip, and it is the one that matters, because it is the only step that tells you what you paid.

                ## The rule

                Indexes are a budget, not a checklist. The read side has a plan you can inspect; the write side has a cost you have to choose to measure. Teams that only ever look at `EXPLAIN` on slow queries end up with twenty indexes on a table that gets four thousand inserts a second, wondering why the writes are queueing.
                MARKDOWN,
            ],
            [
                'title' => 'Sanitising Markdown Without Shipping a Sanitiser to the Browser',
                'slug' => 'sanitising-markdown-without-shipping-a-sanitiser',
                'excerpt' => 'Where you sanitise matters more than what you sanitise with. Render once on write, store the result, and keep the sanitiser off the hot path.',
                'category' => 'security',
                'tags' => ['security', 'xss', 'caching', 'performance'],
                'status' => PostStatus::Published,
                'age_days' => 61,
                'views' => 1214,
                'body' => <<<'MARKDOWN'
                A markdown blog has one genuine security problem: the author's markdown becomes HTML, and HTML can carry script. Everything else is a detail about where to do the conversion.

                ## The two bad options

                ### Sanitise on read

                Every request parses the markdown, renders it, and sanitises the result. This is the default in most setups, and it has three problems:

                - It burns CPU regenerating identical output for content that changed three months ago.
                - The sanitiser is on the request path, so a bypass is an active vulnerability rather than a stored-data problem you can find and fix.
                - It makes the render function a hot dependency that must be correct for every request, forever.

                ### Sanitise on the client

                Ship a markdown parser and a sanitiser, render in the browser. Now you have three problems: the client sanitiser has a different bypass surface than the server one, a client-side bypass is exploitable by anyone reading the page, and you have added ~90KB to every page load to render content that never changes.

                ## The option that is actually good

                Render and sanitise **once, at write time**, store the sanitised HTML alongside the markdown, and never parse on read.

                ```
                write:   markdown ──► parse ──► sanitise ──► body_html (stored)
                read:    body_html ──► {!! !!}   (no parsing, no sanitising)
                ```

                Three consequences, in decreasing order of importance:

                1. **The sanitiser runs once per edit, not once per view.** A bypass affects stored content, which is a data-integrity problem with a clear fix: purge and re-render. It is not a live vulnerability.
                2. **Reads are a single column fetch.** The public page does no parsing at all.
                3. **There is exactly one place to audit.** A grep for `body_html` returns every write site.

                ## The critical constraint

                The sanitiser must be the **only** writer of the rendered column. The moment a second code path can write it — an admin importer, a console command, a seeder that hand-builds HTML — you have reintroduced the problem the design was meant to eliminate.

                A model that declares `body_html` non-fillable is what enforces this:

                ```php
                #[Fillable([
                    'title', 'slug', 'excerpt', 'body',
                    'status', 'is_featured', 'published_at', 'category_id',
                ])]
                class Post extends Model
                {
                    // body_html, body_text, word_count, reading_time are absent
                    // by design: they are derived, and only the rendering
                    // pipeline may write them.
                }
                ```

                Because they are not fillable, `Post::create($request->all())` cannot inject a pre-rendered `body_html`. Because they are written via `forceFill()` inside one action, a grep finds every writer.

                ## What the sanitiser must actually do

                Four things, and a surprising number of implementations miss at least one:

                **Allow-list tags, do not deny-list them.** A deny list of `<script>` and `<iframe>` is a permanent game of catch-up against `<svg onload>`, `<math href>`, and the rest. Name what is permitted and drop everything else.

                ```php
                protected const array ALLOWED_TAGS = [
                    'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                    'strong', 'em', 'del', 'code', 'pre', 'kbd', 'mark',
                    'ul', 'ol', 'li', 'blockquote', 'a',
                    'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img',
                ];
                ```

                **Allow-list attributes per tag.** `<a>` gets `href`, `title`, `target`, `rel`. It does not get `onclick`, `style`, or `formaction`.

                **Reject dangerous URL schemes.** `javascript:` and `data:` are the two that matter; `parse_url()` plus a scheme allow-list closes both:

                ```php
                protected function isUnsafeUrl(string $url): bool
                {
                    $scheme = strtolower((string) parse_url(trim($url), PHP_URL_SCHEME));

                    if ($scheme === '') {
                        return false;   // Relative URL: safe.
                    }

                    return ! in_array($scheme, ['http', 'https', 'mailto'], true);
                }
                ```

                **Escape before you mark up, not after.** The ordering inside the inline renderer is the whole ballgame: escape the author's text first, then apply `**bold**` and `[link](url)` transformations. Reversing that order lets `**<script>**` through as markup.

                ## The test that proves it

                ```php
                test('raw script tags in markdown never reach rendered html', function () {
                    $html = app(MarkdownRenderer::class)->render(
                        "<script>alert('xss')</script>\n\nNormal **text**."
                    );

                    expect($html)
                        ->not->toContain('<script')
                        ->not->toContain('alert');
                });

                test('javascript urls are stripped from links', function () {
                    $html = app(MarkdownRenderer::class)
                        ->render('[click](javascript:alert(1))');

                    expect($html)->not->toContain('javascript:');
                });
                ```

                Both assert on the rendered output rather than on the sanitiser's internals, so they keep working if the implementation changes and keep failing if the property is lost.

                ## The generalisable principle

                For any transformation between untrusted input and rendered output, ask two questions: **where does it run**, and **how many things can do it**.

                Running it on write bounds the blast radius to stored data. Having exactly one writer makes the audit tractable. Getting those two properties right matters far more than which sanitiser library you picked, because a correct library wired to two write paths is still an open door.
                MARKDOWN,
            ],
            [
                'title' => 'The Code Review Comment I Stopped Leaving',
                'slug' => 'the-code-review-comment-i-stopped-leaving',
                'excerpt' => 'I used to write "this could be more efficient" about fifty times a year. It never once led to a better pull request, and I eventually worked out why.',
                'category' => 'career',
                'tags' => ['code-review', 'mentoring', 'refactoring'],
                'status' => PostStatus::Published,
                'age_days' => 79,
                'views' => 3567,
                'body' => <<<'MARKDOWN'
                For the first few years of reviewing code I wrote a comment that looked helpful and was close to worthless: *"This could be more efficient."*

                It is technically true most of the time. It is also unactionable, unmeasurable, and it puts the burden of deciding whether it matters onto the person least equipped to make that call — the author, who now has to guess which of fifty possible efficiencies I meant.

                ## Why it fails

                A code review comment needs three things to be useful: a **specific** observation, a **reason** it matters, and a **suggested** direction. That comment has none of them.

                It also has a hidden cost. The author reads it as "the reviewer thinks I am not good at this," because there is no content to engage with — only a judgement. They either ignore it (and learn that review comments are noise) or gold-plate the change (and ship something more complex for no measured benefit).

                ## What I write instead

                Three replacements, depending on what I actually observed:

                ### When I have measured something

                > "This loop calls `$post->category` inside it. With 9 posts that's 9 extra queries — the index page currently does 3 total. Adding `->with('category')` to the query keeps it at 3."

                Specific, measured, with the fix. The author can verify it in thirty seconds and the conversation is about a number rather than a feeling.

                ### When I suspect something but have not measured

                > "I think this is O(n²) in the number of tags, but I haven't profiled it. Do you know what the realistic upper bound on tags per post is? If it's under 10 I'd leave it."

                This is honest about the uncertainty and invites the author's context, which I do not have. It also gives them permission to leave it alone — which is often the right call, and one my original comment never allowed.

                ### When it is a preference, not a problem

                > "I'd normally extract this into a scope, but it's only used here so I don't think it's worth it. No change needed — flagging it in case you were planning to reuse it."

                Saying explicitly that no change is needed, and why I mentioned it anyway, removes the implicit demand. It also distinguishes "I would do it differently" from "this is wrong," which are very different claims that most review comments blur.

                ## The distinction that matters

                Almost every unhelpful review comment comes from collapsing three different statements into one:

                | Statement | Requires a change? | Needs evidence? |
                | --- | --- | --- |
                | **This is wrong** | Yes | No — correctness is binary |
                | **This will cost us later** | Yes, eventually | Yes — a number or a plausible scenario |
                | **I would do it differently** | No | No |

                The first is a bug report and should be stated plainly. The second is a trade-off and needs evidence, because otherwise it is the third in disguise. The third is a preference and should be labelled as one.

                Most reviewers are confidently writing the third and hearing it as the first.

                ## What changed in the teams I have worked with

                Once I started labelling my comments, two things happened.

                The number of comments went **down**, because a lot of what I used to write turned out to be preference, and preferences do not need to be said out loud.

                The number of changes made went **up**, because the comments that survived were specific enough to act on and were not competing with thirty other vague suggestions for the author's attention.

                ## The habit

                Before leaving a comment, name which of the three it is. If it is a preference, say so and move on. If it is a cost, bring a number or a scenario. If it is a bug, say it directly and skip the softening.

                It takes about four extra seconds per comment and it is the highest-leverage change I have made to how I work with other engineers.
                MARKDOWN,
            ],
            [
                'title' => 'Reading Your Own Query Plans',
                'slug' => 'reading-your-own-query-plans',
                'excerpt' => 'Stop guessing which query is slow. EXPLAIN ANALYZE with BUFFERS tells you what actually happened, and the answer is usually not what you assumed.',
                'category' => 'databases',
                'tags' => ['postgresql', 'performance', 'observability'],
                'status' => PostStatus::Published,
                'age_days' => 96,
                'views' => 1622,
                'body' => <<<'MARKDOWN'
                The most common performance debugging mistake is reading the query and reasoning about what it should do. The database has already told you what it actually did, and it disagrees with you more often than you would like.

                ## The tool

                ```sql
                EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
                SELECT id, title, published_at
                FROM posts
                WHERE status = 'published'
                  AND published_at > NOW() - INTERVAL '90 days'
                ORDER BY published_at DESC
                LIMIT 9;
                ```

                `ANALYZE` runs the query and reports real timings. `BUFFERS` reports how much data came from cache versus disk. Without `ANALYZE` you get the planner's *estimate*, which is exactly the thing you are trying to check.

                Never run bare `EXPLAIN` on a slow query and draw conclusions. The estimate is derived from statistics that may be stale, and the whole reason the query is slow is often that the estimate is wrong.

                ## Reading it

                Each node reports three numbers that matter:

                ```
                Limit  (cost=1024.55..1024.57 rows=9 width=48) (actual time=8.412..8.419 rows=9 loops=1)
                  Buffers: shared hit=1804 read=42
                  ->  Sort  (cost=1024.55..1031.18 rows=2652 width=48) (actual time=8.404..8.406 rows=9 loops=1)
                        Sort Key: published_at DESC
                        Sort Method: top-N heapsort  Memory: 25kB
                        Buffers: shared hit=1804 read=42
                        ->  Seq Scan on posts  (cost=0.00..948.00 rows=2652 width=48) (actual time=0.021..6.881 rows=2654 loops=1)
                              Filter: ((status = 'published') AND (published_at > (now() - '90 days'::interval)))
                              Rows Removed by Filter: 7346
                              Buffers: shared hit=1804 read=42
                ```

                ### `rows=` estimated versus actual

                This is the single most informative comparison. Here the planner estimated 2 652 rows and got 2 654 — an accurate estimate, and the plan is reasonable.

                When these diverge by more than about 10×, the planner has made a decision based on bad information, and no amount of index tuning will help until the estimate is fixed.

                ### `Rows Removed by Filter`

                Seven thousand three hundred and forty-six rows were read and discarded. That means the index did not cover the predicate, so the database scanned the table and threw most of it away.

                This is the number that tells you to add a partial index. If you filter on `status = 'published'` and most rows are drafts, a partial index means those 7 346 rows are never read at all.

                ### `Buffers: shared hit` versus `read`

                `hit=1804, read=42` means almost everything came from the page cache. On a cold cache this query would be dramatically slower, which is worth knowing before you conclude it is fast.

                If `read` matches `hit` in magnitude, you are I/O bound and the fix is usually a smaller working set — fewer columns, a covering index — not more RAM, which just moves the problem to the next data growth increment.

                ### `loops=`

                The most commonly missed number. Every node reports `actual time=<startup>..<total>` and `rows=<rows per loop>`, with `loops=<count>`.

                A node showing `rows=1` with `loops=5000` returned five thousand rows in total, and the `actual time` is **per loop**. Multiply before you conclude a node is cheap:

                ```
                ->  Index Scan using categories_pkey on categories
                      (cost=0.28..8.30 rows=1 width=32)
                      (actual time=0.008..0.009 rows=1 loops=5000)
                ```

                Nine microseconds per loop sounds free. Multiply by five thousand and it is 45 milliseconds of the query's total, for a lookup that an eager load would have turned into one scan of fifty rows.

                This is how a nested loop hides an N+1 that the application layer would have made obvious.

                ## The three fixes, in order

                **Bad row estimate → fix the statistics.**

                ```sql
                ANALYZE posts;
                -- Or, for a column with a skewed distribution:
                ALTER TABLE posts ALTER COLUMN status SET STATISTICS 1000;
                ```

                **Rows removed by filter → add a partial or covering index.**

                ```sql
                CREATE INDEX posts_published_recent_index
                    ON posts (published_at DESC)
                    WHERE status = 'published' AND deleted_at IS NULL;
                ```

                **High `loops` → fix it in the application.** No index helps a query that issues five thousand separate lookups. Add the eager load.

                ## What to stop doing

                Stop adding indexes because a query "looks slow." The plan tells you whether the bottleneck is a scan (index), an estimate (statistics), an I/O pattern (working set), or a loop count (application). Each has a different fix, and applying the wrong one costs you write throughput while leaving the read path exactly as slow as it was.

                Read the plan first. It is right there, and it is not lying to you.
                MARKDOWN,
            ],
            [
                'title' => 'Why I Write ADRs Before Code',
                'slug' => 'why-i-write-adrs-before-code',
                'excerpt' => 'An architecture decision record is the cheapest artefact you will ever produce and the only one that tells a future engineer why the obvious solution was rejected.',
                'category' => 'architecture',
                'tags' => ['mentoring', 'technical-debt', 'refactoring'],
                'status' => PostStatus::Published,
                'age_days' => 128,
                'views' => 2410,
                'body' => <<<'MARKDOWN'
                Code tells you what the system does. Comments tell you what a line is for. Neither tells you what was rejected, and the rejected options are usually where the real engineering judgement lives.

                ## The failure mode an ADR prevents

                Eighteen months from now someone — possibly you — will look at the queue configuration and ask why jobs retry five times with a 10-second initial backoff instead of ten times with a 60-second one. The code says nothing. Slack has scrolled past 10 000 messages. The person who made the call has left.

                So they change it. And they reintroduce the exact latency spike the original numbers were chosen to avoid, because the constraint that made them correct — an upstream API with a 5rps rate limit — is not written down anywhere.

                ## What an ADR contains

                Four sections. Anything longer does not get read; anything shorter does not get used.

                ```markdown
                ## ADR-007: Related posts by tag overlap, then category, with a bounded query

                **Context.** Each post should suggest further reading without a
                recommendation service. Naive candidate selection grows linearly
                with the corpus.

                **Decision.** Rank candidates by shared tag count, break ties by
                shared category, then by recency. Limit to 3. Compute the ranking
                in PHP over a candidate set that is bounded in the database first.

                **Consequences.**
                - Deterministic, explainable, testable.
                - No extra infrastructure.
                - The candidate set must be bounded or the query degrades as the
                  corpus grows. Bounded by category plus the top-N tags, hard
                  capped.

                **Rejected.** A window function over the full corpus — clearer in
                SQL, but not portable across the SQLite/MySQL/PostgreSQL targets
                this project supports.
                ```

                The **Rejected** section is the one that earns its keep. It is the only place a future engineer learns that the obvious approach was considered and why it lost.

                ## Why it goes before the code

                Writing the ADR first changes what you build, because it forces you to state the constraint you are optimising for. Half the time, articulating the constraint reveals that the decision is not actually a decision — there is only one option that satisfies it.

                The other half of the time, it reveals that there are two options and the tie-breaker is something nobody has said out loud yet, like "we do not have the operational capacity to run a search cluster."

                That is a much cheaper discovery than making it three sprints into the implementation.

                ## The ADR is not a design document

                A design document describes what you are going to build. An ADR records a **decision** — a fork in the road where the alternatives were real and the reasoning is not recoverable from the code.

                Things that deserve an ADR:

                - Choosing a datastore, a queue backend, or an auth strategy.
                - Deciding *not* to add a dependency the team expected.
                - A performance trade-off with a number attached.
                - A deliberate inconsistency with the rest of the codebase.
                - Any decision that would look like a mistake without the context.

                Things that do not:

                - How a specific class is structured.
                - Naming conventions.
                - Anything reversible in an afternoon.

                ## Making it stick

                Three rules that have kept this working on the teams I have been on:

                **Keep them in the repository.** `docs/decisions/ADR-007-*.md`, next to the code, reviewed in the same pull request. A wiki nobody opens is where ADRs go to die.

                **One decision per file, numbered, never edited.** To change a decision, write a new ADR that supersedes the old one. The history is the point; editing it destroys the artefact's only advantage over a design doc.

                **Timebox it to twenty minutes.** If an ADR takes an afternoon, you are writing a design document. The value is in the constraint statement and the rejected option, not in the prose.

                ## What it actually buys

                It is not documentation for its own sake. It is a mechanism for **preventing relitigating the past**, which is one of the most expensive things a team does.

                A team that can answer "why is it like this" in thirty seconds spends its engineering judgement on new problems. A team that cannot spends it arguing about decisions that were already made for good reasons nobody can now recall.
                MARKDOWN,
            ],
            [
                'title' => 'Caching Without Invalidation Regret',
                'slug' => 'caching-without-invalidation-regret',
                'excerpt' => 'Most caching bugs are not cache misses. They are cache hits returning something that stopped being true an hour ago.',
                'category' => 'backend',
                'tags' => ['caching', 'redis', 'performance', 'laravel'],
                'status' => PostStatus::Published,
                'age_days' => 143,
                'views' => 1976,
                'body' => <<<'MARKDOWN'
                A cache miss is a performance problem. A stale cache hit is a correctness problem. Teams spend most of their effort on the first and get burned by the second.

                ## The taxonomy that actually helps

                Before choosing a TTL, classify what you are caching. The class determines the invalidation strategy, and the strategy determines whether the cache is safe.

                | Class | Example | Invalidation | Safe TTL |
                | --- | --- | --- | --- |
                | **Immutable** | A published post's rendered HTML at a given version | None needed | Infinite |
                | **Derived, bounded staleness OK** | Category post counts | Event-driven | Hours |
                | **Derived, must be exact** | Account balance | Event-driven + versioning | None — do not cache |
                | **Expensive, tolerance unknown** | Third-party API response | TTL only | Depends on the contract |

                The dangerous cell is the third row. If the value must be exact and you cannot guarantee invalidation, caching it is not a performance optimisation — it is a correctness bug with a delay fuse.

                ## Key derivation decides everything

                Two rules about keys, and both are load-bearing.

                **The key must encode everything the value depends on.** If the rendered output changes when the post's body changes, the version must be in the key:

                ```php
                $key = "post:{$post->id}:html:v{$post->updated_at->timestamp}";
                ```

                Now a stale entry is *unreachable* rather than *invalidated*. The old value is still in the cache, but nothing will ever look it up again, and it expires on its own. This is vastly more reliable than trying to actively delete the right keys, because it cannot be defeated by a missed delete path.

                **The key must not depend on anything volatile.** `post:{$id}:html:user:{$userId}` is fine if the output really varies per user. If it does not, you have just multiplied your cache size by your user count for no benefit and dropped the hit rate to near zero.

                ## Version-keyed caching in practice

                The pattern that has caused me the fewest problems:

                ```php
                final class CachedPostHtml
                {
                    public function __construct(protected MarkdownRenderer $renderer) {}

                    public function get(Post $post): string
                    {
                        // The version is the post's own content hash. Any edit
                        // produces a new key; no delete is ever required.
                        $version = substr(md5($post->body.$post->updated_at), 0, 12);
                        $key = "post:{$post->id}:html:{$version}";

                        return Cache::rememberForever($key, function () use ($post): string {
                            return $this->renderer->render($post->body);
                        });
                    }
                }
                ```

                Properties worth noting:

                - **`rememberForever` is safe here** because the key changes on every edit. There is no stale state to expire; there is only unreachable state, which a periodic `cache:prune-stale-tags` job or a TTL on the underlying store handles.
                - **The version derives from the content**, not from a manual `bump()` call that a developer must remember to make.
                - **There is no delete path**, so there is no delete path that can be forgotten.

                ## Where TTL is the right tool

                Sometimes you genuinely cannot know when a value changes — a third-party API, an external feed. Then TTL is your only lever, and the question is not "how long should this live" but "how wrong is it acceptable for this to be."

                That is a product question, not an infrastructure question, and it should be answered by the person who owns the consequence. "Stock levels may be up to five minutes stale and could show an out-of-stock item as available" is a sentence a product owner should have to agree to.

                Write it in the ADR. It is a decision, and someone will ask about it later.

                ## The three-question audit

                Before adding a cache entry, answer:

                1. **What is the worst thing that happens if this is stale?** If the answer is "a user sees outdated information and makes a decision on it," either version-key it or do not cache it.
                2. **What in the key changes when the value changes?** If nothing does, the cache is a bug factory regardless of TTL.
                3. **How will I notice if it goes stale?** A cache that can go stale silently needs a monitor, or it needs a shorter TTL, or it needs to not exist.

                ## The uncomfortable conclusion

                Caching is not primarily a performance technique. It is a **consistency model choice**, and treating it as a performance knob is how teams end up with a system that is fast, correct in every test, and intermittently wrong in production in a way nobody can reproduce.

                Add the cache when the measurement says you need it, version the key from the content, and write down how stale it is allowed to be. That is the whole discipline.
                MARKDOWN,
            ],
            [
                'title' => 'Scheduled Posts and the Timezone You Forgot',
                'slug' => 'scheduled-posts-and-the-timezone-you-forgot',
                'excerpt' => 'Storing local times instead of UTC means your scheduled post publishes at the wrong moment twice a year, and only in the countries that observe daylight saving.',
                'category' => 'backend',
                'tags' => ['caching', 'queues', 'testing'],
                'status' => PostStatus::Draft,
                'age_days' => 3,
                'body' => <<<'MARKDOWN'
                Draft in progress. Notes for the write-up.

                ## The bug

                A scheduled post set for 9am publishes at 8am or 10am, depending on the date, but only for users in DST-observing timezones. Twelve countries report it. Everyone else cannot reproduce it.

                ## The rule

                Store UTC. Convert at the boundary — display and input. Never store a local time, never store an offset alongside a wall-clock time.

                ## The subtlety with recurring schedules

                A "9am every day" schedule is not a UTC time. It is a wall-clock time in a named zone, and it shifts. Storing it as 09:00 UTC gets it wrong for half the year.

                ```php
                // Wrong: pinned to UTC, drifts with DST.
                $post->published_at = '2026-11-01 09:00:00';

                // Right: anchored to the author's zone, evaluated at publish time.
                $post->published_at = CarbonImmutable::parse(
                    '2026-11-01 09:00:00',
                    config('app.author_timezone'),
                )->utc();
                ```

                ## Testing it

                `Carbon::setTestNow()` plus a date either side of a transition. Anything else misses the only case that matters.

                ## To cover

                - The two DST transitions in both directions
                - Queues replaying a job after a long outage
                - What happens when a post is scheduled in the past
                MARKDOWN,
            ],
            [
                'title' => 'Notes: Migrating a Monolith to Modular Borders',
                'slug' => 'notes-migrating-a-monolith-to-modular-borders',
                'excerpt' => 'Rough notes from a migration that took seven months. Mostly about what we got wrong and why the boundary drawing was the hard part, not the code moving.',
                'category' => 'architecture',
                'tags' => ['refactoring', 'technical-debt', 'mentoring', 'docker'],
                'status' => PostStatus::Draft,
                'age_days' => 9,
                'body' => <<<'MARKDOWN'
                Unfinished. Writing this up properly once I have the numbers.

                ## What we did

                Moved from one `app/` tree to modules with enforced borders, over seven months, without a feature freeze.

                ## What was hard

                Not the code moving. Drawing the boundary correctly, and resisting the urge to draw it where the code was already clean rather than where the coupling actually was.

                ## Number I want to double check

                - Cyclomatic complexity before and after
                - Number of cross-module imports that survived
                - Deploy frequency change

                ## Things I would do differently

                1. Start with the noisiest module, not the cleanest. Clean modules prove nothing.
                2. Write the border test first — the arch test that fails on a violation. Without it the border erodes within a sprint.
                3. Do not rename anything during the move. Two changes at once makes the diff unreviewable.

                ## To expand

                - The arch test itself, with the actual rule
                - How we handled the shared kernel
                - What we deliberately left in the monolith
                MARKDOWN,
            ],
        ];
    }
}
