<?php

/**
 * The feed, rendered for a reader who asked for no motion.
 *
 * Plan §9 promises `prefers-reduced-motion` is honoured. A stylesheet rule is
 * not testable from PHP: `ThemeSystemTest` can assert that the class names are
 * there, but only a browser that reports the preference can assert that the
 * animation actually stopped.
 *
 * This file exists on its own rather than as a third test inside
 * `ComposerPublishTest` because of how Dusk hands out browsers. `browse()` reuses
 * the first browser while any is open and `ProvidesBrowser::tearDownDuskClass()`
 * closes them at the end of each class, so Chrome is launched once per file. The
 * launch flags — including `--force-prefers-reduced-motion` — are fixed when the
 * driver is created, so only the class that owns the first `browse()` can ask for
 * a browser that reports `reduce`. Nothing else can, and pretending otherwise
 * would make this test pass for the wrong reason.
 *
 * The three assertions are load-bearing together, which is the point:
 *
 *   1. the browser really does report `reduce`, so a passing test below cannot
 *      be an OS preference accidentally doing the work;
 *   2. `feed.css` really is applied on this page, proven by a rule the motion
 *      query does not touch;
 *   3. the dot that `feed.css` animates computes to no animation.
 *
 * Without (2), (3) would be satisfied by a feed page whose stylesheet never
 * loaded — `animation-name` is `none` by default. With it, the only rule that can
 * produce `none` is `feed.css`'s own `@media (prefers-reduced-motion: reduce)`
 * guard, because the app-wide clamp in `app.css` shortens `animation-duration`
 * and leaves the name alone.
 */

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

it('stops the feed motion for a reader who prefers reduced motion', function () {
    // Set before the first browse() of this class, which is the only moment the
    // Chrome instance has not already been launched.
    /** @var DuskTestCase $this The binding `tests/Pest.php` gives every file in `Browser`. */
    $this->extraChromeFlags = ['--force-prefers-reduced-motion'];

    /** @var User $author */
    $author = User::query()->where('email', 'shamimstack@gmail.com')->firstOrFail();

    $this->browse(function (Browser $browser) use ($author) {
        $browser
            ->loginAs($author)
            ->visit('/feed')
            ->waitFor('[data-test="feed-card"]')
            ->waitFor('.feed-live-dot')
            ->assertScript('matchMedia("(prefers-reduced-motion: reduce)").matches', true)
            // The control: `.feed-card` is bordered by feed.css and nothing in a
            // motion query. Tailwind's preflight leaves the width at 0, so a real
            // 1px here means the stylesheet is loaded and applied.
            //
            // Each multi-statement check is an IIFE, because `assertScript()`
            // prefixes the expression with `return` — a bare `var` at the front of
            // the string would be a syntax error before it ever ran.
            ->assertScript('(function () {'
                .'var s = getComputedStyle(document.querySelector("[data-test=feed-card]"));'
                .'return s.borderTopWidth === "1px" && s.borderTopStyle === "solid";'
            .'})()')
            ->assertScript(
                'getComputedStyle(document.querySelector(".feed-live-dot")).animationName', 'none'
            )
            // The blanket clamp, measured on the same card so the reader's own
            // theme transitions are included. Parsed rather than string-matched:
            // Chrome is free to serialise 0.01ms as either milliseconds or
            // seconds, and a test that breaks on that is testing the browser.
            ->assertScript('(function () {'
                .'var d = getComputedStyle(document.querySelector("[data-test=feed-card]")).transitionDuration.split(",");'
                .'return d.every(function (v) {'
                .'var ms = v.trim().endsWith("ms") ? parseFloat(v) : parseFloat(v) * 1000;'
                .'return ms <= 1;'
                .'});'
            .'})()')
            ->screenshot('feed-reduced-motion');
    });
});
