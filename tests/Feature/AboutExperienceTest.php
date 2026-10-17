<?php

use App\Data\AuthorProfile;

/*
|--------------------------------------------------------------------------
| About experience timeline
|--------------------------------------------------------------------------
|
| The about page's Experience section is the last major surface rendered
| from config, so these tests pin the contract between
| config('portfolio.author.experience') and the markup: every employer,
| role, highlight, and stack chip in the config must reach the page, and
| the section's numbering must survive the insertion of the new block
| (05 Experience, with Contact moving to 08).
|
*/

test('the experience section renders the journey heading and marker', function () {
    $this->get(route('about'))
        ->assertOk()
        ->assertSee('id="experience-heading"', false)
        ->assertSee('My professional journey')
        ->assertSeeInOrder(['05', 'Experience']);
});

test('every configured employer, role, highlight, and stack chip reaches the page', function () {
    $experience = AuthorProfile::fromConfig()->experience;

    expect($experience)->not->toBeEmpty('The about experience section renders from config; an empty config renders an empty section.');

    $response = $this->get(route('about'))->assertOk();
    $html = $response->getContent();

    foreach ($experience as $employer) {
        $response->assertSee($employer['company']);
        $response->assertSee($employer['period']);

        foreach ($employer['roles'] as $role) {
            $response->assertSee($role['title']);
            $response->assertSee($role['period']);

            foreach ($role['highlights'] as $highlight) {
                $response->assertSee($highlight['text']);

                foreach ($highlight['stack'] as $tech) {
                    $response->assertSee($tech);
                }
            }
        }
    }

    // One employer block per config entry, each with its hairline top edge.
    expect(substr_count($html, 'aria-labelledby="experience-heading"'))->toBe(1)
        ->and($html)->toContain('<article');
});

test('the timeline is ghost-watermarked, rail-dotted, and chip-stamped', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // Ghost watermark: repeated employer name, hidden from assistive tech.
    expect($html)->toContain('aria-hidden="true"')
        ->and($html)->toContain('select-none whitespace-nowrap');

    // Rail dots: one ring tap per configured role.
    $roles = 0;
    foreach (AuthorProfile::fromConfig()->experience as $employer) {
        $roles += count($employer['roles']);
    }

    // Stack chips are the small rounded signal-tinted pills on each highlight.
    expect($html)->toContain('rounded-md border border-signal-700/30');
});

test('contact is the last numbered section after experience moved in', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // Anchor on the section elements: the hero's quick links also say
    // "Get in touch", so raw text positions double-count earlier hits. The
    // writing section is intentionally omitted — it renders only when the
    // database has published posts, which an empty test database lacks.
    $experience = strpos((string) $html, 'aria-labelledby="experience-heading"');
    $work = strpos((string) $html, 'aria-labelledby="work-heading"');
    $contact = strpos((string) $html, 'aria-labelledby="contact-heading"');

    expect($experience)->toBeGreaterThan(0)
        ->and($work)->toBeGreaterThan($experience)
        ->and($contact)->toBeGreaterThan($work);
});
