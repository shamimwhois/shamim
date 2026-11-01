<?php

use App\Models\Post;

/*
|--------------------------------------------------------------------------
| About section scrollspy rail
|--------------------------------------------------------------------------
|
| The vertical rail pinned to the viewport's right edge (hidden below xl):
| ten icon + label anchors, one per panel of the ribcage, highlighted
| on scroll by the shared [data-toc] IntersectionObserver in app.js and
| slid in by the data-toc-reveal hook once the hero is scrolled past.
| These tests pin the contract between the nav and the page it indexes —
| every link must resolve to exactly one section on the page, and the
| observer hooks must be present for app.js to drive.
|
*/

test('the section rail renders all ten anchors in order with icons', function () {
    $response = $this->get(route('about'))->assertOk();
    $html = $response->getContent();

    // The rail carries the reveal hook app.js drives once the hero is
    // scrolled past; its fixed right-edge chrome lives in about-effects.css.
    expect($html)->toContain('aria-label="On this page"')
        ->and($html)->toContain('data-toc')
        ->and($html)->toContain('data-toc-reveal')
        ->and($html)->toContain('class="about-toc"')
        // The footer is the dismiss marker: app.js slides the rail away as
        // the footer reaches the rail's vertical centre so it never sits on
        // top of the footer columns.
        ->and($html)->toContain('data-toc-dismiss');
    foreach ([
        ['Intro', 'about-intro'],
        ['Why me', 'about-why'],
        ['How I think', 'about-mind'],
        ['AI Workflow', 'ai-workflow'],
        ['How I work', 'about-principles'],
        ['Capabilities', 'about-capabilities'],
        ['Experience', 'about-experience'],
        ['Work', 'about-work'],
        ['Writing', 'about-writing'],
        ['Contact', 'about-contact'],
    ] as [$label, $id]) {
        $response
            ->assertSee('data-toc-target="'.$id.'"', false)
            ->assertSee('href="#'.$id.'"', false)
            // Labels render in document order, one icon per anchor.
            ->assertSeeInOrder([$label]);
    }

    expect(substr_count($html, 'about-toc-link__icon'))->toBe(10);
});

test('every rail target resolves to exactly one section on the page', function () {
    // The writing section (08) only renders when the carousel has posts.
    Post::factory()->published()->count(2)->create();

    $html = $this->get(route('about'))->assertOk()->getContent();

    foreach (['about-intro', 'about-why', 'about-mind', 'about-principles', 'about-capabilities', 'about-experience', 'about-work', 'about-writing', 'about-contact'] as $id) {
        // The section exists once and the rail links to it once. Counting the
        // bare id is no longer enough: the page also links to #about-contact
        // from section 02's call to action, and in-page links are allowed to
        // multiply — duplicate *sections* are not.
        expect(substr_count($html, 'id="'.$id.'"'))->toBe(1, "The section {$id} must exist exactly once.")
            ->and(substr_count($html, 'data-toc-target="'.$id.'"'))->toBe(1, "The rail must link {$id} exactly once.");
    }

    // The AI Workflow band is linked once and opens exactly one section; its
    // heading id shares the ai-workflow prefix, so match the bare id only.
    expect(substr_count($html, 'data-toc-target="ai-workflow"'))->toBe(1)
        ->and(substr_count($html, 'id="ai-workflow"'))->toBe(1);
});

test('scrollspy links carry the observer hooks and clear anchor landing space', function () {
    // The writing section (08) only renders when the carousel has posts.
    Post::factory()->published()->count(2)->create();

    $html = $this->get(route('about'))->assertOk()->getContent();

    // data-active is the attribute app.js toggles; it starts unhighlighted,
    // and the rail starts unrevealed (data-visible="false") until app.js
    // flips it once the hero is scrolled past.
    expect(substr_count($html, 'data-active="false"'))->toBe(10)
        ->and($html)->toContain('data-visible="false"')
        // The sticky header is ~110px tall; every target clears it.
        ->and(substr_count($html, 'scroll-mt-28'))->toBe(11)
        // No section is pre-highlighted server-side; app.js owns the state.
        ->and($html)->not->toContain('aria-current="location"');
});

test('the rail ships a collapse toggle that starts collapsed', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // The chevron sits above the icon column and controls the list it
    // labels: app.js toggles the rail's data-pinned between open and shut
    // and mirrors the result onto aria-expanded.
    expect($html)->toContain('data-toc-toggle')
        ->and($html)->toContain('about-toc__toggle')
        ->and($html)->toContain('aria-controls="about-toc-list"')
        ->and($html)->toContain('id="about-toc-list"')
        // The rail starts collapsed: no pin is set, so the stylesheet's
        // hover rule alone decides whether the labels are shown.
        ->and($html)->not->toContain('data-pinned')
        // The button's own state starts closed and the chevron is the only
        // control — the labels themselves are not interactive controls.
        ->and(substr_count($html, 'aria-expanded="false"'))->toBe(1)
        ->and(substr_count($html, 'about-toc__toggle-icon'))->toBe(1);
});
