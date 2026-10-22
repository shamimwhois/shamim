<?php

use App\Enums\CommunityKind;
use App\Enums\PostStatus;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\FeedPost;
use App\Models\MarketplaceProduct;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\CommunitySeeder;
use Database\Seeders\FeedPostSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * The community feed index — the model-backed, moderation-aware surface.
 *
 * The corpus is seeded from {@see FeedCorpus} exactly as a deploy would, so
 * these tests exercise the same path production renders: migration rows,
 * not a static array.
 */
beforeEach(function (): void {
    $this->seed(FeedPostSeeder::class);
});

it('renders the feed page in the feed design system', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertSee('compare&nbsp;notes', false)
        ->assertSee('Top contributors')
        ->assertSee('House guidelines')
        ->assertSee('Load more posts');
});

it('filters posts by the URL-bound topic and sort state', function (): void {
    Livewire::withQueryParams(['category' => 'backend', 'sort' => 'unanswered'])
        ->test('pages::feed')
        ->assertOk()
        ->assertSee('Daniel Okafor')
        ->assertSee('1 matching posts');
});

it('degrades a crafted query string to the default view', function (): void {
    Livewire::withQueryParams(['category' => 'privilege-escalation', 'sort' => 'hot'])
        ->test('pages::feed')
        ->assertOk()
        ->assertSee('Priya Menon');
});

it('filters the feed by the URL-bound content type', function (): void {
    Livewire::withQueryParams(['type' => 'poll'])
        ->test('pages::feed')
        ->assertOk()
        ->assertSee('Nadia Petrova')
        ->assertSee('total votes')
        ->assertSee('1 day left')
        ->assertDontSee('DeepPatch');
});

it('degrades a crafted content type to all types', function (): void {
    Livewire::withQueryParams(['type' => 'sneaky'])
        ->test('pages::feed')
        ->assertOk()
        ->assertSee('Priya Menon')
        ->assertSee('10 matching posts');
});

it('filters the feed by the URL-bound tag', function (): void {
    Livewire::withQueryParams(['tag' => 'Queues'])
        ->test('pages::feed')
        ->assertOk()
        ->assertSee('chunkById vs lazyById')
        ->assertSee('1 matching posts');
});

it('degrades a crafted tag to every tag', function (): void {
    Livewire::withQueryParams(['tag' => 'not-a-real-tag'])
        ->test('pages::feed')
        ->assertOk()
        ->assertSee('10 matching posts');
});

it('searches across title, body, author and tags', function (): void {
    Livewire::withQueryParams(['q' => 'rust'])
        ->test('pages::feed')
        ->assertOk()
        ->assertSee('Rayhan Kabir')
        // "rust" also matches "trust" in the boring-tools post; the count line
        // is what proves the stream itself narrowed to the matches.
        ->assertSee('2 matching posts');
});

it('orders the trending sort by total reactions', function (): void {
    Livewire::withQueryParams(['sort' => 'trending'])
        ->test('pages::feed')
        ->assertOk()
        // The starter-kit showcase carries the heaviest reaction total in
        // the corpus (158 across like/love/insight).
        ->assertSee('Cut v1.0 of our starter kit');
});

it('orders the solved sort to answered questions', function (): void {
    Livewire::withQueryParams(['sort' => 'solved'])
        ->test('pages::feed')
        ->assertOk()
        ->assertSee('Tailwind v4 dark mode classes stopped working')
        // One question in the corpus carries an answer; the other is open.
        ->assertSee('1 matching posts');
});

it('floats the pinned discussion to the top and links its full thread', function (): void {
    Livewire::test('pages::feed')
        ->assertOk()
        ->assertSee('Pinned')
        ->assertSee('Open the full thread', false)
        ->assertSee(route('feed.show', ['slug' => 'rust-async-stream-processing']), false);
});

it('runs one corpus query for the stream and every rail widget', function (): void {
    // The directory is seeded here on purpose: with zero communities the
    // membership lazy-load the rail could fall into has no rows to walk, so
    // the budget would look clean while a real guest render paid one query
    // per community.
    $this->seed(CommunitySeeder::class);

    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::test('pages::feed')->assertOk();

    // One corpus query for the stream and the chrome, one for the unanswered
    // slice the "Needs answers" widget owns, one for the community directory
    // with its live member counts, and one for the marketplace's featured
    // listings. Four owned queries, and no reply widget may add a fifth.
    expect($queries)->toBeLessThanOrEqual(4);
});

it('hides posts moderation hid from the feed', function (): void {
    FeedPost::query()->where('slug', 'eloquent-n1-dashboard')->update(['hidden' => true]);

    Livewire::test('pages::feed')
        ->assertOk()
        ->assertDontSee('pulled the N+1 out of the dashboard post list');
});

it('removes deleted posts from the feed entirely', function (): void {
    FeedPost::query()->where('slug', 'design-systems-hill')->update(['deleted' => true]);

    Livewire::test('pages::feed')
        ->assertOk()
        ->assertDontSee('Priya Menon');
});

it('restarts paging when a filter changes', function (): void {
    Livewire::test('pages::feed')
        ->assertSee('Load more posts')
        ->call('loadMore')
        ->call('loadMore')
        ->call('loadMore')
        // Ten posts, three visible per page: the third Load more exhausts the set.
        ->assertDontSee('Load more posts')
        // A filter change restarts paging from the top of the new set.
        ->set('type', 'poll')
        ->assertSee('1 matching posts')
        ->assertDontSee('Load more posts');
});

it('clears every filter from the empty state', function (): void {
    Livewire::withQueryParams(['q' => 'nonexistent-term'])
        ->test('pages::feed')
        ->assertSee('No matching posts')
        ->call('clearFilters')
        ->assertSee('10 matching posts');
});

/*
|--------------------------------------------------------------------------
| Live feed
|--------------------------------------------------------------------------
*/

it('renders the full-width desktop grid with its live wiring', function (): void {
    Livewire::test('pages::feed')
        ->assertOk()
        ->assertSee('max-w-[1920px]', false)
        ->assertSee('wire:poll.15s="syncFeed"', false);
});

it('shows no live pill while nothing has arrived', function (): void {
    Livewire::test('pages::feed')
        ->assertOk()
        ->assertDontSee('data-test="feed-live-pill"', false);
});

it('announces a new post and folds it in only when the reader asks', function (): void {
    $component = Livewire::test('pages::feed');

    FeedPost::query()->create([
        'slug' => 'fresh-arrival',
        'title' => 'Fresh arrival: queue backpressure in practice',
        'author' => 'Test Author',
        'handle' => '@tester',
        'avatar' => 'TA',
        'posted_at' => now(),
        'topic' => 'backend',
        'type' => 'discussion',
        'body' => 'A post that arrives while the feed is open.',
        'tags' => ['Queues'],
        'reactions' => ['like' => 1, 'love' => 0, 'insight' => 0],
    ]);

    // The poll notices the arrival but leaves the stream where it was.
    $component->call('syncFeed')
        ->assertSet('pendingNew', 1)
        ->assertSee('1 new post')
        ->assertDontSee('Fresh arrival');

    // The click is what moves the stream, once.
    $component->call('showNewPosts')
        ->assertSet('pendingNew', 0)
        ->assertSee('Fresh arrival');
});

it('keeps the poll quiet after the new posts are folded in', function (): void {
    $component = Livewire::test('pages::feed');

    FeedPost::query()->create([
        'slug' => 'fresh-arrival',
        'title' => 'Fresh arrival',
        'author' => 'Test Author',
        'handle' => '@tester',
        'avatar' => 'TA',
        'posted_at' => now(),
        'topic' => 'backend',
        'type' => 'discussion',
        'body' => 'Arrives once.',
        'tags' => ['Queues'],
        'reactions' => ['like' => 1, 'love' => 0, 'insight' => 0],
    ]);

    $component->call('syncFeed')->assertSet('pendingNew', 1);
    $component->call('showNewPosts')->assertSet('pendingNew', 0);
    $component->call('syncFeed')->assertSet('pendingNew', 0);
});

it('does not announce posts the active filter would hide', function (): void {
    $component = Livewire::withQueryParams(['type' => 'poll'])->test('pages::feed');

    // A discussion arrives while the reader is looking at polls only.
    FeedPost::query()->create([
        'slug' => 'filtered-out-arrival',
        'title' => 'A discussion the poll filter hides',
        'author' => 'Test Author',
        'handle' => '@tester',
        'avatar' => 'TA',
        'posted_at' => now(),
        'topic' => 'backend',
        'type' => 'discussion',
        'body' => 'Not a poll.',
        'tags' => ['Queues'],
        'reactions' => ['like' => 1, 'love' => 0, 'insight' => 0],
    ]);

    $component->call('syncFeed')->assertSet('pendingNew', 0);
});

it('publishes demo posts from the console command', function (): void {
    $before = FeedPost::query()->count();

    $this->artisan('feed:simulate', ['--count' => 2])
        ->assertSuccessful()
        ->expectsOutput('Published 2 demo posts into the feed.');

    // Two genuine rows land in the database — the command is not a no-op, and
    // it does not rewrite the seeded corpus. A delta keeps the assertion honest
    // if the seeder's post count changes.
    expect(FeedPost::query()->count())->toBe($before + 2);
});

it('defaults to a single post when no count is given', function (): void {
    $before = FeedPost::query()->count();

    $this->artisan('feed:simulate')->assertSuccessful();

    expect(FeedPost::query()->count())->toBe($before + 1);
});

it('floors a zero count at one post', function (): void {
    $before = FeedPost::query()->count();

    // The handler clamps to at least one so a typo'd `--count=0` cannot wipe
    // the feed; assert that floor rather than silently creating nothing.
    $this->artisan('feed:simulate', ['--count' => 0])->assertSuccessful();

    expect(FeedPost::query()->count())->toBe($before + 1);
});

it('does not de-duplicate across runs', function (): void {
    $before = FeedPost::query()->count();

    $this->artisan('feed:simulate', ['--count' => 3])->assertSuccessful();
    $this->artisan('feed:simulate', ['--count' => 3])->assertSuccessful();

    // Each run writes its own rows (slugs carry a random suffix), so a re-run
    // must add six, not overwrite. Guards against a future "upsert" change.
    expect(FeedPost::query()->count())->toBe($before + 6);
});

/*
|--------------------------------------------------------------------------
| Membership rail
|--------------------------------------------------------------------------
*/

it('derives the inspector telemetry from the corpus', function (): void {
    Livewire::test('pages::feed')
        ->assertOk()
        ->assertSee('Corpus telemetry')
        ->assertSee('Answer rate')
        // The right rail must not resurrect the hardcoded demo numbers.
        ->assertDontSee('top 3% this week');
});

it('shows an honest empty state for the featured-projects rail', function (): void {
    // The corpus seeder seeds no marketplace listings, so the widget must say
    // so rather than invent products.
    Livewire::test('pages::feed')
        ->assertOk()
        ->assertSee('data-test="featured-empty"', false)
        ->assertSee('No featured products yet');
});

it('links a featured listing to its marketplace detail page', function (): void {
    // The empty state is the easy branch; the populated one builds a route
    // per row, and a wrong parameter name is a 500 rather than a broken link.
    $product = MarketplaceProduct::factory()->published()->featured()->create([
        'name' => 'Query Scope Lens',
        'slug' => 'query-scope-lens',
        'tagline' => 'Read any Eloquent scope at a glance.',
    ]);

    Livewire::test('pages::feed')
        ->assertOk()
        ->assertSee('Query Scope Lens')
        ->assertSee(route('marketplace.show', ['product' => $product->slug]), false);
});

it('opens the sign-in dialog when a guest tries to join', function (): void {
    $this->seed([UserSeeder::class, CommunitySeeder::class]);

    Livewire::test('pages::feed')
        ->assertOk()
        ->call('toggleMembership', 'backend-engineers')
        ->assertRedirect(route('feed.index', ['signin' => 'required']));
});

it('joins an open community and reconciles the rail', function (): void {
    $this->seed([UserSeeder::class, CommunitySeeder::class]);

    $reader = User::factory()->create();

    actingAs($reader);

    Livewire::test('pages::feed')
        ->assertOk()
        ->assertDontSee('data-test="membership-refused"', false)
        ->call('toggleMembership', 'backend-engineers')
        ->assertSet('refusedSlug', null)
        ->assertSee('Joined');

    $community = Community::query()->where('slug', 'backend-engineers')->firstOrFail();

    expect(CommunityMembership::query()
        ->where('community_id', $community->id)
        ->where('user_id', $reader->id)
        ->exists())->toBeTrue();
});

it('leaves a community the reader already belongs to', function (): void {
    $this->seed([UserSeeder::class, CommunitySeeder::class]);

    $reader = User::factory()->create();

    actingAs($reader);

    $component = Livewire::test('pages::feed');

    $component->call('toggleMembership', 'backend-engineers')->assertSet('refusedSlug', null);
    $component->call('toggleMembership', 'backend-engineers');

    $community = Community::query()->where('slug', 'backend-engineers')->firstOrFail();

    expect(CommunityMembership::query()
        ->where('community_id', $community->id)
        ->where('user_id', $reader->id)
        ->exists())->toBeFalse();
});

it('refuses joining an invite-only community at the widget', function (): void {
    $this->seed([UserSeeder::class, CommunitySeeder::class]);

    // The rail pins the three busiest communities; promote one of them to
    // invite-only so the visible button and its refusal land on one card.
    Community::query()
        ->where('slug', 'backend-engineers')
        ->update(['kind' => CommunityKind::InviteOnly]);

    $reader = User::factory()->create();

    actingAs($reader);

    Livewire::test('pages::feed')
        ->assertOk()
        ->assertSee('Backend Engineers')
        ->call('toggleMembership', 'backend-engineers')
        ->assertSet('refusedSlug', 'backend-engineers')
        ->assertSee('data-test="membership-refused"', false)
        ->assertSee('Invite only', false);

    $community = Community::query()->where('slug', 'backend-engineers')->firstOrFail();

    expect(CommunityMembership::query()
        ->where('community_id', $community->id)
        ->where('user_id', $reader->id)
        ->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| App shell — the public header, the composer trigger and the filters
|--------------------------------------------------------------------------
*/

it('wears the public guest shell instead of a bespoke app header', function (): void {
    // The feed no longer opts out of the default layout, so it renders inside
    // `layouts::guest.home`: the site's own header supplies the navigation,
    // the theme and accent controls and the guest sign-in trigger, and the
    // app header the feed used to draw itself (search field, Create button)
    // is gone for good.
    $this->get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="sign-in-trigger"', false)
        ->assertSee('data-test="feed-index"', false)
        // The bespoke header's controls must not come back with it.
        ->assertDontSee('data-test="feed-search"', false)
        ->assertDontSee('data-test="feed-create"', false);
});

it('keeps the composer openable without the shell header', function (): void {
    Livewire::test('pages::feed')
        ->assertOk()
        // The composer carries its own affordance in the stream …
        ->assertSee("What's on your mind?")
        // … and still listens for the window event, so a trigger anywhere on
        // the page opens it — including after a re-render.
        ->assertSee('x-on:focus-composer.window', false);
});

it('offers the signed-in reader the shell dashboard link', function (): void {
    $reader = User::factory()->create();
    actingAs($reader);

    $this->get(route('feed.index'))
        ->assertOk()
        ->assertSee(route($reader->dashboardRoute()), false)
        ->assertSee('Dashboard');
});

it('links the rail to the real communities directory with its live count', function (): void {
    $this->seed(CommunitySeeder::class);

    Livewire::test('pages::feed')
        ->assertOk()
        ->assertSee('All communities')
        ->assertSee(route('communities.index'), false)
        // The directory's own total on the section badge — the six seeded
        // circles, never the reference's screenshot thirteen.
        ->assertSee('tabular-nums">6<', false);
});

it('serves the communities directory the rail links to', function (): void {
    $this->seed(CommunitySeeder::class);

    get(route('communities.index'))
        ->assertOk()
        ->assertSee('Find your people');
});

it('filters the feed to discussions, counting them in the rail', function (): void {
    Livewire::withQueryParams(['type' => 'discussion'])
        ->test('pages::feed')
        ->assertOk()
        // Three discussions in the corpus; this title belongs to one.
        ->assertSee('Hot take: most design systems')
        ->assertSee('3 matching posts')
        ->assertSee('Discussions')
        // An answered question's title lives only in the stream: its author is
        // outside the top-four contributors, so its absence is the filter's
        // work. (The unanswered question is exempt — "Needs answers" is a
        // type-independent widget by design.)
        ->assertDontSee('Tailwind v4 dark mode');
});

/*
|--------------------------------------------------------------------------
| Theme contract — the feed wears the editorial palette (docs/themes.md §5)
|--------------------------------------------------------------------------
*/

it('keeps the feed bridge a pure alias layer over the editorial tokens', function (): void {
    $css = File::get(resource_path('css/app.css'));

    // The retired blue-charcoal bespoke palette must not creep back: the
    // canvas, surface, azure, mint, and light-canvas literals of the old
    // --color-feed-* bridge.
    foreach (['#080d12', '#0d141b', '#11181f', '#1683FF', '#00D5A0', '#F2F6FA'] as $hex) {
        expect($css)->not->toMatch('/'.preg_quote($hex, '/').'/i');
    }

    // Every feed role is a var() reference — a re-scoping editorial token or
    // a static ink/signal tone — never a raw value.
    preg_match_all('/--color-feed-[a-z0-9-]*:\s*([^;]+);/i', $css, $declarations);

    expect($declarations[1])->not->toBeEmpty();

    foreach ($declarations[1] as $value) {
        expect(trim($value))->toStartWith('var(');
    }
});

it('keeps the always-dark panels on static tokens with one documented red', function (): void {
    $css = File::get(resource_path('css/feed.css'));

    // docs/themes.md §7: surfaces dark in *both* themes may carry fixed
    // colours, but they are still tokens. The only literal hue in the file is
    // the diff's delete-side red — a red-400 tint that must not brighten with
    // the theme, matching the semantic danger token's dark value.
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $hexes);

    expect($hexes[0])->toBe(['#f87171']);

    // The blue-tinted bespoke panel literals of the old design are gone.
    foreach (['#090E13', '#0F161D', '#1B2430', '#2A3644'] as $hex) {
        expect($css)->not->toMatch('/'.preg_quote($hex, '/').'/i');
    }
});

/*
|--------------------------------------------------------------------------
| Accessibility — the landmarks and hooks in the server-rendered markup
|--------------------------------------------------------------------------
*/

it('carries the skip link, the main landmark, and the labelled cover', function (): void {
    $html = get(route('feed.index'))->getContent();

    // The skip link is the first anchor on the page and points at the landmark.
    preg_match('/<a\b[^>]*href="([^"]*)"/', $html, $firstAnchor);

    expect($firstAnchor[1] ?? null)->toBe('#main')
        ->and($html)->toContain('<main id="main">')
        // The cover section names its own heading instead of a bare aria-label.
        ->toContain('aria-labelledby="feed-cover-heading"')
        ->toContain('id="feed-cover-heading"');
});

it('marks the active tab and wires the more menu and the live region', function (): void {
    $html = get(route('feed.index'))->getContent();

    expect($html)
        ->toContain('class="feed-tab" aria-current="page"')
        ->toContain('role="menu"')
        ->toContain('role="menuitem"')
        ->toContain('aria-haspopup="menu"')
        ->toContain('x-bind:aria-expanded')
        ->toContain('aria-live="polite"');
});

it('renders no image without a non-empty alt attribute', function (): void {
    $html = get(route('feed.index'))->getContent();

    // The surface draws its avatars as initials grids and its icons as
    // aria-hidden SVGs, so any <img> that appears (post media) must carry a
    // non-empty alt; today the stream supplies none at all, and an empty set
    // trivially satisfies the rule.
    preg_match_all('/<img\b[^>]*>/i', $html, $imgs);

    $withoutAlt = array_filter(
        $imgs[0],
        fn (string $img): bool => ! preg_match('/alt="[^"]+"/', $img),
    );

    expect($html)->toContain('data-test="feed-index"')
        ->and($withoutAlt)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Responsive contract — the breakpoint grid tracks in the rendered markup
|--------------------------------------------------------------------------
*
* Honest scope: viewport-level verification needs Laravel Dusk, which is not
* installed here. These are markup-contract assertions — they pin that the
* classes the breakpoints key on actually ship in the HTML.
*/

it('ships the responsive grid tracks and the mobile-first stream order', function (): void {
    $html = get(route('feed.index'))->getContent();

    foreach ([
        'lg:grid-cols-[minmax(0,1fr)_20rem]',
        'xl:grid-cols-[14rem_minmax(0,1fr)_20rem]',
        'order-1 min-w-0 lg:order-none',
        'max-w-[1920px]',
    ] as $contract) {
        expect($html)->toContain($contract);
    }
});

it('keeps wide surfaces scrollable and cover type fluid', function (): void {
    $html = get(route('feed.index'))->getContent();

    // The tab rail and the rails scroll instead of breaking the layout.
    expect($html)->toContain('overflow-x-auto');

    // The cover heading sizes with the viewport via clamp().
    expect($html)->toMatch('/<h1[^>]*text-\[clamp\(/');

    // The thread page carries the same fluid heading and the scrollable diff.
    $thread = get(route('feed.show', ['slug' => 'rust-async-stream-processing']))->getContent();

    expect($thread)
        ->toMatch('/<h1[^>]*text-\[clamp\(/')
        ->toContain('overflow-hidden rounded-lg border border-feed-line-strong');
});

/*
|--------------------------------------------------------------------------
| RSS invalidation — the cached feed.xml breathes with the corpus
|--------------------------------------------------------------------------
*/

it('evicts the cached rss when a post is published', function (): void {
    get(route('feed'))->assertOk()->assertDontSee('A brand new writing post');

    Post::factory()->published()->create(['title' => 'A brand new writing post']);

    get(route('feed'))->assertOk()->assertSee('A brand new writing post');
});

it('evicts the cached rss when a post is deleted and restored', function (): void {
    $post = Post::factory()->published()->create(['title' => 'A temporary writing post']);

    get(route('feed'))->assertOk()->assertSee('A temporary writing post');

    $post->delete();

    get(route('feed'))->assertOk()->assertDontSee('A temporary writing post');

    $post->restore();

    get(route('feed'))->assertOk()->assertSee('A temporary writing post');
});

it('evicts the cached rss when a post reverts to draft', function (): void {
    $post = Post::factory()->published()->create(['title' => 'A post pulled back']);

    get(route('feed'))->assertOk()->assertSee('A post pulled back');

    // Direct status write — the observer's saved hook is the eviction point.
    $post->forceFill(['status' => PostStatus::Draft])->save();

    get(route('feed'))->assertOk()->assertDontSee('A post pulled back');
});

/*
|--------------------------------------------------------------------------
| Design consistency — nothing invented, nothing duplicated, nothing lying
|--------------------------------------------------------------------------
*
* The feed's own rule is that every figure on the page is derived from the
* corpus. These pins keep the view layer honest: no fabricated telemetry, one
* home for the totals, and bounty links that only point at threads that
* actually resolve.
*/

it('ships no fabricated telemetry anywhere on the feed', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertDontSee('99.99%')
        ->assertDontSee('operational');

    // The trending rail used to invent a growth percentage per tag.
    $feed = File::get(base_path('resources/views/pages/⚡feed.blade.php'));

    expect($feed)
        ->not->toContain('Demo telemetry')
        ->not->toContain("'delta'");
});

it('gives the corpus totals a single home on the page', function (): void {
    $html = get(route('feed.index'))->getContent();

    // Posts / Solved / Unanswered / People appeared three times; the
    // inspector's profile overview is now the only one.
    expect(substr_count($html, 'People'))->toBe(1);
});

it('links a bounty with authored detail and routes the rest to the unanswered queue', function (): void {
    $action = "\$set('sort', 'unanswered')";

    FeedPost::query()->create([
        'slug' => 'bounty-with-authored-detail',
        'title' => 'Bounty with authored detail',
        'author' => 'Test Author',
        'handle' => '@tester',
        'avatar' => 'TA',
        'posted_at' => now(),
        'topic' => 'backend',
        'type' => 'question',
        'answered' => false,
        'score' => 5000,
        'body' => 'A question that carries its own thread.',
        'tags' => ['Queues'],
        'reactions' => ['like' => 0, 'love' => 0, 'insight' => 0],
        'detail' => [
            'title' => 'Bounty with authored detail',
            'code' => null,
            'diff' => ['filename' => 'app/Queue/Worker.php', 'summary' => 'One line changed.', 'lines' => []],
            'replies' => [],
        ],
    ]);

    $withoutDetail = FeedPost::query()->create([
        'slug' => 'bounty-without-authored-detail',
        'title' => 'Bounty without authored detail',
        'author' => 'Test Author',
        'handle' => '@tester',
        'avatar' => 'TA',
        'posted_at' => now(),
        'topic' => 'backend',
        'type' => 'question',
        'answered' => false,
        'score' => 4000,
        'body' => 'A question with no thread behind it.',
        'tags' => ['Queues'],
        'reactions' => ['like' => 0, 'love' => 0, 'insight' => 0],
    ]);

    $mixed = str(get(route('feed.index'))->getContent())
        ->between('id="bounties-heading"', 'id="reader-heading"')
        ->value();

    // feed.show only resolves slugs that carry detail, so the first bounty is
    // a real link and the second must not be one.
    expect($mixed)
        ->toContain(route('feed.show', ['slug' => 'bounty-with-authored-detail']))
        ->not->toContain(route('feed.show', ['slug' => 'bounty-without-authored-detail']))
        ->toContain('Bounty without authored detail');

    // The linkless bounty hands the reader to the unanswered queue instead —
    // one more queue action than the all-detail render below.
    $mixedActions = substr_count($mixed, $action);

    // Both slots become anchors once the second carries detail too, so the
    // queue action disappears — proving which branch rendered.
    $withoutDetail->forceFill([
        'detail' => [
            'title' => 'Bounty without authored detail',
            'code' => null,
            'diff' => ['filename' => 'app/Queue/Worker.php', 'summary' => 'One line changed.', 'lines' => []],
            'replies' => [],
        ],
    ])->save();

    $both = str(get(route('feed.index'))->getContent())
        ->between('id="bounties-heading"', 'id="reader-heading"')
        ->value();

    expect(substr_count($both, $action))->toBe($mixedActions - 1);
});

it('offers the composer only the content types the feed actually keeps', function (): void {
    $composer = Blade::render('<x-feed.composer initials="AB" />');

    foreach (['Question', 'Discussion', 'Showcase', 'Snippet', 'Article', 'Poll'] as $type) {
        expect($composer)->toContain($type);
    }

    // The chips used to advertise types the corpus never holds.
    expect($composer)
        ->not->toContain('Project')
        ->not->toContain('Code');
});

/*
|--------------------------------------------------------------------------
| Composer entry point — a real trigger for members, the login dialog for guests
|--------------------------------------------------------------------------
*/

it('gives members a real composer trigger on the entry row', function (): void {
    actingAs(User::factory()->create());

    $composer = Blade::render('<x-feed.composer initials="AB" />');

    // Both entry buttons must name the modal they open — a trigger wired to
    // nothing is the "looks right, does nothing" failure the entry point used
    // to ship — and must not fall through to the guest login dialog.
    expect($composer)
        ->toContain('data-test="composer-prompt"')
        ->toContain('data-test="composer-open"')
        ->toContain('feed-composer-modal')
        ->not->toContain("modal('login')");
});

it('sends guests to the login dialog instead of a composer they cannot authorise', function (): void {
    $composer = Blade::render('<x-feed.composer initials="AB" />');

    expect($composer)
        ->toContain('data-test="composer-prompt"')
        ->toContain('data-test="composer-open"')
        ->toContain("modal('login')")
        ->not->toContain('feed-composer-modal');
});

it('marks :label present and honestly disabled in the more-options panel', function (string $key, string $label): void {
    actingAs(User::factory()->create());

    $html = Livewire::test('pages::feed.composer-modal')
        ->call('toggleMore')
        ->assertSee('data-test="composer-more-panel"', false)
        ->html();

    $marker = 'data-test="composer-deferred-'.$key.'"';
    $at = strpos($html, $marker);

    expect($at)->not->toBeFalse();

    // The whole button, attributes before the marker and label spans after it.
    $start = strrpos(substr($html, 0, (int) $at), '<button');
    $end = strpos($html, '</button>', (int) $at);
    $button = substr($html, (int) $start, (int) $end - (int) $start);

    // Present, labelled, disabled, and saying so — the acceptance criterion is
    // that the control cannot lie about what clicking it would do.
    expect($button)
        ->toContain('aria-disabled="true"')
        ->toContain('disabled')
        ->toContain('>'.deferredLabel($label).'<')
        ->toContain('Not available yet');
})->with([
    ['live-video', 'Live Video'],
    ['reel', 'Reel'],
    ['gif', 'GIF'],
    ['video', 'Video'],
    ['tag-people', 'Tag People'],
    ['check-in', 'Check In / Location'],
    ['get-calls', 'Get Calls / Contact CTA'],
    ['collaborator', 'Collaborator'],
    ['business-partnership', 'Business Partnership'],
    ['event', 'Event'],
    ['product-attachment', 'Product / Project Attachment'],
    ['life-update', 'Life Update'],
    ['content-warning', 'Content Warning'],
    ['emoji-picker', 'Emoji Picker'],
    ['formatting', 'Formatting Controls'],
    ['mention-autocomplete', 'Mention / Hashtag Autocomplete'],
    ['link-preview', 'Link Preview Metadata'],
]);

/**
 * Labels pass through __(), which is identity here, but quoting keeps the
 * assertion honest against markup that escapes the value. Named to avoid the
 * global test helper `label()` that the framework itself installs.
 */
function deferredLabel(string $value): string
{
    return e($value);
}

it('ranks the needs-answers bounties by score after the in-memory fold', function (): void {
    // The fold turned bounties() from a query into a projection over the
    // corpus the stream already loaded. The ranking it must keep is score,
    // descending — these two outrank everything the seeder holds, so if the
    // projection drifted to recency the order below would flip.
    FeedPost::factory()->question()->create(['title' => 'Highest-scored open question', 'score' => 1000]);
    FeedPost::factory()->question()->create(['title' => 'Second-highest open question', 'score' => 900]);

    $mixed = str(get(route('feed.index'))->getContent())
        ->between('id="bounties-heading"', 'id="reader-heading"')
        ->value();

    // Bounty rows are buttons, not links: clicking one switches the stream to
    // unanswered, so what a row must carry is its title, its score, and that
    // sort action — nothing more.
    expect($mixed)
        ->toContain('Highest-scored open question')
        ->toContain('Second-highest open question')
        ->toContain("wire:click=\"\$set('sort', 'unanswered')\"");

    $first = strpos($mixed, 'Highest-scored open question');
    $second = strpos($mixed, 'Second-highest open question');

    expect($first)->not->toBeFalse()
        ->and($second)->not->toBeFalse()
        ->and($first)->toBeLessThan((int) $second);
});
