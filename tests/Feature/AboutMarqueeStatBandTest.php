<?php

/*
|--------------------------------------------------------------------------
| About :: expertise marquee + stat band
|--------------------------------------------------------------------------
|
| The two panels that open the about page under the hero pin the mhabir.dev
| opening pair: a scrolling expertise ticker whose pills navigate to the
| Expertise section, then the four-column stat band fed from config. These
| tests pin data flow, the loop's accessibility contract, and the count-up
| attributes the server render must emit for app.js to enhance.
|
*/

test('the marquee lists every configured expertise domain, linking to the section', function () {
    $view = $this->get(route('about'))->assertOk()->getContent();

    foreach (config('portfolio.author.expertise') as $domain) {
        expect($view)->toContain('title="'.htmlspecialchars($domain['title'], ENT_QUOTES).' — see Expertise"');

        expect($view)->toContain('href="#expertise-heading"');
    }
});

test('the marquee duplicates its sequence once and only once, kept out of the a11y tree', function () {
    $view = $this->get(route('about'))->assertOk()->getContent();

    // Exactly one hidden duplicate wrapper…
    expect(substr_count($view, '<div class="flex items-center" aria-hidden="true">'))->toBe(1);

    // …whose pills are unfocusable, so keyboard users tab the list once.
    expect(substr_count($view, 'tabindex="-1"'))->toBeGreaterThanOrEqual(count(config('portfolio.author.expertise')));
});

test('the stat band renders every configured figure with count-up attributes', function () {
    $view = $this->get(route('about'))->assertOk()->getContent();

    expect($view)->toContain('>09<')   // '9' displays zero-padded…
        ->and($view)->toContain('data-count-target="9"')
        ->and($view)->toContain('data-count-pad="2"')
        ->and($view)->toContain('>30+<') // …suffixes ride along untouched…
        ->and($view)->toContain('data-count-suffix="+"')
        ->and($view)->toContain('>40M<')
        ->and($view)->toContain('data-count-suffix="M"')
        ->and($view)->toContain('>12<');  // …and two-digit values pad to themselves.

    foreach (config('portfolio.author.stats') as $stat) {
        expect($view)->toContain($stat['label']);
    }
});

test('non-numeric stat values render static without count-up wiring', function () {
    config()->set('portfolio.author.stats', [
        ['label' => 'Uptime', 'value' => 'ALL the nines'],
    ]);

    $view = $this->get(route('about'))->assertOk()->getContent();

    expect($view)->toContain('>ALL the nines<')
        ->and($view)->not->toContain('data-countup');
});
