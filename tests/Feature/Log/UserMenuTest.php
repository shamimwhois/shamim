<?php

use App\Models\User;
use Database\Seeders\FeedPostSeeder;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * The account menu.
 *
 * The reference draws this as a React dropdown on the rail's user chip. Here it
 * is a Blade component rendered once at the end of the shell, with the rail chip
 * and the phone's header avatar both dispatching the same event, so these tests
 * are about three things: that the menu exists on every framed surface, that it
 * only offers rows this application can actually answer, and that it degrades to
 * a plain profile link when the script does not run.
 */
beforeEach(function (): void {
    $this->seed(FeedPostSeeder::class);
    $this->reader = User::factory()->create(['handle' => 'reader', 'name' => 'Reader One']);
});

it('offers a guest the sign-in button instead of an account menu', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="sign-in-trigger"', false)
        ->assertDontSee('data-test="user-menu"', false)
        ->assertDontSee('data-test="user-menu-trigger"', false);
});

it('hangs the menu off every surface that wears the frame', function (string $route): void {
    actingAs($this->reader);

    get(route($route))
        ->assertOk()
        ->assertSee('data-test="user-menu"', false)
        // The rail chip and the phone's header avatar are separate triggers:
        // the nav is `display: none` on a phone, so the chip alone would leave
        // a handset with no way into the menu.
        ->assertSee('data-test="user-menu-trigger"', false)
        ->assertSee('data-test="user-menu-trigger-mobile"', false);
})->with(['feed.index', 'starred.index', 'notifications.index', 'remotes.index']);

it('lists only the rows this application can answer', function (): void {
    actingAs($this->reader);

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="user-menu-starred"', false)
        ->assertSee('data-test="user-menu-profile"', false)
        ->assertSee('data-test="user-menu-settings"', false)
        ->assertSee('data-test="user-menu-about"', false)
        ->assertSee('data-test="user-menu-github"', false);
});

it('prints the reader in the menu and links that block to their profile', function (): void {
    actingAs($this->reader);

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('dn-user-menu-id', false)
        ->assertSee('Reader One')
        ->assertSee('@reader');
});

it('offers the admin workspace only to an account that can reach it', function (): void {
    actingAs($this->reader);

    get(route('feed.index'))
        ->assertOk()
        ->assertDontSee('data-test="user-menu-dashboard"', false);

    actingAs(User::factory()->admin()->create(['handle' => 'chief', 'name' => 'Chief']));

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="user-menu-dashboard"', false)
        ->assertSee(route('admin.dashboard'), false);
});

it('tags a role that is not the default one', function (): void {
    actingAs(User::factory()->admin()->create(['handle' => 'chief', 'name' => 'Chief']));

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('dn-user-menu-role', false)
        ->assertSee('Admin');

    actingAs($this->reader);

    get(route('feed.index'))
        ->assertOk()
        ->assertDontSee('dn-user-menu-role', false);
});

it('sends the GitHub row off-site in a new tab', function (): void {
    actingAs($this->reader);

    get(route('feed.index'))
        ->assertOk()
        ->assertSee(config('portfolio.author.links.github.url'), false)
        ->assertSee('rel="noopener noreferrer"', false);
});

/*
 * The reference's Terms/Privacy rows have no pages behind them here. The
 * component asks `Route::has()`, so the assertion follows the router rather than
 * a hard-coded expectation: the day those pages ship, this test keeps passing
 * because the row appears with them.
 */
it('prints a legal row only when the route behind it exists', function (string $name): void {
    actingAs($this->reader);

    $response = get(route('feed.index'))->assertOk();

    Route::has($name)
        ? $response->assertSee('data-test="user-menu-'.$name.'"', false)
        : $response->assertDontSee('data-test="user-menu-'.$name.'"', false);
})->with(['terms', 'privacy']);

it('logs out with a POST rather than a link', function (): void {
    actingAs($this->reader);

    $html = get(route('feed.index'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-test="logout-button"')
        ->toContain('action="'.route('logout').'"')
        ->toContain('method="POST"');

    post(route('logout'))->assertRedirect();

    $this->assertGuest();
});

it('keeps the chip a working profile link so the menu survives without a script', function (): void {
    actingAs($this->reader);

    $html = get(route('feed.index'))->assertOk()->getContent();

    $profile = route('profile.show', ['handle' => 'reader']);

    // The trigger is an `<a href>` that Alpine intercepts; with the script off
    // it is still the link it looks like.
    expect($html)->toMatch(
        '/<a\b[^>]*href="'.preg_quote($profile, '/').'"[^>]*data-test="user-menu-trigger"/',
    );
});
