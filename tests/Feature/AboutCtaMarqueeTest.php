<?php

/*
|--------------------------------------------------------------------------
| About :: CTA marquee (floor band)
|--------------------------------------------------------------------------
|
| The band between the Contact section and the footer, ported 1:1 from
| mhabir.dev's floor marquee: a seamless loop of the outlined phrase
| "Let's build something useful". These tests pin the loop's contract —
| six phrase copies so the -50% translation never shows a seam, the
| band kept out of the accessibility tree, and the Contact → marquee
| ordering that seats it at the foot of the page.
|
*/

use function Pest\Laravel\get;

test('the floor band repeats the CTA phrase six times for a seamless loop', function () {
    $view = get(route('about'))->assertOk()->getContent();

    // Blade escapes the apostrophe (Let&#039;s); the entities ride along as raw markup.
    expect(substr_count($view, 'build something useful&nbsp;·&nbsp;'))->toBe(6)
        // Every copy shares the outlined, size-responsive treatment.
        ->and(substr_count($view, 'cta-marquee__stroke whitespace-nowrap'))->toBe(6);
});

test('the floor band is pure decoration: hidden from assistive tech and unselectable', function () {
    $view = get(route('about'))->assertOk()->getContent();

    $band = mb_strpos($view, 'cta-marquee__track');
    expect($band)->not->toBeFalse('The looping track must render.');

    // The open tag just above the track declares the band itself: accent
    // hairline on top, unselectable, and absent from the accessibility tree.
    $opening = mb_substr($view, max(0, $band - 400), $band);

    expect($opening)->toContain('class="cta-marquee')
        ->and($opening)->toContain('aria-hidden="true"')
        ->and($opening)->toContain('select-none');
});

test('the floor band closes the page: contact content above, marquee below', function () {
    $view = get(route('about'))->assertOk()->getContent();

    $contact = mb_strpos($view, 'id="contact-heading"');
    $marquee = mb_strpos($view, 'cta-marquee__track');

    expect($contact)->toBeInt()
        ->and($marquee)->toBeInt()
        ->and($marquee)->toBeGreaterThan($contact);
});
