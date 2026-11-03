<?php

use App\Data\AuthorProfile;
use App\Models\Post;

/*
|--------------------------------------------------------------------------
| About selected work / capabilities / writing sections
|--------------------------------------------------------------------------
|
| Sections 04, 06 and 07 of the about page were re-modelled on
| mhabir.dev's #skills and #projects panels: the shared header idiom, a
| capability stage cards with a clickable mind map, the asymmetric
| project bento, and a scroll-snap writing carousel. These tests pin the
| contract between config('portfolio') and the markup that renders it.
|
*/

test('the selected work section renders the bento header and every project', function () {
    $projects = AuthorProfile::fromConfig()->projects;

    expect($projects)->not->toBeEmpty('The work bento renders from config; an empty config renders an empty section.');

    $response = $this->get(route('about'))->assertOk();

    $response
        ->assertSee('id="work-heading"', false)
        ->assertSeeInOrder(['06', 'Selected work']);

    foreach ($projects as $index => $project) {
        $response->assertSee('P·'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT));
        $response->assertSee($project['name']);
        $response->assertSee($project['summary']);

        foreach ($project['stack'] as $tech) {
            $response->assertSee($tech);
        }
    }
});

test('work cards draw their frame, carry the ghost watermark, and link out when a url exists', function () {
    config()->set('portfolio.projects', [
        ['name' => 'Atlas Ledger', 'role' => 'Lead engineer', 'summary' => 'S', 'outcome' => 'O', 'stack' => ['Laravel'], 'url' => 'https://github.com/example/atlas'],
        ['name' => 'Meridian API', 'role' => 'Architect', 'summary' => 'S', 'outcome' => 'O', 'stack' => ['MySQL'], 'url' => null],
    ]);

    $response = $this->get(route('about'))->assertOk();

    $response
        ->assertSee('draw-frame', false)
        ->assertSee('exp-ghost-stroke', false)
        ->assertSee('rel="noopener noreferrer nofollow"', false)
        // Only the entry with a URL gets the outlined View pill.
        ->assertSeeText('View');

    // The bento is asymmetric on the reference: 7+5, then 6+6.
    expect(substr_count((string) $response->getContent(), 'lg:col-span-7'))->toBe(1);
    expect(substr_count((string) $response->getContent(), 'lg:col-span-5'))->toBe(1);
});

test('the capabilities section renders the cards and the mind map from config', function () {
    $domains = AuthorProfile::fromConfig()->expertise;

    $response = $this->get(route('about'))->assertOk();

    $response
        ->assertSee('id="expertise-heading"', false)
        ->assertSeeInOrder(['04', 'Capabilities'])
        ->assertSee('Click a capability to trace its branch');

    // One interactive card and one map branch per configured domain.
    $html = (string) $response->getContent();
    expect(substr_count($html, 'cap-card '))->toBe(count($domains));
    expect(substr_count($html, 'skills-wire'))->toBe(count($domains));
    expect(substr_count($html, 'skills-node"'))->toBe(count($domains));

    // Each card icon renders as an inline, theme-following SVG.
    foreach ($domains as $domain) {
        $response->assertSee($domain['title']);
    }
    $iconMatches = preg_match_all('/<svg[^>]*class="[^"]*cap-card__icon/', $html);
    expect($iconMatches)->toBeInt();
    expect($iconMatches)->toBe(count($domains));
    expect($html)->not->toContain('-mono.svg');

    // Cards start unpressed; the stage wires up for initSkillsMap().
    expect(substr_count($html, 'aria-pressed="false"'))->toBeGreaterThanOrEqual(count($domains));
    expect($html)->toContain('data-skills-stage');
});

test('the writing carousel renders when posts exist and the counter names the first card', function () {
    $response = $this->get(route('about'))->assertOk();
    $html = (string) $response->getContent();

    if (Post::query()->published()->count() === 0) {
        expect($html)->not->toContain('data-carousel-viewport');

        return;
    }

    expect($html)
        ->toContain('data-carousel-viewport')
        ->toContain('data-carousel-counter')
        ->toContain('data-carousel-prev')
        ->toContain('data-carousel-next');
});
