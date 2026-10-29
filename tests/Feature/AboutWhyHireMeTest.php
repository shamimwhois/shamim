<?php

/*
|--------------------------------------------------------------------------
| About page — section 02, why hire me
|--------------------------------------------------------------------------
|
| The section is a value argument: the heading and the paragraph on the
| left, the six claims orbiting the portrait on the right, the three
| specialisation pills, the two audience panels along the bottom, and one
| pair of actions.
|
| These tests pin the parts that could quietly rot:
|
| 1. The eyebrow, the heading and the description are present — the section
|    is specified copy, not a placeholder.
| 2. All six claims render, in order.
| 3. Both audience panels render their prose. A truncated panel is the
|    easiest thing to lose in a refactor and the hardest to notice.
| 4. The orbit renders the profile portrait, in both theme renders.
| 5. The GitHub action points at the real profile and opens in a new tab.
| 6. The contact action stays inside the page — the brief forbids a new
|    contact page or form, so the anchor is the contract.
| 7. Each action appears once. "Include only these two buttons" is a
|    content requirement, not a styling preference.
|
*/

test('the section renders its eyebrow, heading and description', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // The accent word is wrapped in its own span with the swash SVG inside it,
    // so the heading only reads as one sentence once the markup is stripped.
    $text = strip_tags($html);

    expect($text)->toContain('Why Hire Me?')
        ->toContain('Your Ideas, My Engineering Focus.')
        ->toContain('I build reliable, scalable and maintainable web applications using Laravel and PHP.');
});

test('the six value propositions render in order', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // The cards carry no numerals any more — the orbit is positional — so
    // order is pinned by walking the markup: each title must appear after
    // the previous one, not merely somewhere on the page.
    $position = 0;

    foreach ([
        'Backend Engineering',
        'API Development',
        'Scalable Architecture',
        'Automation &amp; Tools',
        'Quality &amp; Reliability',
        'Client &amp; Team Focus',
    ] as $title) {
        $found = strpos($html, $title, $position);

        expect($found)->not->toBeFalse();
        $position = $found + strlen($title);
    }
});

test('both audience panels render their prose', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toContain('For Companies')
        ->toContain('For Clients')
        // One line from the middle of each panel: a panel that loses its
        // tail is the failure mode worth pinning.
        ->toContain('I bring problem-solving, clean architecture and a focus on long-term maintainability.')
        ->toContain('I build with clarity, quality and care.');
});

test('the orbit renders the profile portrait in both theme renders', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toContain('images/hero-portrait-light.jpg')
        ->toContain('images/darkmode.webp');
});

test('the GitHub action points at the profile and opens in a new tab', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toContain('href="https://github.com/shamimwhois"')
        ->toContain('Explore My GitHub')
        ->toContain('rel="noopener noreferrer"');
});

test('the contact action stays inside the page', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // The brief forbids a new contact page or form: the section links to the
    // contact band the page already renders.
    expect($html)->toContain('href="#about-contact"')
        ->and($html)->toContain("Let's Work Together");
});

test('the section offers exactly two actions', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect(substr_count($html, 'Explore My GitHub'))->toBe(1)
        ->and(substr_count($html, "Let's Work Together"))->toBe(1);
});
