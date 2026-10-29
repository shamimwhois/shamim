<?php

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| About hero
|--------------------------------------------------------------------------
|
| The about hero deliberately ships two portraits — one per theme — so that
| the light and dark compositions each match their own art direction rather
| than asking a single image to survive both backgrounds. These tests pin the
| pair, and the assets behind them, so a refactor that drops to one image (or
| renames the file) fails here instead of on a live page.
|
| The CTA row is pinned for the same reason. Its second pill is a CV download,
| and a hardcoded href there meant "Download CV" silently reloaded the page it
| was already on. The href is configuration, so the pill must appear exactly
| when a real url is configured and must take that url.
|
*/

test('the about page serves both hero portraits', function () {
    $this->get(route('about'))
        ->assertOk()
        ->assertSee('images/hero-portrait-light.jpg', false)
        ->assertSee('images/darkmode.webp', false);
});

test('the hero portraits hide themselves by theme rather than being swapped by script', function () {
    // Each portrait carries a theme-scoped class; exactly one is ever visible.
    // The mechanism is CSS on <html class="dark">, so it holds with JS off.
    $this->get(route('about'))
        ->assertOk()
        ->assertSee('hero-portrait__img--light', false)
        ->assertSee('hero-portrait__img--dark', false);
});

test('the hero portrait assets exist on disk', function () {
    foreach (['hero-portrait-light.jpg', 'darkmode.webp'] as $asset) {
        expect(File::exists(public_path('images/'.$asset)))
            ->toBeTrue("Missing hero portrait [{$asset}]; the about hero renders an empty panel without it.");
    }
});

test('the CV pill is withheld until a real url is configured', function () {
    // Counting the pill's own class rather than the label: the anchor row
    // above also says "Download CV" (it jumps to the work grid), so the label
    // alone cannot tell the two apart. `class="btn-push` is markup-only — the
    // stylesheet uses `.btn-push` — so the bundled CSS cannot inflate it.
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect(substr_count($html, 'class="btn-push'))
        ->toBe(1, 'The CV pill rendered without a CV behind it.');

    expect($html)->not->toContain('href="/about"');
});

test('the CV pill points at the configured url', function () {
    config(['portfolio.author.links.cv.url' => 'https://example.test/shamim-cv.pdf']);

    $html = $this->get(route('about'))->assertOk()->getContent();

    expect(substr_count($html, 'class="btn-push'))
        ->toBe(2, 'The CV pill did not render despite a configured url.');

    expect($html)->toContain('href="https://example.test/shamim-cv.pdf"', false)
        ->and($html)->toContain('Download CV');
});

test('the hero portrait images carry explicit dimensions to avoid layout shift', function () {
    // A portrait without width/height reserves no space, so the hero jumps as
    // each ~400kb image decodes. Both must declare their box.
    $html = $this->get(route('about'))->assertOk()->getContent();

    // Count <img> tags carrying the base class, not every substring hit —
    // the `--light`/`--dark` modifiers repeat the base name and would
    // otherwise double the total.
    expect(substr_count($html, '<img'))
        ->and(substr_count($html, 'class="hero-portrait__img'))->toBe(2)
        ->and($html)->toContain('width="1536"')
        ->and($html)->toContain('height="1024"');
});
