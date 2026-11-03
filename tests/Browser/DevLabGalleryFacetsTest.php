<?php

/**
 * A facet you can send somebody.
 *
 * The gallery's filters are `#[Url]` properties, which means a click is supposed
 * to leave the address bar holding the state, and a link built from that address
 * bar is supposed to reproduce it. Both halves are invisible to a feature test:
 * `Livewire::test()` can assert that `$language` changed, and it can assert that a
 * GET request with `?lang=` comes back filtered, but the thing in between — that
 * the page the reader is looking at rewrote its own URL rather than navigating
 * away and losing the scroll position, and that the chip they clicked is the chip
 * a fresh load marks as selected — only exists in a tab.
 *
 * The reload is the part worth pinning. A facet that renders from component state
 * but writes its URL from somewhere else will look correct until somebody presses
 * refresh or sends the link to a colleague.
 */

use Laravel\Dusk\Browser;

it('writes the chosen language into the address bar and reads it back on reload', function () {
    $this->browse(function (Browser $browser) {
        $browser
            ->visit('/dev-lab/gallery')
            ->waitFor('[data-test="dev-lab-gallery"]')
            ->assertScript('window.location.search', '')
            ->click('[data-test^="language-chip-"]')
            // pushState, not a navigation: the document stays and the URL moves.
            ->waitUntil('window.location.search.indexOf("lang=") !== -1')
            ->assertScript('document.querySelectorAll("[data-test^=language-chip-][aria-selected=true]").length', 1);

        $selected = $browser->script('return document.querySelector("[data-test^=language-chip-][aria-selected=true]").getAttribute("data-test");')[0];

        $chip = trim((string) $selected, '"');

        $browser
            ->refresh()
            ->waitFor('[data-test="dev-lab-gallery"]')
            // The reloaded page has to answer the same way the click did, from the
            // query string alone — and it is the chip's own attribute that says which.
            ->assertPresent($selector = '[data-test="'.$chip.'"]')
            // json_encode because the selector carries double quotes and
            // `assertScript` pastes its argument straight into a JS string.
            ->assertScript(sprintf('document.querySelector(%s).getAttribute("aria-selected")', json_encode($selector)), 'true');
    });
});

it('replaces the query rather than stacking a second language on it', function () {
    $this->browse(function (Browser $browser) {
        $browser
            ->visit('/dev-lab/gallery')
            ->waitFor('[data-test="dev-lab-gallery"]')
            ->click('[data-test^="language-chip-"]')
            ->waitUntil('window.location.search.indexOf("lang=") !== -1');

        $first = $browser->script('return window.location.search;')[0];

        // Only worth trying when the seed left a second language to pick; a gallery
        // with one language has no second chip, and the test would be asserting a
        // thing that cannot happen here.
        $others = $browser->script('return document.querySelectorAll("[data-test^=language-chip-]").length;')[0];

        if ((int) $others < 2) {
            $this->markTestSkipped('The gallery has a single language chip, so there is no second facet to switch to.');
        }

        $browser
            ->click('[data-test^="language-chip-"]:not([aria-selected="true"])')
            ->waitUntil(sprintf('window.location.search !== %s', json_encode($first)))
            // One `lang=` and no stale pair of them: a facet list that appends would
            // send a reader to a URL that means two things at once.
            ->assertScript('window.location.search.split("lang=").length - 1', 1);
    });
});
