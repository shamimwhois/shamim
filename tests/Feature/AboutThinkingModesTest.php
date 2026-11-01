<?php

use App\Enums\AboutItemType;
use App\Enums\AboutSectionType;
use App\Models\AboutItem;
use App\Models\AboutSection;
use App\Services\AboutContent;
use App\Support\ThinkingTaxonomy;

/*
|--------------------------------------------------------------------------
| About page — section 04 thinking modes
|--------------------------------------------------------------------------
|
| The section 04 band is the second reader of the thinking vocabulary. It
| answers a different question from section 02 ("what does this mind do",
| not "what is it made of"), so it reads its own rows out of the principles
| section. These tests pin the three things that could quietly break:
|
| 1. The two explorers stay separate. A row written for one must never
|    appear in the other, and the built-in vocabulary of one must never
|    leak into the other.
| 2. Affinity decides the hemisphere. A card marked fast belongs to the
|    left, deep to the right, and a card marked neither belongs to neither.
| 3. The band still renders when the operator has written nothing at all.
|
*/

test('the panel renders both rails and their built-in abilities', function () {
    $response = $this->get(route('about'))->assertOk();

    $response->assertSee('System 1')
        ->assertSee('System 2')
        ->assertSee('Rapid Prototyping')
        ->assertSee('System Architecture');

    // The cards must be in the markup, not only in the payload: three per
    // rail, six in total, which is the panel's whole height budget.
    expect(substr_count($response->getContent(), 'hiw-card__name'))->toBe(6);
});

test('the section 04 band renders its own vocabulary, not section 02 categories', function () {
    $this->get(route('about'))
        ->assertOk()
        // Section 04's built-in abilities...
        ->assertSee('Rapid Prototyping')
        ->assertSee('System Architecture');

    // Section 02's disciplines belong to the other band, so section 04's
    // own payload must not carry them. Asserted on the payload rather than
    // the page, because the page renders both bands on purpose.
    $modes = app(AboutContent::class)->thinkingModes()['modes'];

    $names = collect($modes)
        ->flatMap(fn (array $mode): array => collect($mode['cards'])->pluck('name')->all())
        ->all();

    expect($names)->toContain('Rapid Prototyping')
        ->and($names)->not->toContain('Systems Thinking')
        ->and($names)->not->toContain('Debugging & Analysis');
});

test('stored thinking rows in the principles section drive the section 04 band', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Principles]);

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingCategory,
        'title' => 'Contract-First Design',
        'subtitle' => 'contract-first-design',
        'body' => 'The promise is written before the payload.',
        'url_label' => 'deep',
    ]);

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingNode,
        'title' => 'Promise before payload',
        'subtitle' => 'contract-first-design',
        'body' => 'A contract is a promise about behaviour.',
    ]);

    $response = $this->get(route('about'))->assertOk();

    $response->assertSee('Contract-First Design')
        // The stored rows replace the built-in set for this section.
        ->assertDontSee('Rapid Prototyping');

    // One stored category, so one card in the panel. Its mind-map nodes are
    // no longer rendered: the panel shows the method, not the reasoning.
    expect(substr_count($response->getContent(), 'hiw-card__name'))->toBe(1);
});

test('section 02 rows never leak into the section 04 band', function () {
    $reasons = AboutSection::factory()->create(['type' => AboutSectionType::Reasons]);

    AboutItem::factory()->create([
        'about_section_id' => $reasons->id,
        'type' => AboutItemType::ThinkingCategory,
        'title' => 'Section Two Only',
        'subtitle' => 'section-two-only',
        'body' => 'This card belongs to the reasons section.',
        'url_label' => 'fast',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Section Two Only')
        // Section 04 keeps its own vocabulary because the rows live
        // in a different section.
        ->assertSee('Rapid Prototyping');
});

test('section 04 rows never leak into the section 02 band', function () {
    $principles = AboutSection::factory()->create(['type' => AboutSectionType::Principles]);

    AboutItem::factory()->create([
        'about_section_id' => $principles->id,
        'type' => AboutItemType::ThinkingCategory,
        'title' => 'Section Four Only',
        'subtitle' => 'section-four-only',
        'body' => 'This card belongs to the principles section.',
        'url_label' => 'fast',
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Section Four Only')
        ->assertSee('Problem Solving');
});

test('a card with no affinity is shown on neither hemisphere', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Principles]);

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingCategory,
        'title' => 'Unassigned Ability',
        'subtitle' => 'unassigned-ability',
        'body' => 'No hemisphere has claimed it yet.',
        'url_label' => null,
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertDontSee('Unassigned Ability');
});

test('the section 04 presenter puts fast cards on the left and deep cards on the right', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Principles]);

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingCategory,
        'title' => 'Fast Card',
        'subtitle' => 'fast-card',
        'url_label' => 'fast',
    ]);

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingCategory,
        'title' => 'Deep Card',
        'subtitle' => 'deep-card',
        'url_label' => 'deep',
    ]);

    $modes = app(AboutContent::class)->thinkingModes()['modes'];

    expect($modes)->toHaveCount(2)
        ->and($modes[0]['key'])->toBe('system_1')
        ->and($modes[0]['side'])->toBe('left')
        ->and($modes[0]['accent'])->toBe('cyan')
        ->and($modes[1]['key'])->toBe('system_2')
        ->and($modes[1]['side'])->toBe('right')
        ->and($modes[1]['accent'])->toBe('violet')
        ->and(collect($modes[0]['cards'])->pluck('name')->all())->toContain('Fast Card')
        ->and(collect($modes[0]['cards'])->pluck('name')->all())->not->toContain('Deep Card')
        ->and(collect($modes[1]['cards'])->pluck('name')->all())->toContain('Deep Card');
});

test('a stored mode row renames the section 04 hemisphere', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Principles]);

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingMode,
        'title' => 'System 1 — Instinct',
        'subtitle' => 'system_1',
        'body' => 'The half that already knows.',
    ]);

    $modes = app(AboutContent::class)->thinkingModes()['modes'];

    expect($modes[0]['name'])->toBe('System 1 — Instinct')
        ->and($modes[0]['description'])->toBe('The half that already knows.');
});

test('a typo in a stored section 04 mode key degrades to the fast system', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Principles]);

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingMode,
        'title' => 'Mystery System',
        'subtitle' => 'system_9',
        'body' => 'An unresolvable key.',
    ]);

    $modes = app(AboutContent::class)->thinkingModes()['modes'];

    expect($modes[0]['key'])->toBe('system_1')
        ->and($modes[0]['name'])->toBe('Mystery System');
});

test('the section 04 band carries the operating principles and the focus facts', function () {
    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Make the wrong thing hard to do')
        ->assertSee('Primary stack');
});

test('the thinking taxonomy ships one key per ability and one per discipline', function () {
    $work = ThinkingTaxonomy::workCategories();
    $disciplines = ThinkingTaxonomy::categories();

    expect($work)->not->toBeEmpty()
        ->and($disciplines)->not->toBeEmpty()
        // The two vocabularies are separate sets: a key must not appear in
        // both, or the two bands would render the same card twice.
        ->and(array_intersect(
            array_column($work, 'key'),
            array_column($disciplines, 'key'),
        ))->toBe([]);
});

test('the decision flow is an accessible figure, not a decorative image', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // A diagram that carries the section's argument has to be readable to a
    // screen reader: a title and a description on the svg, and every node
    // label in the markup rather than drawn on a canvas.
    expect($html)->toContain('Engineering decision flow')
        ->toContain('A problem splits into a fast path')
        ->toContain('hiw-flow__svg')
        ->toContain('>REPRODUCE<')
        ->toContain('>GUARDRAIL<')
        ->toContain('>SHIP<');
});

test('the technical summary is the panel\'s readout row of five facts', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect(substr_count($html, 'hiw-fact__label'))->toBe(5)
        ->and($html)->toContain('Primary stack')
        ->toContain('Comfortable in')
        ->toContain('Worked on')
        ->toContain('Based in')
        ->toContain('Experience');

    // The row is folded into the instrument panel, not left as a separate
    // block under it. It is also the only renderer for the `focus` section,
    // so if it ever moves the admin-editable rows move with it or go dark.
    $panelStart = strpos($html, 'class="hiw-panel');
    $factsStart = strpos($html, 'class="hiw-facts"');
    $indexStart = strpos($html, 'hiw-index');

    expect($panelStart)->toBeGreaterThan(0)
        ->and($factsStart)->toBeGreaterThan($panelStart)
        ->and($indexStart)->toBeGreaterThan($factsStart);
});

test('the flow is wired to the rails and draws itself in segments', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // Every node is tagged with the branch it belongs to, which is what the
    // `:has()` rules read to light the matching rail. Without the fast tag the
    // left branch is just a bare node and the link silently stops working.
    expect(substr_count($html, 'hiw-node--fast'))->toBe(2)
        ->and(substr_count($html, 'hiw-node--deep'))->toBe(2);

    // Each edge is one subpath carrying its own draw delay, so the diagram
    // assembles in reading order instead of all at once.
    expect(substr_count($html, '--hiw-draw-delay'))->toBeGreaterThanOrEqual(12)
        ->and($html)->toContain('hiw-edge--live');
});

test('the six principles close the section as a numbered index', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect(substr_count($html, 'principle-card'))->toBeGreaterThanOrEqual(6)
        ->and($html)->toContain('Make the wrong thing hard to do')
        ->toContain('Delete code before adding a flag');
});
