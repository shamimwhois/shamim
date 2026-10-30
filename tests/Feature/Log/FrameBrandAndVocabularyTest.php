<?php

/**
 * The frame's brand and the log's vocabulary.
 *
 * Two things live here because both are string contracts that no type system
 * defends, and both have already broken once:
 *
 * 1. **The brand.** It was the literal `~/feed` — the page's URL standing in for
 *    the owner. It is now the owner's own handle, read from
 *    `portfolio.author.short_name`. The first attempt read
 *    `config('portfolio.short_name')`, which does not exist (the key is nested
 *    under `author`), so `config()` returned null and the rail rendered a bare
 *    `~/` — a silent failure, because an empty string is valid markup. The test
 *    asserts the wordmark is non-empty and equals the config value.
 *
 * 2. **The vocabulary.** The log says *post*, not *commit*. Git's noun is a
 *    metaphor that leaked into the UI: the composer read "Commit", starring a
 *    row read "Star this commit", the profile's empty state read "no commits
 *    yet". Every one of those is a reader-facing string that no compiler checks.
 *    This pins the reader-facing surfaces against the old noun so a future edit
 *    cannot quietly reintroduce it, while leaving the internal names alone —
 *    `deleteCommit`, `commitStateChanged`, the `commits` table and the
 *    `data-test="commit"` hooks are implementation, not copy.
 */
use App\Models\User;

test('the brand is the owner handle, not the feed url', function () {
    $short = config('portfolio.author.short_name');

    expect($short)->toBeString()->not->toBe('');

    $html = $this->get(route('feed.index'))->assertOk()->getContent();

    expect(strpos($html, '>~/'.$short))->not->toBeFalse();
    expect(strpos($html, '~/feed'))->toBeFalse();
});

// The empty-string failure mode: a wrong config path renders `~/` and nothing else.
test('the brand never renders an empty wordmark', function () {
    $html = $this->get(route('feed.index'))->assertOk()->getContent();

    expect(strpos($html, 'dn-wordmark">~/</span>'))->toBeFalse();
    expect(strpos($html, 'dn-wordmark">~<span class="dn-wordmark-accent">/</span></span>'))->toBeFalse();
});

// The wordmark is longer than a nav label, so it needs its own cap large enough
// not to clip. Measured in Chrome at 1440px: the handle is 203.6px, the rail
// gives 232px. A shared 160px cap (the nav-label one) cut it off.
test('the brand text has a cap wide enough for the handle', function () {
    $css = file_get_contents(resource_path('css/log.css'));

    expect(strpos($css, ".dn-shell[data-nav='expanded'] .dn-brand-text"))->not->toBeFalse();
    expect(strpos($css, 'max-width: 232px'))->not->toBeFalse();
});

test('the reader-facing log says post, not commit', function (string $url, array $absent) {
    $reader = User::factory()->create(['handle' => 'reader']);

    $html = $this->actingAs($reader)->get($url)->assertOk()->getContent();

    foreach ($absent as $phrase) {
        expect(strpos($html, $phrase))->toBeFalse("the old noun '{$phrase}' is back on {$url}");
    }
})->with([
    'feed' => ['/feed', ['>Commit<', 'Load older commits', 'no commits yet', 'Star this commit']],
    'notifications' => ['/notifications', ['your commit']],
]);

test('the composer offers to post', function () {
    $reader = User::factory()->create(['handle' => 'poster']);

    $html = $this->actingAs($reader)->get(route('feed.index'))->assertOk()->getContent();

    $posts = strpos($html, 'Posting…') !== false || strpos($html, '>Post<') !== false;

    expect($posts)->toBeTrue('the composer no longer offers to post');
});
