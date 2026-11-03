<?php

use App\Enums\AboutItemType;
use App\Enums\AboutSectionType;
use App\Models\AboutItem;
use App\Models\AboutSection;

/*
|--------------------------------------------------------------------------
| About page — section 02 thinking explorer
|--------------------------------------------------------------------------
|
| Section 02 is an annotated plate: a rail of the six disciplines on the
| left, the 3D brain on the right, and one caption underneath that reads out
| whatever is under the pointer. The two thinking systems are a switch on the
| stage rather than a caption beside it.
|
| These tests pin the parts that could quietly rot:
|
| 1. The band is genuinely interactive. The renderer is a canvas with a
|    static fallback under it, the labels are real buttons, and the
|    content payload is present and addressable.
| 2. Hover previews and click selects, and the two are separate. A
|    selection must survive a hover elsewhere.
| 3. The mode switch names the two systems, and the operator-managed
|    name and description are rendered as well — a renamed system is
|    visible, not swallowed.
| 4. The mind map is drawn before JavaScript runs: the edges and the
|    nodes are in the markup, so the graph is never blank.
| 5. Nothing depends on hover alone — the readout is an aria-live
|    region and every control is a button with aria-pressed.
| 6. The rows are stationary. They are the only route to a discipline,
|    so they must not ride the rotating geometry; a decorative numbered
|    anchor and a single live leader line carry the live position
|    instead.
| 7. The scene moves on its own, so it can be stopped, and it can be
|    turned without a pointer.
|
| The value claims used to render under the band. They are section 02's
| specification now — see AboutEngagementSheetTest.
|
*/

test('the explorer stage is a canvas scene with a static fallback beneath it', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toContain('te-stage--explorer')
        ->toContain('x-ref="canvas"')
        ->toContain('x-ref="fallback"')
        ->toContain('te-brain__fallback')
        ->toContain('te-brain__canvas');
});

test('the explorer publishes its content payload for the renderer to read', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // Alpine's tree walk skips <script> elements, so the payload is
    // addressed by attribute. The attribute is explorer-specific so the
    // section 04 band can never read this one, or the other way round.
    expect($html)->toContain('data-thinking-explorer-payload')
        ->toContain('type="application/json"');
});

test('the six disciplines are stationary rows in a rail, not tags pinned to the geometry', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // One row per discipline, each anchored by key. The rows are laid out by
    // the browser, so "stationary" is now a property of the layout rather
    // than a percentage the renderer has to keep its hands off. That is the
    // whole reason the rail replaced the floating tags: a label that cannot
    // move cannot drift out from under the cursor.
    expect(substr_count($html, 'data-hub-key='))->toBe(6);

    expect($html)->toContain('te-hubs')
        ->toContain('te-hub__dot')
        ->toContain('te-hub__name')
        ->toContain('te-hub__index');
});

test('the moving half is a separate decorative layer, and only one leader is live', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // A label that rides the rotating geometry drifts out from under the
    // cursor and becomes unreachable, and a discipline whose cluster
    // rotated behind the cortex disappears entirely. So the label holds its
    // place in the rail, a decorative numbered anchor rides the projected
    // cluster, and a leader line joins the two.
    expect(substr_count($html, 'data-anchor-key='))->toBe(6)
        ->and(substr_count($html, 'data-lead-key='))->toBe(6)
        ->and($html)->toContain('te-anchors')
        ->and($html)->toContain('te-leads')
        ->and($html)->toContain('te-anchor')
        ->and($html)->toContain('te-lead');

    // Six permanent leaders crossed the cortex and each other and said
    // nothing the numerals did not already say. The line is now drawn only
    // for the discipline under the pointer.
    expect($html)->toContain("&& 'is-live'");
});

test('the scene can be turned with the keyboard and says so', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // The scene is a focusable group; the arrow keys turn the model, so
    // the visitor is not at the mercy of the ambient sway.
    expect($html)->toContain('x-ref="scene"')
        ->toContain('tabindex="0"')
        ->toContain('role="group"')
        ->toContain('onSceneKeydown($event)')
        ->toContain('Interactive brain model. Use the arrow keys to turn it.');
});

test('the scene can be stopped because it moves on its own', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // WCAG 2.2.2: auto-updating content that moves for more than five
    // seconds needs a way to pause it. The switch is a real button with a
    // pressed state, and when the system asks for reduced motion the band
    // says so honestly instead of offering a switch it cannot honour.
    expect($html)->toContain('te-motion__toggle')
        ->toContain('toggleMotion()')
        ->toContain(':aria-pressed="motionPaused ? \'true\' : \'false\'"')
        ->toContain('Pause the motion')
        ->toContain('Resume the motion')
        ->toContain('Motion reduced by your system setting');
});

test('hovering a discipline previews it and clicking it selects it', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // Preview and selection are separate bindings. A preview never
    // changes what is selected; only the click does.
    expect($html)->toContain('@mouseenter="preview = ')
        ->toContain('@mouseleave="preview = null"')
        ->toContain('@focus="preview = ')
        ->toContain('@blur="preview = null"')
        ->toContain("@click=\"toggle('")
        ->toContain(':aria-pressed="active === ');
});

test('the mode switch names the two systems and does not carry operator copy', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // The systems themselves are fixed by the taxonomy, so the switch
    // says "System 1" / "System 2" and never the stored name — the
    // stored name is rendered by the legend instead. Scoped to the switch
    // itself: the page renders those words in the legend too, so a
    // page-wide contains() would prove nothing.
    preg_match('/<div class="te-modes"[^>]*>(.*?)<\/div>/s', $html, $match);
    $switch = $match[1] ?? '';

    expect($switch)->toContain('System 1')
        ->toContain('System 2')
        ->and($html)->toContain('aria-label="Thinking mode"');
});

test('the operator-managed mode name and description are rendered', function () {
    $section = AboutSection::factory()->create(['type' => AboutSectionType::Reasons]);

    AboutItem::factory()->create([
        'about_section_id' => $section->id,
        'type' => AboutItemType::ThinkingMode,
        'title' => 'Instinctive Reach',
        'subtitle' => 'system_1',
        'body' => 'The half that already knows the answer.',
    ]);

    $html = $this->get(route('about'))->assertOk()->getContent();

    // A renamed system must be visible on the page, not only in the
    // payload the browser reads.
    expect($html)->toContain('Instinctive Reach')
        ->toContain('The half that already knows the answer.')
        ->toContain('te-mode-legend');
});

test('the mind map is drawn in the markup so it is never blank', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // The edges and the node buttons are server-rendered, so the graph
    // is correct before JavaScript runs and stays readable if it never
    // runs at all.
    expect($html)->toContain('te-map__edges')
        ->toContain('te-map__edge')
        ->toContain('te-map__core')
        ->toContain('te-node')
        ->toContain('viewBox="0 0 400 300"');
});

test('the graph drawer has a way out that does not need a pointer', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // The drawer overlays the whole stage, so it must be closable by
    // keyboard: Escape dismisses it, and the back button names what it
    // does.
    expect($html)->toContain('te-drawer')
        ->toContain('@keydown.escape.window')
        ->toContain('Back to the brain');
});

test('the explorer readout is an aria-live region so nothing depends on hover', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toContain('te-readout')
        ->toContain('aria-live="polite"')
        ->toContain('x-text="readout"');
});

test('the explorer says which renderer is live so the fallback is not mistaken for a fault', function () {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toContain('te-renderer')
        ->toContain("x-show=\"renderer === 'webgl'\"")
        ->toContain("x-show=\"renderer === 'canvas'\"")
        ->toContain('Canvas fallback');
});
