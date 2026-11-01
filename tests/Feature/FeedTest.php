<?php

use App\Models\FeedPost;
use App\Models\User;
use Database\Seeders\FeedPostSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * The log's shell — the frame the /feed surface lives in.
 *
 * The surface's own behaviour (logs, stars, replies, commits) is covered in
 * `tests/Feature/Feed/LogSurfaceTest.php`. This file asserts the frame: that the
 * log draws its own application shell rather than the marketing one, that the
 * navigation is real, and that the page keeps the accessibility contracts every
 * public surface in this codebase owes.
 */
beforeEach(function (): void {
    $this->seed(FeedPostSeeder::class);
});

it('wears its own application shell, not the marketing one', function (): void {
    $response = get(route('feed.index'))->assertOk();

    // The public shell's header and footer are absent: the log's navigation is
    // the page's navigation.
    $response->assertDontSee('data-test="site-header"', false)
        ->assertDontSee('data-test="site-footer"', false);

    $response->assertSee('data-test="log"', false)
        ->assertSee('id="main"', false);
});

it('keeps the skip link as the first tab stop', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertSee('href="#main"', false)
        ->assertSee('Skip to content');
});

it('draws one h1 and it names the page', function (): void {
    $html = (string) get(route('feed.index'))->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('The Log');
});

it('links the shell to the four public surfaces and marks the current one', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertSee(route('communities.index'), false)
        ->assertSee(route('blog.index'), false)
        ->assertSee(route('marketplace.index'), false)
        ->assertSee(route('about'), false)
        ->assertSee('aria-current="page"', false);
});

it('renders no image without a non-empty alt attribute', function (): void {
    $html = (string) get(route('feed.index'))->assertOk()->getContent();

    preg_match_all('/<img\b[^>]*>/i', $html, $images);

    foreach ($images[0] as $image) {
        expect($image)->toMatch('/\balt="[^"]+"/i');
    }
});

it('escapes markup typed into a commit body', function (): void {
    $post = FeedPost::query()->visible()->firstOrFail();
    $post->forceFill(['body' => '<img src=x onerror="alert(1)"> shipped'])->save();

    $html = (string) get(route('feed.index'))->assertOk()->getContent();

    expect($html)->not->toContain('onerror="alert(1)"')
        ->and($html)->toContain('&lt;img src=x');
});

it('offers the older commits only while some are left', function (): void {
    expect(Livewire::test('pages::feed')->instance()->hasMore)->toBeFalse();

    FeedPost::factory()->count(20)->create();

    expect(Livewire::test('pages::feed')->instance()->hasMore)->toBeTrue();
});

it('gives a signed-in reader their own row in the navigation', function (): void {
    $reader = User::factory()->create(['name' => 'Md Shamim Hossain']);

    actingAs($reader);

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('Md Shamim Hossain')
        ->assertSee('data-test="composer"', false);
});
