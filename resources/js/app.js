/**
 * DevFolio client behaviour.
 *
 * Deliberately small. Everything here is progressive enhancement: the site is
 * fully functional with JavaScript disabled, and each block below degrades to
 * a working (if plainer) experience rather than a broken one.
 *
 * Alpine handles component-local state (share bar, nav disclosure) and is
 * bundled by Livewire. This file covers the behaviours that need to observe
 * the whole document: reading progress, table-of-contents highlighting,
 * scroll reveals, and code-block copy buttons.
 */

/**
 * Editor AI client, the rich text editor's Alpine component, the Developer Lab
 * sandbox, and the About page's two thinking explorers.
 *
 * Imported for their side effects: each attaches itself to `window` so the
 * Blade component can reference them by name from an `x-data` attribute without
 * the template having to know how the bundle is split.
 */
import './editor-ai.js';
import './rich-text-editor.js';
import './lab-sandbox.js';
import './sound.js';
import './thinking-explorer.js';
import './log-shell.js';

import { initInteractionSound, playAmbient } from './sound.js';

/**
 * Reading progress.
 *
 * Writes a 0–1 value into a CSS custom property on the element rather than
 * animating a property directly, so the compositor handles the transform and
 * the main thread stays free during scroll.
 *
 * Uses a rAF-throttled scroll listener instead of an IntersectionObserver
 * because progress is a continuous function of scroll position, not a
 * threshold crossing.
 */
function initReadingProgress() {
    const bar = document.querySelector('[data-reading-progress]');

    if (! bar) {
        return;
    }

    const targetSelector = bar.dataset.progressTarget || '[data-reading-target]';

    let ticking = false;

    const update = () => {
        ticking = false;

        const article = document.querySelector(targetSelector);

        if (! article) {
            return;
        }

        const rect = article.getBoundingClientRect();
        const viewport = window.innerHeight;
        const scrollable = rect.height - viewport;

        // Article shorter than the viewport: treat it as fully read.
        const ratio = scrollable <= 0
            ? 1
            : Math.min(1, Math.max(0, -rect.top / scrollable));

        bar.style.setProperty('--progress', ratio.toFixed(4));
        bar.setAttribute('aria-valuenow', String(Math.round(ratio * 100)));
    };

    const onScroll = () => {
        if (ticking) {
            return;
        }

        ticking = true;
        window.requestAnimationFrame(update);
    };

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });

    update();
}

/**
 * Table-of-contents highlighting.
 *
 * An IntersectionObserver tracks every heading the TOC links to. The heading
 * nearest the top of the viewport wins, which is what readers expect: the TOC
 * reflects the section you are currently inside, not the one you just passed.
 */
function initTableOfContents() {
    const toc = document.querySelector('[data-toc]');

    if (! toc) {
        return;
    }

    const links = Array.from(toc.querySelectorAll('[data-toc-target]'));

    if (links.length === 0) {
        return;
    }

    const headings = links
        .map((link) => document.getElementById(link.dataset.tocTarget))
        .filter((el) => el !== null);

    if (headings.length === 0) {
        return;
    }

    const setActive = (id) => {
        links.forEach((link) => {
            const isActive = link.dataset.tocTarget === id;
            link.dataset.active = isActive ? 'true' : 'false';

            if (isActive) {
                link.setAttribute('aria-current', 'location');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    // Track which headings are currently intersecting, then pick the topmost.
    const visible = new Set();

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    visible.add(entry.target.id);
                } else {
                    visible.delete(entry.target.id);
                }
            });

            if (visible.size === 0) {
                return;
            }

            // Order by document position, not insertion order.
            const topmost = headings.find((heading) => visible.has(heading.id));

            if (topmost) {
                setActive(topmost.id);
            }
        },
        {
            // Treat the band between 96px and 40% viewport height as "current".
            rootMargin: '-96px 0px -60% 0px',
            threshold: 0,
        },
    );

    headings.forEach((heading) => observer.observe(heading));

    // Optional slide-in: a nav element carrying data-toc-reveal stays
    // off-canvas until the first section has scrolled up past the reading
    // band, so the hero opens clean and the TOC arrives as reading starts.
    //
    // The rail is fixed to the viewport's right edge, so at the bottom of the
    // page it would sit on top of the footer's link columns. Anything marked
    // data-toc-dismiss (the site footer) pushes it back off-canvas as soon as
    // it reaches the rail's lower edge, and it slides back in on the way up.
    if (toc.hasAttribute('data-toc-reveal')) {
        const dismiss = document.querySelector('[data-toc-dismiss]');

        let revealTicking = false;

        const updateReveal = () => {
            const first = headings[0];
            const pastHero = ! first || first.getBoundingClientRect().bottom < 96;

            // The rail is vertically centred: its lower edge sits at
            // half the viewport plus half its own height.
            const railBottom = window.innerHeight / 2 + toc.offsetHeight / 2;
            const atFooter = dismiss !== null
                && dismiss.getBoundingClientRect().top <= railBottom;

            toc.dataset.visible = pastHero && ! atFooter ? 'true' : 'false';
            revealTicking = false;
        };

        const queueReveal = () => {
            if (revealTicking) {
                return;
            }

            revealTicking = true;
            window.requestAnimationFrame(updateReveal);
        };

        window.addEventListener('scroll', queueReveal, { passive: true });
        window.addEventListener('resize', queueReveal, { passive: true });

        updateReveal();
    }
}

/**
 * Section rail collapse.
 *
 * The rail rests as a narrow column of icons so it never competes with the
 * page it indexes. Hovering it widens the rail to reveal the labels; the
 * chevron above the column pins that choice — open, shut, or back to
 * following the pointer — and a pin outranks the hover so a reader who has
 * chosen a state keeps it.
 *
 * The pin is written to the rail as data-pinned and mirrored onto the
 * toggle's aria-expanded, so the stylesheet owns the reveal and the script
 * only owns the decision. Without JS the rail still previews on hover.
 */
function initTocToggle() {
    const toc = document.querySelector('[data-toc]');
    const toggle = toc?.querySelector('[data-toc-toggle]');

    if (! toc || ! toggle || toc.dataset.tocToggleReady === 'true') {
        return;
    }

    toc.dataset.tocToggleReady = 'true';

    // null means "no pin yet" — the rail follows the pointer. 'open' pins
    // the labels open, 'shut' pins them shut.
    let pinned = null;
    let hovering = false;

    const render = () => {
        // The pin is only written once the reader has chosen one; until then
        // the stylesheet's hover rule is the only rule in play.
        if (pinned === null) {
            delete toc.dataset.pinned;
        } else {
            toc.dataset.pinned = pinned;
        }

        const expanded = pinned === null ? hovering : pinned === 'open';

        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    };

    toc.addEventListener('pointerenter', () => {
        hovering = true;
        render();
    });

    toc.addEventListener('pointerleave', () => {
        hovering = false;
        render();
    });

    toggle.addEventListener('click', () => {
        // A plain two-state toggle. The rail follows the pointer until the
        // reader first clicks, and from then the pin wins over the hover.
        pinned = pinned === 'open' ? 'shut' : 'open';

        render();
    });

    render();
}

/**
 * Reveal-on-scroll — mirrored, AOS-style.
 *
 * Elements marked `data-reveal` fade up as they enter the viewport and fade
 * back out when they leave, so sections settle in on the way down the page
 * and give way again on the way up. Opt-in per element. The observer is
 * module-level and persists across Livewire navigations: the visible state
 * now toggles with the scroll, so nothing is ever "done" and unobserved, and
 * re-observing an already-observed target is a spec-level no-op.
 */
let revealObserver = null;

function initReveal() {
    const targets = document.querySelectorAll('[data-reveal]');

    if (targets.length === 0) {
        return;
    }

    // Respect the user's motion preference — show everything immediately.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        targets.forEach((el) => el.setAttribute('data-visible', 'true'));

        return;
    }

    if (revealObserver === null) {
        revealObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.setAttribute('data-visible', 'true');
                    } else {
                        entry.target.removeAttribute('data-visible');
                    }
                });
            },
            {
                // A band below the fold delays the fade-in; any pixel of the
                // element inside the remaining viewport keeps it shown, so
                // tall sections never flicker while straddling the edge.
                rootMargin: '0px 0px -80px 0px',
                threshold: 0,
            },
        );
    }

    targets.forEach((el) => revealObserver.observe(el));
}

/**
 * Code block copy buttons.
 *
 * Adds a copy affordance to every fenced code block in an article. Injected
 * client-side so the server-rendered, sanitised HTML stays minimal and the
 * button never appears in a context where JS cannot make it work.
 */
function initCodeCopy() {
    const blocks = document.querySelectorAll('.prose-editorial pre:not([data-copy-ready])');

    if (blocks.length === 0) {
        return;
    }

    blocks.forEach((block) => {
        const code = block.querySelector('code');

        if (! code) {
            return;
        }

        block.dataset.copyReady = 'true';
        block.classList.add('group/code');

        const button = document.createElement('button');
        button.type = 'button';
        button.className =
            'absolute left-3 top-2.5 rounded-xs border border-ink-200 bg-white/80 px-1.5 py-0.5 font-mono text-2xs text-ink-400 opacity-0 backdrop-blur transition-opacity hover:text-ink-700 focus-visible:opacity-100 group-hover/code:opacity-100 dark:border-ink-700 dark:bg-ink-900/80 dark:text-ink-500 dark:hover:text-ink-100';
        button.textContent = 'copy';
        button.setAttribute('aria-label', 'Copy code to clipboard');

        button.addEventListener('click', async () => {
            const text = code.textContent ?? '';

            try {
                await navigator.clipboard.writeText(text);
                button.textContent = 'copied';
            } catch {
                // Fall back to selecting the block so the reader can copy it.
                const range = document.createRange();
                range.selectNodeContents(code);

                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);

                button.textContent = 'select';
            }

            window.setTimeout(() => {
                button.textContent = 'copy';
            }, 2200);
        });

        block.appendChild(button);
    });
}

/**
 * Typewriter effect for the About hero headline.
 *
 * Progressive enhancement: the server renders the full text, so a no-JS
 * reader still gets the headline. With JS we clear it and type it out once,
 * then leave it still.
 */
function initTypewriter() {
    const targets = document.querySelectorAll('[data-typewriter]:not([data-typed])');

    if (targets.length === 0) {
        return;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        targets.forEach((el) => {
            el.textContent = el.dataset.text || el.textContent;
            el.dataset.typed = 'done';
        });
        return;
    }

    targets.forEach((el) => {
        const full = el.dataset.text || '';
        if (! full) {
            el.dataset.typed = 'done';
            return;
        }

        el.dataset.typed = 'running';
        el.textContent = '';

        let i = 0;
        const tick = () => {
            if (i <= full.length) {
                el.textContent = full.slice(0, i);
                i += 1;
                window.setTimeout(tick, 26);
            } else {
                el.dataset.typed = 'done';
            }
        };

        window.setTimeout(tick, 200);
    });
}

/**
 * Cycling typewriter for the hero subtitle.
 *
 * Not a character-by-character typewriter: the whole phrase appears at once
 * as random glyphs and then decodes left to right, one character per tick —
 * the scramble-decode effect. Spaces hold their real value, so the shape of
 * the sentence is visible while the rest is still noise. After a full
 * resolve it holds, then the next phrase scrambles in at its full length.
 * With JS off the server-rendered phrase stays complete.
 *
 * The line is written as two parts — the characters that have already
 * locked in, and the ones still standing in for them — because a decode
 * that renders as one undifferentiated string is not legible *as* a
 * decode: the eye cannot tell signal from noise until the whole phrase
 * has arrived. Two spans let the CSS mark the difference, which is also
 * what gives the glitch sound something to point at.
 */
const SCRAMBLE_GLYPHS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789$#&/%*<>';

const scrambleTickMs = 45;
const scrambleHoldMs = 1750;

/**
 * The noisy tail of a phrase: every character but the spaces replaced by
 * a random glyph, so the sentence's shape stays readable through the
 * noise.
 */
function scrambleNoise(phrase) {
    return phrase
        .split('')
        .map((char) => {
            if (char === ' ') {
                return char;
            }

            return SCRAMBLE_GLYPHS[Math.floor(Math.random() * SCRAMBLE_GLYPHS.length)];
        })
        .join('');
}

function initCycleTypewriter() {
    const targets = document.querySelectorAll('[data-cycle-el]:not([data-cycled])');

    if (targets.length === 0) {
        return;
    }

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    targets.forEach((el) => {
        const phrases = (el.dataset.cycle || '').split(',').map((p) => p.trim()).filter(Boolean);

        if (phrases.length === 0) {
            el.dataset.cycled = 'done';
            return;
        }

        if (reduced) {
            el.textContent = phrases[0];
            el.dataset.cycled = 'done';
            return;
        }

        el.dataset.cycled = 'running';

        // The two halves of the line. `signal` is the text that has
        // locked in; `noise` is the glyph standing in for the rest.
        const signal = document.createElement('span');
        signal.className = 'about-decode-signal';

        const noise = document.createElement('span');
        noise.className = 'about-decode-noise';

        el.textContent = '';
        el.append(signal, noise);

        let phraseIndex = 0;
        let resolved = 0;

        // The sound is the punctuation, not the typing. One blip per
        // character would be a drone, so the noise gets a single burst
        // as the phrase breaks apart and a clean tone as it locks in —
        // the two moments the eye actually registers.
        //
        // And it plays once, on the first phrase only. The line loops
        // forever, so a sound on every pass is a loop, and a loop is
        // exactly how a sound starts to grate: the reader is trying to
        // read, and the effect keeps announcing itself. One burst as the
        // terminal boots is a flourish; every four seconds is a nuisance.
        let sounded = false;

        const tick = () => {
            const phrase = phrases[phraseIndex];

            if (resolved < phrase.length) {
                resolved += 1;
                signal.textContent = phrase.slice(0, resolved);
                noise.textContent = scrambleNoise(phrase.slice(resolved));

                if (resolved === 1 && ! sounded) {
                    sounded = true;
                    playAmbient('glitch');
                }

                window.setTimeout(tick, scrambleTickMs);
                return;
            }

            signal.textContent = phrase;
            noise.textContent = '';

            if (resolved === 1) {
                playAmbient('lock');
            }

            window.setTimeout(() => {
                phraseIndex = (phraseIndex + 1) % phrases.length;
                resolved = 0;
                tick();
            }, scrambleHoldMs);
        };

        signal.textContent = phrases[0];
        window.setTimeout(tick, 450);
    });
}

/**
 * Magnetic buttons.
 *
 * Each `[data-magnetic]` wrapper leans a few pixels toward the pointer while
 * it moves over the button and springs straight back on leave. Fine pointers
 * only — touch has no hover to borrow. Reduced-motion users get a static
 * button. (The pulse rings on the primary pill are pure CSS, not this.)
 */
function initMagnetic() {
    const targets = document.querySelectorAll('[data-magnetic]:not([data-magnetic-ready])');

    if (targets.length === 0) {
        return;
    }

    if (
        ! window.matchMedia('(pointer: fine)').matches ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        return;
    }

    const strength = 9;
    const maxOffset = 7;

    targets.forEach((wrapper) => {
        wrapper.dataset.magneticReady = 'true';

        wrapper.addEventListener('pointermove', (event) => {
            const rect = wrapper.getBoundingClientRect();
            const x = (event.clientX - rect.left - rect.width / 2) / strength;
            const y = (event.clientY - rect.top - rect.height / 2) / strength;
            const clampedX = Math.max(-maxOffset, Math.min(maxOffset, x));
            const clampedY = Math.max(-maxOffset, Math.min(maxOffset, y));

            wrapper.style.transform = `translate(${clampedX.toFixed(2)}px, ${clampedY.toFixed(2)}px)`;
        });

        wrapper.addEventListener('pointerleave', () => {
            wrapper.style.transform = '';
        });
    });
}

/**
 * Count-up figures for the About stats band.
 *
 * Progressive enhancement: the server render contains the final value, so a
 * no-JS reader sees the real numbers. With JS, the numeric part of each
 * `[data-countup]` figure counts up once, the first time it enters the
 * viewport; a suffix ("+", "M") rides along and a `data-count-pad` of 2
 * zero-pads the display ("9" → "09"), matching the band's mono style.
 */
function initStatCounters() {
    const targets = document.querySelectorAll('[data-countup]:not([data-counted])');

    if (targets.length === 0) {
        return;
    }

    targets.forEach((el) => {
        el.dataset.counted = 'true';

        // Non-numeric or malformed pairs render static — skip quietly.
        if (! el.hasAttribute('data-count-target')) {
            return;
        }

        const target = Number(el.dataset.countTarget);

        if (! Number.isFinite(target)) {
            return;
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const pad = Number(el.dataset.countPad || 0) || 0;
        const suffix = el.dataset.countSuffix || '';

        const format = (value) => String(Math.round(value)).padStart(pad, '0') + suffix;

        const animate = () => {
            const duration = 1200;
            let start;

            const frame = (now) => {
                if (start === undefined) {
                    start = now;
                }

                const progress = Math.min(1, (now - start) / duration);
                const eased = 1 - Math.pow(1 - progress, 3);

                el.textContent = format(target * eased);

                if (progress < 1) {
                    window.requestAnimationFrame(frame);
                }
            };

            el.textContent = format(0);
            window.requestAnimationFrame(frame);
        };

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    observer.disconnect();
                    animate();
                }
            },
            { threshold: 0.4 },
        );

        observer.observe(el);
    });
}

/**
 * Writing-carousel pagination (About page, section 07).
 *
 * The carousel is a scroll-snap strip; this wires the header stepper and the
 * 01/NN counter to its scroll position. Buttons scroll one card at a time and
 * disable at the ends; scroll position decides which card the counter names.
 * Idempotent like every initialiser: buttons already carrying the listener
 * (found via a private symbol-free flag) are skipped.
 */
function initWritingCarousel() {
    document.querySelectorAll('[data-carousel-viewport]').forEach((viewport) => {
        if (viewport.dataset.carouselBound === 'true') {
            return;
        }

        viewport.dataset.carouselBound = 'true';

        const root = viewport.closest('section');
        const prev = root?.querySelector('[data-carousel-prev]');
        const next = root?.querySelector('[data-carousel-next]');
        const counter = root?.querySelector('[data-carousel-counter]');
        const cards = Array.from(viewport.children);

        if (prev === null || next === null || cards.length === 0) {
            return;
        }

        const step = () => (cards[0]?.getBoundingClientRect().width ?? 0) + 16;

        const indexFor = () => Math.round(viewport.scrollLeft / Math.max(1, step()));

        const sync = () => {
            const index = Math.min(cards.length - 1, Math.max(0, indexFor()));

            if (counter !== null) {
                counter.textContent = String(index + 1).padStart(2, '0');
            }

            prev.disabled = viewport.scrollLeft <= 2;
            next.disabled = viewport.scrollLeft >= viewport.scrollWidth - viewport.clientWidth - 2;
        };

        prev.addEventListener('click', () => {
            viewport.scrollBy({ left: -step(), behavior: 'smooth' });
        });

        next.addEventListener('click', () => {
            viewport.scrollBy({ left: step(), behavior: 'smooth' });
        });

        let ticking = false;

        viewport.addEventListener('scroll', () => {
            if (ticking) {
                return;
            }

            ticking = true;
            window.requestAnimationFrame(() => {
                ticking = false;
                sync();
            });
        }, { passive: true });

        window.addEventListener('resize', sync, { passive: true });
        sync();
    });
}

/**
 * About §04 capability stage: cards + mind map.
 *
 * Cards carry a pointer-tracked spotlight (--gx/--gy) and a 3D tilt
 * (--tilt-x/--tilt-y); the SVG beside them draws its wires in when scrolled
 * into view (is-drawn), and clicking a card sets [data-focus] on the stage so
 * every branch but the matching one dims. Esc or a second click clears it.
 * Safe on repeated boot()s: one binding attribute per stage.
 */
function initSkillsMap() {
    document.querySelectorAll('[data-skills-stage]').forEach((stage) => {
        if (stage.dataset.skillMapBound) {
            return;
        }
        stage.dataset.skillMapBound = '1';

        const cards = Array.from(stage.querySelectorAll('.cap-card'));
        const branches = Array.from(stage.querySelectorAll('[data-domain]'));
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        const setFocus = (index) => {
            if (index === null) {
                stage.removeAttribute('data-focus');
            } else {
                stage.setAttribute('data-focus', String(index));
            }

            branches.forEach((branch) => {
                branch.classList.toggle('is-hot', index !== null && Number(branch.dataset.domain) === index);
            });

            cards.forEach((card) => {
                const active = index !== null && Number(card.dataset.domain) === index;
                card.setAttribute('aria-pressed', active ? 'true' : 'false');
                card.classList.toggle('is-active', active);
            });
        };

        // Entrance: draw the wires when the stage scrolls in, then drop the
        // stagger delays once the slowest transition has finished.
        if (reduceMotion) {
            stage.classList.add('is-drawn', 'is-settled');
        } else {
            const revealObserver = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    stage.classList.add('is-drawn');
                    window.setTimeout(() => stage.classList.add('is-settled'), 1900);
                    revealObserver.disconnect();
                }
            }, { threshold: 0.3 });
            revealObserver.observe(stage);
        }

        cards.forEach((card) => {
            card.addEventListener('click', () => {
                const index = Number(card.dataset.domain);
                setFocus(stage.getAttribute('data-focus') === String(index) ? null : index);
            });

            if (reduceMotion) {
                return;
            }

            card.addEventListener('pointermove', (event) => {
                const rect = card.getBoundingClientRect();
                const px = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
                const py = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));

                card.style.setProperty('--gx', `${(px * 100).toFixed(1)}%`);
                card.style.setProperty('--gy', `${(py * 100).toFixed(1)}%`);
                card.style.setProperty('--tilt-x', `${((0.5 - py) * 7).toFixed(2)}deg`);
                card.style.setProperty('--tilt-y', `${((px - 0.5) * 9).toFixed(2)}deg`);
            });

            card.addEventListener('pointerleave', () => {
                card.style.setProperty('--tilt-x', '0deg');
                card.style.setProperty('--tilt-y', '0deg');
            });
        });

        stage.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && stage.hasAttribute('data-focus')) {
                setFocus(null);
            }
        });
    });
}

/**
 * Boot. Livewire swaps page content without a full reload, so every initialiser
 * must be safe to run repeatedly against the current DOM.
 */
function boot() {
    initReadingProgress();
    initTableOfContents();
    initTocToggle();
    initReveal();
    initCodeCopy();
    initTypewriter();
    initCycleTypewriter();
    initMagnetic();
    initStatCounters();
    initWritingCarousel();
    initSkillsMap();
    initInteractionSound();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

document.addEventListener('livewire:navigated', boot);
