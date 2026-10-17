<?php

use App\Models\Post;

/*
|--------------------------------------------------------------------------
| About section scrollspy rail
|--------------------------------------------------------------------------
|
| The vertical rail pinned to the viewport's right edge (hidden below xl):
| eight icon + label anchors, one per panel of the ribcage, highlighted
| on scroll by the shared [data-toc] IntersectionObserver in app.js and
| slid in by the data-toc-reveal hook once the hero is scrolled past.
| These tests pin the contract between the nav and the page it indexes —
| every link must resolve to exactly one section on the page, and the
| observer hooks must be present for app.js to drive.
|
*/

test('the section rail renders all eight anchors in order with icons', function () {
    $response = $this->get(route('about'))->assertOk();
    $html = $response->getContent();

    // The rail carries the reveal hook app.js drives once the hero is
    // scrolled past; its fixed right-edge chrome lives in about-effects.css.
    expect($html)->toContain('aria-label="On this page"')
        ->and($html)->toContain('data-toc')
        ->and($html)->toContain('data-toc-reveal')
        ->and($html)->toContain('class="about-toc"');

    foreach ([
        ['Intro', 'about-intro'],
        ['Why me', 'about-why'],
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

    expect(substr_count($html, 'about-toc-link__icon'))->toBe(8);
});

test('every rail target resolves to exactly one section on the page', function () {
    // Section 07 only renders when the writing carousel has posts to show.
    Post::factory()->published()->count(2)->create();

    $html = $this->get(route('about'))->assertOk()->getContent();

    foreach (['about-intro', 'about-why', 'about-principles', 'about-capabilities', 'about-experience', 'about-work', 'about-writing', 'about-contact'] as $id) {
        // Three occurrences each: the section's id attribute, the rail link's
        // href, and its data-toc-target — neither missing nor duplicated.
        expect(substr_count($html, $id))->toBe(3, "The scrollspy target {$id} must exist once and be linked once.");
    }
});

test('scrollspy links carry the observer hooks and clear anchor landing space', function () {
    // Section 07 only renders when the writing carousel has posts to show.
    Post::factory()->published()->count(2)->create();

    $html = $this->get(route('about'))->assertOk()->getContent();

    // data-active is the attribute app.js toggles; it starts unhighlighted,
    // and the rail starts unrevealed (data-visible="false") until app.js
    // flips it once the hero is scrolled past.
    expect(substr_count($html, 'data-active="false"'))->toBe(8)
        ->and($html)->toContain('data-visible="false"')
        // The sticky header is ~110px tall; every target clears it.
        ->and(substr_count($html, 'scroll-mt-28'))->toBe(9)
        // No section is pre-highlighted server-side; app.js owns the state.
        ->and($html)->not->toContain('aria-current="location"');
});
