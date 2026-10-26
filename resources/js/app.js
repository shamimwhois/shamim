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
/**
 * Teardown for the previous page's reading-progress listeners.
 *
 * Livewire navigates without a full reload, so boot() runs again against a new
 * document. Without this, every navigation would add another scroll listener
 * still writing to an element that is no longer in the page.
 */
let teardownReadingProgress = null;

function initReadingProgress() {
    teardownReadingProgress?.();
    teardownReadingProgress = null;

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

    teardownReadingProgress = () => {
        window.removeEventListener('scroll', onScroll);
        window.removeEventListener('resize', onScroll);
    };

    update();
}

/**
 * Table-of-contents highlighting.
 *
 * An IntersectionObserver tracks every heading the TOC links to. The heading
 * nearest the top of the viewport wins, which is what readers expect: the TOC
 * reflects the section you are currently inside, not the one you just passed.
 */
let teardownTableOfContents = null;

function initTableOfContents() {
    teardownTableOfContents?.();
    teardownTableOfContents = null;

    const observers = [];

    // A page can render two TOCs (sticky sidebar, mobile disclosure). Both
    // should track the article; observing only the first left the mobile copy
    // inert.
    document.querySelectorAll('[data-toc]').forEach((toc) => {
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
        observers.push(observer);
    });

    teardownTableOfContents = () => {
        observers.forEach((observer) => observer.disconnect());
    };
}

/**
 * Reveal-on-scroll.
 *
 * Elements marked `data-reveal` fade up once, when first entering the viewport.
 * Opt-in per element so the effect is never applied to content a reader might
 * scroll past too quickly to see.
 */
function initReveal() {
    const targets = document.querySelectorAll('[data-reveal]:not([data-visible])');

    if (targets.length === 0) {
        return;
    }

    // Respect the user's motion preference — show everything immediately.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        targets.forEach((el) => el.setAttribute('data-visible', 'true'));

        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (! entry.isIntersecting) {
                    return;
                }

                entry.target.setAttribute('data-visible', 'true');
                observer.unobserve(entry.target);
            });
        },
        {
            rootMargin: '0px 0px -80px 0px',
            threshold: 0.05,
        },
    );

    targets.forEach((el) => observer.observe(el));
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
 * Boot. Livewire swaps page content without a full reload, so every initialiser
 * must be safe to run repeatedly against the current DOM.
 */
function boot() {
    initReadingProgress();
    initTableOfContents();
    initReveal();
    initCodeCopy();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

document.addEventListener('livewire:navigated', boot);
