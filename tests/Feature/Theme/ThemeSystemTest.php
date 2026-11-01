<?php

use App\Enums\AccountRole;
use App\Models\User;
use Database\Seeders\FeedPostSeeder;

/**
 * The theme system contract.
 *
 * Theming in this application has exactly one job that can rot silently:
 * the wiring. The CSS is inert without it — a page that renders without
 * the boot script paints light for a dark-default product; a surface whose
 * chrome forgot the toggle strands a visitor who chose light. Both
 * failures are invisible in the diff that causes them and obvious the
 * moment someone actually uses the product, so they are pinned here.
 *
 * The invariant every surface shares is the one AuthLayoutTest established
 * for the auth layout: no server-rendered `class="dark"`. Dark being the
 * default is a decision the *theme controller* makes at runtime; the
 * moment Blade writes the class itself, a visitor who chose light is
 * overridden, which is the exact bug the auth redesign fixed.
 */
function themedAccount(AccountRole $role): User
{
    return User::factory()->role($role)->create();
}

test('the theme controller boots dark-first on every surface', function () {
    $this->seed(FeedPostSeeder::class);

    $surfaces = [
        // The token-bearing reset screen stands in for the auth layout: login,
        // register and forgot-password are modal-first redirects now.
        'auth' => $this->get(route('password.reset', ['token' => 'theme-check'])),
        'public' => $this->get(route('home')),
        'dashboard' => $this->actingAs(themedAccount(AccountRole::User))->get(route('dashboard.index')),
        // The feed shell composes the same partials.head, so it boots through
        // the same controller — index for the shell, show for the thread page.
        'feed' => $this->get(route('feed.index')),
        'feed thread' => $this->get(route('feed.show', ['slug' => 'rust-async-stream-processing'])),
    ];

    foreach ($surfaces as $surface => $response) {
        $response->assertOk();

        $html = $response->getContent();

        expect($html, "The {$surface} surface")
            ->toContain('flux.appearance')
            ->toContain('DevFolioTheme')
            ->toContain('name="theme-color"');
    }
});

test('no surface renders a server-applied dark class', function () {
    $this->seed(FeedPostSeeder::class);

    $surfaces = [
        'auth' => $this->get(route('password.reset', ['token' => 'theme-check'])),
        'public' => $this->get(route('home')),
        'dashboard' => $this->actingAs(themedAccount(AccountRole::User))->get(route('dashboard.index')),
        'feed' => $this->get(route('feed.index')),
        'feed thread' => $this->get(route('feed.show', ['slug' => 'rust-async-stream-processing'])),
    ];

    foreach ($surfaces as $surface => $response) {
        expect($response->getContent(), "The {$surface} surface")
            ->not->toContain('<html lang="en" class="dark"')
            ->not->toContain('class="dark"');
    }
});

test('the boot script runs after the appearance controller it amends', function () {
    $html = $this->get(route('password.reset', ['token' => 'theme-check']))->getContent();

    $controller = mb_strpos($html, 'applyAppearance');
    $boot = mb_strpos($html, 'DevFolioTheme');

    expect($controller)->not->toBeFalse()
        ->and($boot)->not->toBeFalse()
        ->and($controller)->toBeLessThan($boot);
});

test('every workspace exposes the theme toggle', function () {
    $surfaces = [
        'author' => $this->actingAs(themedAccount(AccountRole::User))->get(route('dashboard.index')),
        'seller' => $this->actingAs(themedAccount(AccountRole::Seller))->get(route('seller.dashboard')),
        'admin' => $this->actingAs(themedAccount(AccountRole::Admin))->get(route('admin.dashboard')),
    ];

    /* The three role layouts compose the same shared navbar, which is why
       one component serves all of them — and why a regression here means
       the toggle disappeared from every workspace at once. */
    foreach ($surfaces as $surface => $response) {
        expect($response->getContent(), "The {$surface} workspace")
            ->toContain('data-test="theme-toggle"');
    }
});

test('the public site, the auth screens, and the feed expose the theme toggle', function () {
    $this->seed(FeedPostSeeder::class);

    expect($this->get(route('home'))->getContent())
        ->toContain('data-test="theme-toggle"')
        ->and($this->get(route('password.reset', ['token' => 'theme-check']))->getContent())
        ->toContain('data-test="theme-toggle"')
        ->and($this->get(route('feed.index'))->getContent())
        ->toContain('data-test="theme-toggle"');
});

test('the public header exposes the accent picker beside the theme toggle', function () {
    $html = $this->get(route('home'))->getContent();

    $toggle = mb_strpos($html, 'data-test="theme-toggle"');
    $picker = mb_strpos($html, 'data-test="accent-picker-trigger"');

    /* The picker mirrors the reference nav: it sits directly after the
       theme toggle, so the two appearance controls read as one group. */
    expect($toggle)->not->toBeFalse()
        ->and($picker)->not->toBeFalse()
        ->and(abs((int) $picker - (int) $toggle))->toBeLessThan(3000);

    foreach (['emerald', 'indigo', 'cyan', 'amber', 'rose', 'violet'] as $hue) {
        expect($html)->toContain('data-accent-option="'.$hue.'"');
    }
});

test('the boot script restores the chosen accent before paint', function () {
    $html = $this->get(route('home'))->getContent();

    expect($html)
        ->toContain('devfolio.accent')
        ->toContain('data-accent')
        ->toContain('DevFolioTheme.accent');
});
