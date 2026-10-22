<?php

/*
|--------------------------------------------------------------------------
| About hero decode line
|--------------------------------------------------------------------------
|
| The hero's terminal line is a scramble-decode: a phrase arrives as noise
| and locks in left to right, and it is paired with a matching glitch
| sound. These tests pin the three things that contract rests on — the
| markup app.js drives, the no-JS fallback, and the sound hooks — so a
| refactor that breaks the pairing fails here rather than silently on a
| live page.
|
*/

test('the decode line exposes the phrases app.js cycles through', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // The phrases live in a data attribute, comma separated, because
    // app.js cycles them; the visible text is only the first one.
    expect($html)->toContain('data-cycle=')
        ->and($html)->toContain('data-cycle-el')
        ->and($html)->toContain('Shipping production Laravel with measurable impact')
        ->and($html)->toContain('Building APIs that don&#39;t page you at 3am')
        ->and($html)->toContain('Solving one bug at a time')
        ->and($html)->toContain('Teaching Postgres, queues and caches to behave');
});

test('the decode line is hidden from assistive tech with the phrases spelled out instead', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // The animated line is noise to a screen reader — it changes 22 times
    // a second. It is hidden, and a static copy of every phrase carries
    // the meaning instead.
    expect($html)->toContain('class="sr-only"')
        ->and($html)->toContain('aria-live="polite"');

    // The visible line is the one that is hidden: the sr-only sibling
    // must not be aria-hidden, or nothing would be announced at all.
    expect($html)->toMatch('/<span class="sr-only">[^<]*<\/span>\s*<span\s+aria-hidden="true"\s+data-cycle=/');
});

test('the decode line keeps a complete phrase when JavaScript is off', function () {
    // The server renders the first phrase inside the element app.js
    // takes over, so a no-JS reader gets a whole sentence rather than
    // an empty box.
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toMatch('/data-cycle-el\s*>\s*Shipping production Laravel with measurable impact\s*<\/span>/');
});

test('app.js drives the decode line as two parts so signal and noise read differently', function () {
    $js = file_get_contents(resource_path('js/app.js'));

    // One string cannot express "these characters have resolved, these
    // have not" — the two halves need separate elements, because the
    // CSS marks the difference between them.
    expect($js)->toContain('about-decode-signal')
        ->and($js)->toContain('about-decode-noise')
        ->and($js)->toContain('scrambleNoise');

    // The noise keeps the sentence's spaces, so its shape is readable
    // while the characters are still noise.
    expect($js)->toMatch("/function scrambleNoise[\s\S]*?if \(char === ' '\) \{/");
});

test('the decode is paired with a matched glitch and lock sound', function () {
    $js = file_get_contents(resource_path('js/app.js'));
    $sound = file_get_contents(resource_path('js/sound.js'));

    // Two hits per phrase, not one per character: the burst when the
    // phrase breaks apart, the clean tone when it locks in. A blip per
    // character would be a drone at 22 sounds a second.
    expect($js)->toContain("playAmbient('glitch')")
        ->and($js)->toContain("playAmbient('lock')")
        ->and($sound)->toContain('glitch:')
        ->and($sound)->toContain('lock:');
});

test('the decode sound plays once rather than on every pass of the loop', function () {
    $js = file_get_contents(resource_path('js/app.js'));

    // The line cycles forever, so a sound on every pass is a loop — and
    // a loop is how a sound starts to grate against someone trying to
    // read. One burst as the terminal boots is a flourish.
    expect($js)->toContain('let sounded = false;')
        ->and($js)->toMatch('/if \(resolved === 1 && ! sounded\) \{[\s\S]*?sounded = true;[\s\S]*?playAmbient\(\'glitch\'\);/');
});

test('the ambient decode sound waits for a user gesture before it plays', function () {
    $sound = file_get_contents(resource_path('js/sound.js'));

    // The decode is driven by a timer, so it has no gesture of its own.
    // The browser will not start an audio context without one, and an
    // attempt to force it leaves a console warning behind — so the
    // ambient path must wait for the engine to be unlocked.
    expect($sound)->toContain('export function playAmbient')
        ->and($sound)->toMatch('/export function playAmbient[\s\S]*?if \(! soundUnlocked\(\)\) \{[\s\S]*?return;[\s\S]*?\}\s*playSound\(name\);/');
});

test('the sound engine stays silent under reduced motion', function () {
    $sound = file_get_contents(resource_path('js/sound.js'));

    // Motion sensitivity and audio feedback travel together here: a
    // reader who asked for less motion gets no sound either.
    expect($sound)->toMatch('/export function soundEnabled\(\)[\s\S]*?prefers-reduced-motion: reduce[\s\S]*?return false;/');
});
