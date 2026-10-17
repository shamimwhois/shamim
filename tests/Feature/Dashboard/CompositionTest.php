<?php

use App\Enums\AccountRole;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

/**
 * The dashboard composition.
 *
 * ShellTest proves the shell renders; this suite proves what the *page* puts
 * inside it. The Creative Tim redesign moved the dashboard's identity into
 * four reusable sections — the gradient welcome card, the toned metric row,
 * the activity chart and the task list — and each of them has a behaviour
 * that can silently rot:
 *
 *   the hero becoming a second `h1` on a page that already has one,
 *   the chart drawing a flat line when the author has published nothing,
 *   the task list inventing work that does not exist,
 *   a metric tile claiming a click it cannot deliver.
 *
 * All four are invisible in a screenshot and obvious in a month of use, so
 * they are pinned here rather than in a review.
 */
function author(array $attributes = []): User
{
    return User::factory()->role(AccountRole::User)->create($attributes);
}

test("the hero is the page's single top-level heading", function () {
    $response = $this->actingAs(author())->get(route('dashboard.index'));

    $response->assertOk();

    preg_match_all('/<h1\b[^>]*>/', $response->getContent(), $matches);

    expect($matches[0])->toHaveCount(1)
        // The heading lives inside the gradient card, which is the only
        // surface in the system allowed to carry a gradient.
        ->and($response->getContent())->toContain('sui-hero');
});

test('the hero greeting uses the account first name and carries the primary action', function () {
    $user = author(['name' => 'Ada Lovelace']);

    $response = $this->actingAs($user)->get(route('dashboard.index'));

    $response->assertOk()
        ->assertSee('Welcome back, Ada', false)
        // The action is a white surface on the dark hero, not a second
        // gradient — and it points at the create screen that exists.
        ->assertSee(route('dashboard.posts.create'), false);
});

test('every metric tile keeps its assigned pastel tone', function () {
    $user = author();
    $html = $this->actingAs($user)->get(route('dashboard.index'))->getContent();

    /* One tile per tone, and the tone is bound to the metric rather than to
       the grid position — so reordering the grid cannot recolour a metric. */
    foreach (['success', 'secondary', 'info', 'danger'] as $tone) {
        expect($html)->toContain("bg-sui-pastel-{$tone}-wash")
            ->and($html)->toContain("text-sui-pastel-{$tone}-ink");
    }
});

test('the moderation tile stays quiet when the queue is clear', function () {
    $user = author();

    Post::factory()->for($user, 'user')->published()->create();

    $html = $this->actingAs($user)->get(route('dashboard.index'))->getContent();

    /* Attention is reserved for a non-zero queue: a clear queue is a quiet
       tile, and "Awaiting moderation" renders in the primary pastel. */
    expect($html)->not->toContain('text-sui-attention-ink')
        ->and($html)->toContain('bg-sui-pastel-primary-wash');
});

test('the moderation tile engages the attention tone only when work is waiting', function () {
    $user = author();
    $post = Post::factory()->for($user, 'user')->published()->create();

    /* Pending is the factory's default, so no state call is needed. */
    Comment::factory()->for($post)->create();

    $html = $this->actingAs($user)->get(route('dashboard.index'))->getContent();

    expect($html)->toContain('text-sui-attention-ink')
        ->and($html)->toContain('border-sui-attention-border');
});

test('the activity chart draws real weekly buckets from published_at', function () {
    $user = author();

    Post::factory()->for($user, 'user')->published()->create(['published_at' => now()->startOfWeek()->subWeek()]);
    Post::factory()->for($user, 'user')->published()->create(['published_at' => now()->startOfWeek()->subWeeks(4)]);
    /* A draft in the same window: it must not move the chart. */
    Post::factory()->for($user, 'user')->create(['published_at' => null]);

    $html = $this->actingAs($user)->get(route('dashboard.index'))->getContent();

    expect($html)
        ->toContain('chartAreaFill')
        ->toContain('stroke="var(--color-sui-accent)"')
        // Twelve weekly labels, thinned to one every other week.
        ->toContain('left: 90.91%')
        // The x-axis names real Mondays, not placeholder text.
        ->not->toContain('Jan 1');
});

test('the chart falls back to an empty state instead of a flat line', function () {
    /* A flat line riding the baseline looks exactly like data and is the
       opposite of it, so a series that sums to zero is not drawn at all. */
    $user = author();

    Post::factory()->for($user, 'user')->create(['title' => 'Only a draft']);

    $html = $this->actingAs($user)->get(route('dashboard.index'))->getContent();

    expect($html)->not->toContain('chartAreaFill')
        ->and($html)->toContain('Nothing published in the last twelve weeks yet.');
});

test('the task list is derived from persisted state and never invents work', function () {
    $user = author();

    Post::factory()->for($user, 'user')->count(2)->create();
    $post = Post::factory()->for($user, 'user')->published()->create();

    Comment::factory()->count(3)->create(['post_id' => $post->id]);

    $html = $this->actingAs($user)->get(route('dashboard.index'))->getContent();

    expect($html)
        ->toContain('Finish 2 draft posts')
        ->and($html)->toContain('Review 3 waiting comments')
        // Each row links to the queue where the work happens, because a
        // checkbox only the database can tick is a control that lies.
        ->and($html)->toContain(route('dashboard.comments'))
        ->and($html)->toContain(route('dashboard.posts.index', ['status' => 'draft']));
});

test('a brand new account is offered the first post rather than an empty list', function () {
    $html = $this->actingAs(author())->get(route('dashboard.index'))->getContent();

    expect($html)->toContain('Write your first post')
        ->and($html)->not->toContain('Every task on this list is done.');
});

test('an author with nothing outstanding sees the quiet task state', function () {
    $user = author();

    Post::factory()->for($user, 'user')->published()->create();

    $html = $this->actingAs($user)->get(route('dashboard.index'))->getContent();

    /* The second line is the task list's own copy — the first is shared with
       the navbar's notification panel, and asserting on it would let this
       test pass on the strength of a different component. */
    expect($html)->toContain('Every task on this list is done.')
        ->and($html)->not->toContain('Finish 1 draft posts')
        ->and($html)->not->toContain('Write your first post');
});

test('a metric tile with no destination is not a link', function () {
    /* Total views is the one tile without a route: advertising a click it
       cannot deliver is worse than a tile that is plainly a surface. The
       hover lift is the marker, because it is emitted only for the tiles
       that render as an anchor. */
    $user = author();

    Post::factory()->for($user, 'user')->published()->create(['view_count' => 1234]);

    $html = $this->actingAs($user)->get(route('dashboard.index'))->getContent();

    expect($html)->toContain('1,234')
        ->and(substr_count($html, 'hover:border-sui-border hover:shadow-soft-2'))->toBe(5);
});

test('no interpolated class placeholder survives into the markup', function () {
    /* The bug this pins: Laravel's ComponentTagCompiler rewrites the double
       quotes of a component-tag `@class` argument to single quotes, and a
       single-quoted PHP string does not interpolate. So
           @class([... "text-sui-pastel-{$tone}-ink" => ...])
       rendered as the literal class name `text-sui-pastel-{$tone}-ink`,
       which matches no CSS rule at all. The sidenav icons silently lost
       their pastel ink with no error anywhere. An unresolved `{$` in the
       output is the tell, and it can only come from this mistake. */
    $html = $this->actingAs(author())->get(route('dashboard.index'))->getContent();

    expect($html)->not->toContain('{$');
});

test('the sidenav icons actually carry their pastel ink', function () {
    /* Six tones, and the compact rail must agree with the expanded one — an
       item that changes colour when the sidenav narrows is a bug. */
    $user = author();
    Post::factory()->for($user, 'user')->published()->create();

    $html = $this->actingAs($user)->get(route('dashboard.index'))->getContent();

    expect($html)->toContain('text-sui-pastel-primary-ink')
        ->and($html)->toContain('text-sui-pastel-info-ink')
        ->and($html)->toContain('text-sui-pastel-success-ink');
});

test('every pastel utility the markup asks for exists in the built css', function () {
    /* The complement of the test above: the page can name a tone the
       stylesheet never emitted. Tailwind v4 scans source files, so a class
       assembled by string interpolation is invisible to it and the tile
       renders with no wash at all. Reading the built manifest — the .css
       the browser actually receives — is the only place this shows up. */
    $html = $this->actingAs(author())->get(route('dashboard.index'))->getContent();

    preg_match_all('/class="([^"]*)"/', $html, $matches);

    $used = collect($matches[1])
        ->flatMap(fn (string $class) => preg_split('/\s+/', $class) ?: [])
        ->filter(fn (string $class) => str_contains($class, 'sui-pastel'))
        ->unique()
        ->values();

    expect($used)->not->toBeEmpty();

    $manifest = collect(glob(public_path('build/assets/app-*.css')) ?: [])
        ->sortByDesc(fn (string $path) => filemtime($path))
        ->first();

    expect($manifest)->not->toBeNull();

    $css = file_get_contents($manifest);

    /* `toContain` is variadic over needles, not (needle, message) — a second
       argument here would be read as another string to look for. So the
       missing set is computed and asserted as a list, which also names every
       offender in one failure rather than one per run. */
    $missing = $used
        ->reject(fn (string $class) => str_contains($css, '.'.$class.'{'))
        ->values();

    $this->assertSame([], $missing->all(), sprintf(
        'The dashboard renders pastel classes the built CSS never emitted: %s',
        $missing->implode(', '),
    ));
});
