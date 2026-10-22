<?php

/**
 * The reading layer on a published idea, driven by a real browser.
 *
 * Nothing in this file can be proven from PHP. `IdeaShowReadingTest` already pins
 * that the page *emits* `[data-reading-progress]`, `[data-reading-target]` and the
 * outline links; emitting an attribute is not the same as something reading it.
 * The progress rule and the highlighted table of contents are both computed by
 * listeners in `resources/js/app.js` — a rAF-throttled scroll handler that writes
 * `--progress` / `aria-valuenow`, and an IntersectionObserver that writes
 * `data-active` onto the matching link — and a listener that never fired because
 * of a typo in a selector name would leave every Blade assertion green.
 *
 * So the assertions are made against the values the browser computes, at the
 * scroll positions a reader actually reaches:
 *
 *   1. zero at the top, because the article has not crossed the fold yet;
 *   2. greater than zero, and the third section's link marked current, once that
 *      section is inside the band the observer treats as "reading now";
 *   3. exactly 100 at the bottom, which is the only end of the range a listener
 *      can land on by arithmetic rather than by luck.
 *
 * The heading is parked 200px below the top of the viewport rather than at the
 * very top, because the observer's root margin is `-96px 0px -60% 0px`: a heading
 * scrolled to y=0 sits *above* that band and would legitimately be reported as
 * not current. `scrollIntoView()` would be the obvious one-liner and would test
 * the margin instead of the highlight.
 */

use App\Models\Idea;
use App\Models\User;
use Laravel\Dusk\Browser;

it('tracks the reader through a published idea and lights the section in view', function () {
    /** @var User $author */
    $author = User::query()->where('email', 'test@example.com')->firstOrFail();

    // Written here rather than seeded: the corpus this suite rebuilds has no
    // published idea tall enough to scroll, and a progress bar measured on a
    // one-screen article is arithmetic on a negative number.
    /** @var Idea $idea */
    $idea = Idea::factory()->published()->create([
        'user_id' => $author->id,
        'title' => 'The reading layer is a contract between two files',
        'slug' => 'reading-layer-browser-check',
        'content' => <<<'MD'
            A page can render an article and still refuse to tell anybody how far they
            have read it. The gap between those two states is smaller than it looks: one
            attribute, one listener, and agreement between them about which element the
            listener measures.

            ## Why the order matters

            The measure is the prose, not the document. A progress rule computed from
            `document.body` reaches 100 percent while the reader is still inside the
            related-ideas rail, which is not a reading position anybody would describe as
            finished, and the number arrives so late that it stops being information.

            The prose is also not the article element. The difference is invisible on a page
            whose header is short and obvious on one that carries a byline block, a badge
            row, a share bar and a topic strip above the first paragraph, where the rule
            would sit at zero for the entire distance down to the text.

            Every paragraph below is filler in the honest sense that it exists to make the
            page long enough for the arithmetic to have a range. A progress bar tested
            against a two-screen article is tested against a number that rounds the same
            way at both ends, and a bar tested against a page taller than four screens is
            tested against one where the middle of the range is reachable.

            ## What the bar measures

            A fraction of the article's own height that has passed the top edge of the
            viewport, divided by the height that can pass it at all. Two queries, one
            subtraction, one clamp, and no scroll listener that needs to know where the page
            began.

            That last clause is the reason the implementation is not `scrollTop`. A handler
            written against `scrollTop` needs the article's offset from the top of the
            document, which is a number that changes when the header reflows, when a font
            finishes loading, and when somebody edits the byline. `getBoundingClientRect` is
            measured at the frame it is used in, so none of those three events can make the
            bar lie about a position it has already left behind.

            The clamp is what makes the two ends worth asserting separately. Below zero the
            bar would read as a negative percentage, which the attribute setter turns into a
            string the browser refuses, and above one it would advertise a reading progress
            that no reader has. The arithmetic is the boring half; the boundaries are where
            a bar like this breaks.

            ## Where the outline comes from

            The table of contents is derived from the rendered headings, which means the
            link and the heading carry the same slug for the same reason: both are produced
            from the same text by the same function, in the same request, from the same
            Markdown. An outline that stored its own ids would drift the first time an
            author renamed a section and republished.

            The highlight is an observer, not a scroll handler, because the question the
            side column answers is positional in a way that does not need a number: which
            heading is currently inside the band a reader's eyes occupy. That band is a root
            margin, and a margin is the cheapest way to say "not the header, not the footer"
            without measuring either.

            Two sections in the band at once is the interesting case, and the answer is the
            topmost one in document order. A reader who has scrolled until a heading sits
            just below the fold is inside the section above it, whatever the observer's
            callback order happens to be — which is why the implementation sorts by the
            heading list rather than trusting the entries it was handed.

            ## What a bar is not for

            It is not a percentage of the article that has been understood, and not a
            position in a document that a reader could quote back. It is a scroll indicator
            that uses the text as its ruler because the text is the only part of the page
            anybody is reading.

            Which is also why the component is allowed to be invisible without JavaScript.
            A rule that never moves is a decorative line; a rule that never moves because
            its stylesheet failed to load is the same decorative line, and neither is a
            reason to withhold the article from anybody.

            The narrow-screen copy of the outline is the same list in a disclosure, and it
            is deliberately not what the listener wires up. Two observers tracking two
            identical sets of links would fight over the same attribute on the same page
            and produce a highlight that flickers between two correct answers.

            A reader on a phone gets the outline folded, the bar still moving, and no
            indication that any of it was decided by a selector that matched the first
            element in document order rather than the most obvious one. That is the whole
            of the difference between a reading layer and a page that renders prose.
            MD,
    ]);

    $this->browse(function (Browser $browser) use ($idea) {
        $browser
            ->visit('/ideas/'.$idea->slug)
            ->waitFor('[data-reading-target]')
            ->assertPresent('[data-reading-progress]')
            ->assertPresent('[data-toc]')
            // The starting state, before any scroll event has been handled: the article
            // has not reached the fold, so the bar is empty rather than merely small.
            ->assertAttribute('[data-reading-progress]', 'aria-valuenow', '0');

        // `script()` answers with the JavaScript return value rather than the
        // browser, so a scroll cannot be chained onto the assertions around it.
        $browser->script('var h = document.getElementById("where-the-outline-comes-from");
            window.scrollTo(0, h.getBoundingClientRect().top + window.scrollY - 200);');

        $browser
            // Progress is a continuous value, so it is waited for as a predicate instead
            // of a fixed string the browser is free to round differently.
            ->waitUntil('(function () {'
                .'var bar = document.querySelector("[data-reading-progress]");'
                .'return parseFloat(bar.style.getPropertyValue("--progress")) > 0'
                .' && Number(bar.getAttribute("aria-valuenow")) > 0;'
            .'})()')
            // The observer's own write, and the aria that travels with it. Polling on the
            // attribute rather than sleeping: the callback lands on the next intersection
            // change, which is not a duration anything in the page controls.
            ->waitFor('[data-toc] a[data-toc-target="where-the-outline-comes-from"][data-active="true"]')
            ->assertAttribute(
                '[data-toc] a[data-toc-target="where-the-outline-comes-from"]',
                'aria-current',
                'location'
            )
            // Exactly one section is current: the highlight is a cursor, not a set. This
            // holds the moment the line above it holds, because both are written by the
            // same synchronous pass over the links.
            ->assertAttribute(
                '[data-toc] a[data-toc-target="why-the-order-matters"]',
                'data-active',
                'false'
            );

        $browser->script('window.scrollTo(0, document.body.scrollHeight)');

        $browser
            // The far end of the range, where the article's last pixel has met the
            // viewport's. Anything short of 100 here means the clamp or the divisor is
            // wrong, and no earlier assertion in this file would notice.
            ->waitUntil('document.querySelector("[data-reading-progress]").getAttribute("aria-valuenow") === "100"')
            ->screenshot('ideas-reading-layer');
    });
});
