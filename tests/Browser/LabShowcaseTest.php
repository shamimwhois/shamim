<?php

/**
 * The landing showcase, run by a reader who asked for nothing.
 *
 * `SandboxPageTest` and `SandboxShowcaseTest` pin the attribute string in the
 * markup — `sandbox="allow-scripts"`, and the absence of `allow-same-origin` — and
 * that is the whole of what a PHP test can say about a security boundary. It is not
 * nothing: a missing `wire:ignore`, a copied prop, or a template that stops passing
 * the attribute would all be caught there.
 *
 * What it cannot catch is the boundary being real. The promise the panel makes in
 * prose — "the frame has no origin of its own, so the code in it cannot read this
 * site's cookies, storage, or page" — is a claim about what the *browser* enforces
 * once the attribute is parsed, and a browser is the only judge of that. Two ways
 * that claim goes false without any test above it noticing: the frame is given an
 * origin it should not have (`allow-same-origin` reaching a srcdoc document, which
 * would put this site's storage inside a pane that runs unreviewed code), or the
 * console stops working, which is the other half of the same contract because a
 * panel that never prints is indistinguishable from a frame that never ran.
 *
 * So this file asks the frame itself. Inside it, `window.origin` is the string
 * `"null"` rather than `http://127.0.0.1:8000`, and touching `localStorage` raises
 * `SecurityError` instead of returning a storage object — the two facts that mean
 * the code in the panes is not this site's neighbour. And in the parent, the line
 * the frame's own `console.log` posted proves the shim, the `postMessage`, and the
 * `event.source` identity test on the receiving side all still line up: a parent
 * that compared origins would have dropped this message, because an opaque origin
 * serialises to the literal `"null"` and never matches.
 */

use Laravel\Dusk\Browser;

it('runs the landing example in a frame that has no origin of its own', function () {
    $this->browse(function (Browser $browser) {
        $browser
            ->visit('/dev-lab')
            ->waitFor('[data-test="dev-lab-showcase"]')
            // Nothing composes a frame until a reader asks. This is the module's rule
            // about running code in somebody's tab, and it holds for our own example.
            ->assertMissing('[data-test="dev-lab-showcase"] iframe')
            ->press('Run the example')
            ->waitFor('[data-test="dev-lab-showcase"] iframe')
            ->assertScript('(function () {'
                .'var f = document.querySelector("[data-test=dev-lab-showcase] iframe");'
                .'var v = f.getAttribute("sandbox");'
                .'return v === "allow-scripts" && v.indexOf("allow-same-origin") === -1;'
            .'})()')
            ->withinFrame('[data-test="dev-lab-showcase"] iframe', function (Browser $frame) {
                $frame
                    ->waitUntil('!!document.querySelector("main.note h1")')
                    ->assertScript('window.origin', 'null')
                    // Reading the accessor is what throws — the property exists on every
                    // window, so the check has to be the touch, not the name.
                    ->assertScript('(function () {'
                        .'try { window.localStorage; return false; }'
                        .'catch (e) { return e.name === "SecurityError"; }'
                    .'})()');
            })
            // Back in the parent: the transcript is the only place the frame can speak,
            // and it is the receiving side that decides whether it was believed.
            ->waitUntil('(function () {'
                .'var panel = document.querySelector("[data-test=dev-lab-showcase] ul");'
                .'return panel !== null && panel.textContent.indexOf("Frame ready") !== -1;'
            .'})()')
            ->assertScript('(function () {'
                .'var panel = document.querySelector("[data-test=dev-lab-showcase] ul");'
                .'return panel.textContent.indexOf("<") === -1;'
            .'})()')
            ->screenshot('dev-lab-showcase');
    });
});
