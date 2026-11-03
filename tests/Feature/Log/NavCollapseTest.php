<?php

/**
 * The log's navigation rail, collapsed.
 *
 * The rail's collapse control used to hide only the labels: `.dn-nav-label`
 * went to `max-width: 0` while `.dn-nav` kept `width: var(--dn-nav-column)`
 * (256px) and `.dn-main` kept `calc(876px - var(--dn-nav-column))`. Collapsing
 * therefore reclaimed nothing — a 256px column held a 20px icon and about 212px
 * of dead space, and the feed column never grew to use it.
 *
 * These are computed-value rules living in `log.css`, and no PHP test can
 * measure a laid-out box. What it *can* do is pin the contract that makes the
 * measurement come out right in a browser: both the rail and the feed column
 * must read the same live variable, the collapsed state must assign it, and the
 * icons must be centred rather than left-aligned in a rail built for labels.
 *
 * Verified in headless Chrome at 1024/1284/1568px: collapsed gives
 * `--dn-nav-live: 76px`, `.dn-main` max-width 800px (was 620px), and each row's
 * icon inset 28px/28px.
 */
function logCss(): string
{
    $css = file_get_contents(resource_path('css/log.css'));

    expect($css)->not->toBeFalse();

    return $css;
}

test('the nav column has a collapsed value and a live value that reads it', function () {
    $css = logCss();

    // Two constants and one live variable: a custom property cannot reference
    // itself, so the collapse has to swap a separate `--dn-nav-live`.
    expect($css)
        ->toContain('--dn-nav-column: 256px')
        ->toContain('--dn-nav-collapsed: 76px')
        ->toContain('--dn-nav-live: var(--dn-nav-column)');
});

test('the collapsed shell assigns the live nav variable', function () {
    $css = logCss();

    // The one assignment the whole collapse turns on. Without it the rail keeps
    // its expanded width and the labels vanish into a 256px empty column.
    expect($css)->toMatch(
        "/\.dn-shell\[data-nav='collapsed'\]\s*\{\s*--dn-nav-live:\s*var\(--dn-nav-collapsed\);/"
    );
});

test('both the rail and the feed column read the live variable', function () {
    $css = logCss();

    // The rail and the column must never disagree: if `.dn-main` read
    // `--dn-nav-column` while the rail read `--dn-nav-live`, collapsing would
    // shrink one and not the other and the reclaimed space would become gap.
    expect($css)
        ->toContain('width: var(--dn-nav-live)')
        ->toContain('max-width: calc(876px - var(--dn-nav-live))')
        // The messenger needs the aside's room and computes its own ceiling
        // from the shell max; it has to follow the rail too.
        ->toContain('max-width: calc(var(--dn-shell-max) - var(--dn-nav-live))');

    // No width rule may still be pinned to the expanded constant, or the rail
    // would snap back to 256px the moment someone touched the collapsed state.
    expect($css)->not->toContain('width: var(--dn-nav-column)');
});

test('the collapsed rail centres its rows instead of leaving icons adrift', function () {
    $css = logCss();

    // A row built for a label is `justify-content: flex-start` with a 12px icon
    // gap and 16px of leading padding. Left alone in a 76px rail that puts the
    // icon 16px off-centre — the tell that a rail was collapsed by hiding text
    // rather than by resizing. Every row that survives into the rail has to
    // re-centre: the plain item, the primary button, the brand and the user chip.
    foreach ([
        '.dn-nav-item',
        '.dn-nav-primary',
        '.dn-brand',
        '.dn-user',
        '.dn-nav-inner',
    ] as $target) {
        // `strpos` rather than Pest's `toContain`: the selectors carry `.` and
        // `-`, which a pattern-flavoured matcher reads as syntax.
        $selector = ".dn-shell[data-nav='collapsed'] {$target}";

        expect(strpos($css, $selector))->not->toBeFalse(
            "The collapsed rail never re-centres {$target}."
        );
    }
});

test('the collapse control promises the direction it will move', function () {
    $css = logCss();

    // Closed, the same row reopens the rail, so its chevron mirrors. A control
    // whose icon keeps pointing left after the rail has closed is a control that
    // looks like it will close something that is already closed.
    expect($css)
        ->toContain(".dn-shell[data-nav='collapsed'] .dn-nav-collapse svg")
        ->toContain('transform: rotate(180deg)');
});

test('the collapse animates on the widths, and stops when motion is unwanted', function () {
    $css = logCss();

    expect($css)
        ->toContain('--dn-nav-transition:')
        ->toContain('transition: width var(--dn-nav-transition)')
        ->toContain('transition: max-width var(--dn-nav-transition)');

    // The rail, the column and the labels share one clock, and all of them stop
    // for `prefers-reduced-motion`.
    expect($css)->toContain('@media (prefers-reduced-motion: reduce)');
});

test('the rail remembers the reader’s choice across navigation', function () {
    $js = file_get_contents(resource_path('js/log-shell.js'));

    expect($js)->not->toBeFalse();

    // `wire:navigate` re-renders the shell with every page, so the markup's
    // `expanded` default would spring the rail back open on every link unless
    // the choice is stored and reapplied. The button calls the component rather
    // than mutating the dataset inline, so there is one place that writes both.
    expect($js)
        ->toContain('restoreNav()')
        ->toContain('toggleNav()')
        ->toContain("window.localStorage.setItem('dn.nav'");

    $frame = file_get_contents(resource_path('views/components/log/frame.blade.php'));

    expect($frame)->not->toBeFalse()
        ->and($frame)->toContain('x-on:click="toggleNav()"')
        // The inline dataset mutation is what this replaced.
        ->and($frame)->not->toContain(".dataset.nav = \$el.closest('.dn-shell').dataset.nav === 'expanded'");
});
