<?php

use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The landing page's opening frame.
 *
 * The hero is the one section on the site that could plausibly be built as a
 * dark-only set piece — the previous one was, and it therefore ignored the
 * theme toggle and the accent picker. These tests hold the replacement to the
 * opposite contract: it may look like deep space, but every colour it uses is
 * a `--color-hx-*` role that resolves per theme, its atmosphere is hidden from
 * assistive technology, and its motion is something a visitor can switch off.
 *
 * The structural checks read the stylesheets from disk on purpose. A hero that
 * has quietly re-gained a hard-coded palette renders fine in a screenshot and
 * fails the site, so the token contract has to be assertable, not just
 * observable.
 */

$heroTokens = [
    '--color-hx-void',
    '--color-hx-void-deep',
    '--color-hx-vignette',
    '--color-hx-grid',
    '--color-hx-grid-soft',
    '--color-hx-panel',
    '--color-hx-line',
    '--color-hx-line-strong',
    '--color-hx-accent',
    '--color-hx-accent-soft',
    '--color-hx-on-accent',
    '--color-hx-ink',
    '--color-hx-ink-muted',
    '--color-hx-ink-faint',
    '--color-hx-star',
];

/**
 * The hero's own slice of the rendered page. The section holds no nested
 * <section>, so `betweenFirst` stops at its own closing tag — `Str::between`
 * would run to the *last* section on the page and pull in the whole document.
 */
function heroSlice(string $html): string
{
    return Str::betweenFirst($html, 'class="hx"', '</section>');
}

/**
 * Read a stylesheet off disk. A missing file throws rather than degrading to
 * an empty string, because a deleted stylesheet should read as a deleted
 * stylesheet — not as a palette that has quietly drifted.
 */
function stylesheet(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read stylesheet at {$path}");
    }

    return $contents;
}

/**
 * The byte offset of a needle, throwing when it is absent so the ordering
 * assertions below never end up comparing against `false`.
 */
function offsetOf(string $haystack, string $needle): int
{
    $offset = strpos($haystack, $needle);

    if ($offset === false) {
        throw new RuntimeException("Needle not found in the rendered page: {$needle}");
    }

    return $offset;
}

test('the hero opens the page and is labelled by its own heading', function () {
    $html = Livewire::test('pages::home')->html();

    expect($html)->toContain('data-test="home-hero"')
        ->and($html)->toContain('aria-labelledby="hero-heading"')
        ->and($html)->toContain('id="hero-heading"');

    // Ahead of the CI/CD band, which is the next thing a reader meets.
    expect(offsetOf($html, 'home-hero'))->toBeLessThan(offsetOf($html, 'id="cicd"'));
});

test('the hero states the name, the role and the availability from the profile', function () {
    Livewire::test('pages::home')
        ->assertSee('Shamim')
        ->assertSee('Hossain')
        ->assertSee('Full Stack Developer')
        ->assertSee(config('portfolio.author.availability'))
        ->assertSee(config('portfolio.author.tagline'));
});

test('the hero carries no colour of its own, so it cannot go stale in one theme', function () {
    $html = Livewire::test('pages::home')->html();
    $hero = heroSlice($html);

    // The root element is one class name. Every colour decision is made in the
    // stylesheet, against a token, per theme.
    expect($html)->toContain('class="hx"');

    // None of the hero's own classes reach for a Tailwind palette — the retired
    // hero opened with `bg-[#030816]`, `text-white` and a cyan/emerald pair.
    // Flux's buttons are a shared component that keeps its own vocabulary, so
    // the check is scoped to the `hx*` names rather than to the whole subtree.
    preg_match_all('/class="(hx[^"]*)"/', $hero, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $classes) {
        expect($classes)->not->toMatch(
            '/\b(text|bg|border|ring|from|via|to)-(white|black|slate|gray|zinc|neutral|stone|cyan|emerald|sky|blue|indigo|violet|rose|amber|yellow|green|teal|red)-/'
        );
    }
});

test('every inline style in the hero declares a custom property and nothing else', function () {
    $hero = heroSlice(Livewire::test('pages::home')->html());

    preg_match_all('/style="([^"]*)"/', $hero, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $declarations) {
        foreach (explode(';', $declarations) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }

            expect(trim($declaration))->toStartWith('--');
        }
    }
});

test('the hero hides its atmosphere from assistive technology', function () {
    $hero = heroSlice(Livewire::test('pages::home')->html());

    foreach ([
        'hx__void',
        'hx__bloom',
        'hx__horizon',
        'hx__stars',
        'hx__plane',
        'hx__scan',
        'hx__frame',
        'hx__core',
    ] as $layer) {
        expect($hero)->toContain('class="'.$layer.'" aria-hidden="true"');
    }
});

test('the hero calls to action land on destinations that exist', function () {
    $html = Livewire::test('pages::home')->html();

    // Both hero CTAs are page routes rather than in-page anchors: the primary
    // sends the visitor to the about page, the secondary to the writing index.
    // `#work` used to be the primary's target; it no longer is, so the label
    // and the named route are what identify it now.
    expect($html)->toContain('About me')
        ->and($html)->toContain('href="'.route('about').'"')
        ->and($html)->toContain(route('blog.index'));

    // The sections still exist, so a reader arriving from a nav link, the
    // footer or a shared URL still lands somewhere.
    expect($html)->toContain('id="work"')
        ->and($html)->toContain('id="contact"');
});

test('the retired dark-only hero is gone', function () {
    $html = Livewire::test('pages::home')->html();

    expect($html)->not->toContain('brain-hero')
        ->and($html)->not->toContain('Engineering Mindset')
        ->and($html)->not->toContain('System 1');
});

test('every hero role is declared once for the light theme and again for the dark one', function () use ($heroTokens) {
    $css = stylesheet(resource_path('css/app.css'));
    $darkStart = offsetOf($css, '.dark {');

    $light = substr($css, 0, $darkStart);
    $dark = substr($css, $darkStart);

    foreach ($heroTokens as $token) {
        expect($light)->toContain($token.':')
            ->and($dark)->toContain($token.':');
    }
});

test('the hero stylesheet is wired into the build and introduces no hue of its own', function () {
    $css = stylesheet(resource_path('css/app.css'));

    expect($css)->toContain("@import './hero-deck.css';");

    $deck = stylesheet(resource_path('css/hero-deck.css'));

    // Everything the stylesheet colours, it colours through a role.
    expect(substr_count($deck, 'var(--color-hx-'))->toBeGreaterThan(30);

    // No hue is introduced. `#000` is exempt because it is the mask idiom —
    // a mask reads alpha rather than colour, so black and white are the same
    // opaque stop there and the value is not a palette decision.
    expect($deck)->not->toMatch('/#(?!000\b)[0-9a-fA-F]{3,8}\b/');
});

test('the hero roles are aliases onto the ink and signal scales, not a second palette', function () use ($heroTokens) {
    $css = stylesheet(resource_path('css/app.css'));
    $darkStart = offsetOf($css, '.dark {');

    $scopes = ['light' => substr($css, 0, $darkStart), 'dark' => substr($css, $darkStart)];

    foreach ($heroTokens as $token) {
        foreach ($scopes as $theme => $scope) {
            preg_match('/'.preg_quote($token, '/').':\s*([^;]+);/', $scope, $matches);

            if (! isset($matches[1])) {
                throw new RuntimeException("{$token} is not declared for the {$theme} theme");
            }

            $value = trim($matches[1]);

            // Aliased onto the site's own scales, or one of the three fixed
            // stops that are deliberately literal — the panel fill, the ink
            // that sits on a filled accent chip, and the starfield's white
            // pinpricks — or an alpha shadow.
            expect($value)->toMatch(
                '/^var\(--color-(ink|signal|white)(-\d+)?\)$|^#ffffff$|^rgb\(/',
                "{$token} resolves to `{$value}` in the {$theme} theme, which is not an ink/signal alias"
            );
        }
    }
});

test('the hero entrance is gated behind no-preference and the ambient loops are opt-out', function () {
    $deck = stylesheet(resource_path('css/hero-deck.css'));

    expect($deck)->toContain('@media (prefers-reduced-motion: no-preference)')
        ->and($deck)->toContain('@media (prefers-reduced-motion: reduce)');

    $noPreference = Str::betweenFirst(
        $deck,
        '@media (prefers-reduced-motion: no-preference)',
        '@keyframes hx-wipe'
    );

    // The entrance choreography lives inside the no-preference query, so a
    // visitor who asked for less motion is handed the finished composition
    // rather than a composition that has to be animated into place.
    expect($noPreference)->toContain('hx-wipe')
        ->and($noPreference)->toContain('hx-rise')
        ->and($noPreference)->toContain('hx-core-in')
        ->and($noPreference)->toContain('hx-bracket-in');

    $reduce = Str::after($deck, '@media (prefers-reduced-motion: reduce)');

    foreach ([
        'hx__star',
        'hx__stars-drift',
        'hx__floor',
        'hx__ring',
        'hx__orbit',
        'hx__sweep',
        'hx__pip',
        'hx__scroll-track::after',
        'hx__scan',
    ] as $ambient) {
        expect($reduce)->toContain('.'.$ambient);
    }
});

test('the landing page renders the hero for a guest', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-test="home-hero"', false);
});
