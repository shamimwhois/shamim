<?php

use App\Models\FeedPost;
use App\Models\User;
use Database\Seeders\FeedPostSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * The admin feed moderation console — persisted curation over the corpus.
 *
 * Every decision is a column on {@see FeedPost}, so these tests assert the
 * database state and the effect the decision has on the public surface,
 * rather than a session payload.
 */
beforeEach(function (): void {
    $this->seed(FeedPostSeeder::class);
});

it('lets an admin open the moderation console', function (): void {
    actingAs(User::factory()->admin()->create())
        ->get(route('admin.feed'))
        ->assertOk()
        ->assertSee('Posts in corpus')
        ->assertSee('Feed moderation');
});

it('forbids non-admins from the moderation console', function (): void {
    actingAs(User::factory()->create())
        ->get(route('admin.feed'))
        ->assertForbidden();
});

it('persists a moderation flag on the post', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.feed')
        ->assertOk()
        ->call('toggle', 1, 'pinned');

    expect(FeedPost::query()->find(1)->pinned)->toBeTrue();
});

it('hides a post from the public feed until it is restored', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.feed')->call('toggle', 1, 'hidden');

    expect(FeedPost::query()->find(1)->hidden)->toBeTrue();

    Livewire::test('pages::feed')->assertDontSee('pulled the N+1 out of the dashboard post list');

    Livewire::test('pages::admin.feed')->call('toggle', 1, 'hidden');

    expect(FeedPost::query()->find(1)->hidden)->toBeFalse();

    Livewire::test('pages::feed')->assertSee('pulled the N+1 out of the dashboard post list');
});

it('rejects flags outside the moderation whitelist', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.feed')->call('toggle', 1, 'score');

    // A crafted flag name writes nothing — the action aborts, and the post
    // keeps the seeded values.
    expect(FeedPost::query()->find(1)->score)->toBe(210)
        ->and(FeedPost::query()->find(1)->pinned)->toBeFalse();
});

it('rejects solving a post that is not a question', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.feed')->call('toggle', 1, 'solved');

    // The showcase post is unchanged: solving it would put a "Solved" badge
    // on a thread that never asked a question.
    expect(FeedPost::query()->find(1)->answered)->toBeTrue();
});

it('solves an unanswered question', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.feed')->call('toggle', 4, 'solved');

    expect(FeedPost::query()->find(4)->answered)->toBeTrue();
});

it('restores every post to the seeded moderation baseline', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.feed')->call('toggle', 1, 'pinned');
    Livewire::test('pages::admin.feed')->call('toggle', 1, 'hidden');

    expect(FeedPost::query()->find(1)->pinned)->toBeTrue()
        ->and(FeedPost::query()->find(1)->hidden)->toBeTrue();

    Livewire::test('pages::admin.feed')->call('restoreDefaults');

    expect(FeedPost::query()->find(1)->pinned)->toBeFalse()
        ->and(FeedPost::query()->find(1)->hidden)->toBeFalse()
        ->and(FeedPost::query()->where('pinned', true)->value('slug'))->toBe('rust-async-stream-processing');
});

it('filters the queue by tab and free text', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.feed')
        ->assertSee('Shamim Hossain')
        ->set('tab', 'curated')
        ->assertDontSee('Shamim Hossain')
        ->assertSee('Rayhan Kabir')
        ->set('tab', 'all')
        ->set('q', 'nadia')
        ->assertSee('Nadia Petrova')
        ->assertDontSee('Shamim Hossain');
});

it('refuses the console to an account without admin:access at the endpoint', function (): void {
    // The route never sees a Livewire request, so on `/livewire/update` the
    // only thing between an ordinary account and the community corpus is the
    // component's own `boot()` guard. This is the test that fails if that
    // guard is deleted while every test above stays green.
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.feed')
        ->assertForbidden();

    expect(FeedPost::query()->where('pinned', true)->pluck('slug')->all())
        ->toBe(['rust-async-stream-processing']);
});

it('refuses a write to an account whose permission was withdrawn mid-session', function (): void {
    // Open the console as an admin, then swap the authenticated account before
    // the action runs. The page guard has already passed on the mount; the
    // per-action check is what stops this call, and it is what would still stop
    // it if the route ever stopped carrying `permission:admin:access`.
    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.feed');

    actingAs(User::factory()->create());

    $component->call('toggle', 1, 'pinned')->assertForbidden();

    expect(FeedPost::query()->find(1)->pinned)->toBeFalse();
});

it('refuses the bulk restore to an account without admin:access', function (): void {
    // Open as an admin and make a deliberate deviation from the seeded
    // baseline. `restoreDefaults()` writes five columns on every row, so the
    // only way to prove the refused call did nothing is to show that the
    // deviation it would have erased is still there afterwards.
    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.feed')
        ->call('toggle', 1, 'hidden');

    expect(FeedPost::query()->find(1)->hidden)->toBeTrue();

    actingAs(User::factory()->create());

    $component->call('restoreDefaults')->assertForbidden();

    expect(FeedPost::query()->find(1)->hidden)->toBeTrue();
});
