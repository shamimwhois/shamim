/**
 * The Thinking Explorer — section 02 of the About page.
 *
 * The Alpine data provider. The Blade component stays markup and reads its
 * content from a JSON script tag, the same division `richTextEditor` and
 * `labSandbox` use. State is deliberately small: the active mode, the
 * previewed/selected category and the selected mind-map node. Everything else
 * derives.
 *
 * The rendering has two paths, and the provider picks between them at
 * runtime:
 *
 * 1. **WebGL (primary).** `thinking-brain-3d.js` is pulled in through a
 *    dynamic `import()`, so Three.js ships in its own chunk and costs the
 *    visitor nothing until the band scrolls into view. The scene owns a real
 *    3D brain: folded hemispheres, a synapse cloud, pathways, orbital rings
 *    and travelling signals.
 *
 * 2. **Canvas (fallback).** If the WebGL context cannot be created, the
 *    scene says so and `thinking-brain-2d.js` takes over: the same point
 *    cloud, projected by hand onto a 2D canvas. The band still shows a brain
 *    rather than collapsing to a static picture, and the page says which
 *    renderer is live.
 *
 * Behavioural contract (brief §3/§4/§10):
 * - System 1: many short, fast signals. System 2: few long, sequential
 *   chains. The mode also retunes the scene's accent.
 * - Selecting a category brightens its cluster's nodes and pathways and biases
 *   the signal routing through it; the mind-map panel opens beside it.
 * - `prefers-reduced-motion` renders a single static frame — no rAF loop,
 *   no pointer tracking — and hides the travelling signals entirely.
 * - The loop pauses when the canvas leaves the viewport or the tab hides,
 *   and `destroy()` tears every listener down when Livewire removes the
 *   component from the DOM.
 *
 * ## How the labels stay reachable
 *
 * The category labels are the only way to reach a discipline, so they have to
 * be *stationary*. An earlier version pinned each label to the projected
 * position of its cluster, which meant every label was a moving target: the
 * brain turned underneath them at fifty pixels a second, so a label slid out
 * from under the cursor between one press and the next. It was also a
 * genuine accessibility failure — a category whose cluster had rotated
 * behind the cortex became both invisible and unreachable.
 *
 * The fix splits the job in two:
 *
 * - The **label** is laid out by the browser in a rail beside the model. It
 *   never moves, so it is always a clean click target and always readable —
 *   and "stationary" is now a property of the layout rather than something
 *   this file has to be careful about.
 * - The **cluster anchor** — the glowing dot on the brain — travels with the
 *   geometry, and a **leader line** ties the label to it.
 *
 * So the labels still read as pinned to the geometry (the line says exactly
 * which cluster a label belongs to), but nothing that has to be clicked is
 * ever moving.
 */

import { thinkingBrain } from './thinking-brain-2d';

/**
 * Alpine data provider. The Blade component hands over nothing — content is
 * read from the JSON script tag inside it, so no payload ever travels
 * through an HTML attribute.
 */
window.thinkingExplorer = function thinkingExplorer() {
    return {
        payload: { modes: {}, categories: [] },
        mode: 'system_1',
        preview: null,
        active: null,
        activeNode: null,
        engine: null,
        renderer: 'pending',
        /**
         * Whether the visitor has stopped the scene. The band says so and
         * offers the switch back — an auto-updating scene that cannot be
         * paused is a WCAG 2.2.2 failure, whatever the visual intent.
         */
        motionPaused: false,
        /**
         * True when the operating system asked for reduced motion. In that
         * case the visitor is not offered a switch the band cannot honour.
         */
        motionLocked: false,

        /**
         * The DOM nodes the scene pins the cluster anchors to. Kept outside
         * Alpine's reactivity: the positions change every frame and must not
         * trigger a re-render.
         *
         * @type {Map<string, HTMLElement>}
         */
        anchorElements: new Map(),

        /**
         * The connector lines that tie each label to its travelling anchor.
         *
         * @type {Map<string, SVGLineElement>}
         */
        leadElements: new Map(),

        /**
         * The label elements, so the connector lines know where to start.
         *
         * @type {Map<string, HTMLElement>}
         */
        labelElements: new Map(),

        /**
         * The fixed slot of each label, in pixels, recomputed on resize.
         *
         * @type {Map<string, {x: number, y: number}>}
         */
        slots: new Map(),

        /** The pixel size of the scene box. @type {{width: number, height: number}} */
        sceneSize: { width: 0, height: 0 },

        /** @type {ResizeObserver|null} */
        sceneObserver: null,

        init() {
            // Alpine's tree walk skips <script> elements, so the payload is
            // addressed by attribute and read from the component root. The
            // attribute is explorer-specific: the section 04 band publishes
            // its own payload under a different name, and the two must never
            // read each other's content.
            const source = this.$el.querySelector('script[data-thinking-explorer-payload]');

            if (source !== null) {
                try {
                    this.payload = JSON.parse(source.textContent);
                } catch {
                    this.payload = { modes: {}, categories: [] };
                }
            }

            const canvas = this.$refs.canvas;

            if (canvas === undefined || canvas.getContext === undefined) {
                this.renderer = 'none';

                return;
            }

            this.collectNodes();
            this.observeScene();

            // The scene is loaded lazily. Until it resolves the band shows
            // the static fallback drawing, so the section is never blank.
            this.bootScene(canvas);

            // A paused scene renders one frame and never repaints on its
            // own, so a change of mode or selection has to ask for a redraw.
            this.$watch('mode', () => this.engine?.redraw?.());
            this.$watch('active', () => this.engine?.redraw?.());
            this.$watch('preview', () => this.engine?.redraw?.());
        },

        /**
         * Collect the label, anchor and connector elements the scene drives.
         */
        collectNodes() {
            this.anchorElements.clear();
            this.leadElements.clear();
            this.labelElements.clear();

            for (const element of this.$el.querySelectorAll('[data-anchor-key]')) {
                this.anchorElements.set(element.dataset.anchorKey, element);
            }

            for (const element of this.$el.querySelectorAll('[data-lead-key]')) {
                this.leadElements.set(element.dataset.leadKey, element);
            }

            for (const element of this.$el.querySelectorAll('[data-hub-key]')) {
                this.labelElements.set(element.dataset.hubKey, element);
            }
        },

        /**
         * Track the size of the scene box, so the connector lines can be
         * drawn in pixels and the label slots can be converted to pixels.
         */
        observeScene() {
            const scene = this.$refs.scene;

            if (scene === undefined) return;

            const measure = () => {
                const rect = scene.getBoundingClientRect();

                this.sceneSize = { width: rect.width, height: rect.height };

                const leads = this.$refs.leads;

                if (leads !== undefined) {
                    leads.setAttribute('viewBox', `0 0 ${rect.width} ${rect.height}`);
                }

                this.measureSlots();
            };

            measure();

            this.sceneObserver = new ResizeObserver(measure);
            this.sceneObserver.observe(scene);
        },

        /**
         * Convert each label's on-screen box into the pixel point the
         * leader line starts from.
         *
         * The labels are laid out by the browser now, so the slot cannot be
         * read from a custom property — it is measured. The line starts at
         * the label's *inner* edge (the side facing the model) and at the
         * label's vertical centre, which is what makes the plate read as
         * label → line → cluster rather than as a stray dash.
         *
         * Coordinates are scene-local and may be negative: the rail sits
         * outside the scene box, and the leads layer draws with
         * `overflow: visible` so a negative x is a legitimate position.
         */
        measureSlots() {
            this.slots.clear();

            const scene = this.$refs.scene;

            if (scene === undefined) return;

            const origin = scene.getBoundingClientRect();
            const centre = origin.left + origin.width / 2;

            for (const [key, element] of this.labelElements) {
                const rect = element.getBoundingClientRect();

                // Pick the edge nearest the model, so the same code works if
                // the rail ever moves to the other side of the scene.
                const inner = rect.left + rect.width / 2 < centre ? rect.right : rect.left;

                this.slots.set(key, {
                    x: inner - origin.left,
                    y: rect.top + rect.height / 2 - origin.top,
                });
            }
        },

        /**
         * Start the WebGL scene, and fall back to the 2D canvas renderer if
         * the context cannot be created.
         *
         * @param {HTMLCanvasElement} canvas
         */
        async bootScene(canvas) {
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            this.motionLocked = reduced;
            this.motionPaused = reduced;

            try {
                const { createBrainScene } = await import('./thinking-brain-3d');

                const scene = createBrainScene({
                    canvas,
                    clusterCount: this.categories.length,
                    reduced,
                    getState: () => ({
                        mode: this.mode,
                        active: this.active,
                        preview: this.preview,
                        clusters: this.categories,
                    }),
                    onProject: (hubs) => this.applyHubs(hubs),
                });

                if (scene.supportsWebgl) {
                    this.engine = scene;
                    this.renderer = 'webgl';

                    // The scene owns the picture now; the static SVG stays
                    // only for the no-JS / no-canvas path.
                    this.$refs.fallback?.classList.add('is-dimmed');

                    scene.setPaused?.(this.motionPaused);

                    return;
                }

                scene.dispose();
            } catch (error) {
                // A failed import or a dead context is not a broken page —
                // the canvas renderer below covers for it.
                console.warn('[thinking-explorer] WebGL scene unavailable, falling back.', error);
            }

            this.engine = thinkingBrain(
                canvas,
                this.categories.length,
                () => ({
                    mode: this.mode,
                    active: this.active,
                    categories: this.categories,
                }),
                (hubs) => this.applyHubs(hubs),
            );

            this.renderer = 'canvas';

            this.engine.setPaused?.(this.motionPaused);
        },

        /**
         * Move the travelling cluster anchors and redraw the connector
         * lines that tie each label to its anchor.
         *
         * The positions are written as inline `left`/`top` in pixels. That
         * happens outside Alpine's reactivity on purpose — sixty reactive
         * updates a second would re-render the whole band for the sake of a
         * transform.
         *
         * @param {Record<string, {x: number, y: number, depth: number, visible: boolean}>} hubs
         */
        applyHubs(hubs) {
            for (const [key, element] of this.anchorElements) {
                const position = hubs[key];

                if (position === undefined) continue;

                element.style.left = `${position.x.toFixed(1)}px`;
                element.style.top = `${position.y.toFixed(1)}px`;
                element.dataset.hidden = position.visible ? 'false' : 'true';

                const lead = this.leadElements.get(key);
                const slot = this.slots.get(key);

                if (lead === undefined || slot === undefined) continue;

                lead.setAttribute('x1', slot.x.toFixed(1));
                lead.setAttribute('y1', slot.y.toFixed(1));
                lead.setAttribute('x2', position.x.toFixed(1));
                lead.setAttribute('y2', position.y.toFixed(1));
                lead.dataset.hidden = position.visible ? 'false' : 'true';
            }
        },

        /**
         * Turn the model. Called by the drag handler in the renderer and by
         * the arrow keys on the scene.
         *
         * @param {number} yaw
         * @param {number} pitch
         */
        turn(yaw, pitch) {
            this.engine?.rotate?.(yaw, pitch);
        },

        /**
         * The arrow keys turn the model. The scene is focusable so this is
         * reachable without a pointer.
         *
         * @param {KeyboardEvent} event
         */
        onSceneKeydown(event) {
            const step = 0.12;
            const pitchStep = 0.08;

            switch (event.key) {
                case 'ArrowLeft':
                    this.turn(-step, 0);
                    break;
                case 'ArrowRight':
                    this.turn(step, 0);
                    break;
                case 'ArrowUp':
                    this.turn(0, -pitchStep);
                    break;
                case 'ArrowDown':
                    this.turn(0, pitchStep);
                    break;
                default:
                    return;
            }

            event.preventDefault();
        },

        /**
         * Stop or resume the scene.
         */
        toggleMotion() {
            if (this.motionLocked) return;

            this.motionPaused = !this.motionPaused;

            this.engine?.setPaused?.(this.motionPaused);
        },

        destroy() {
            this.engine?.dispose();
            this.engine = null;

            this.sceneObserver?.disconnect();
            this.sceneObserver = null;

            this.anchorElements.clear();
            this.leadElements.clear();
            this.labelElements.clear();
            this.slots.clear();
        },

        get categories() {
            return this.payload.categories ?? [];
        },

        get activeCategory() {
            return this.categories.find((category) => category.key === this.active) ?? null;
        },

        get activeNodeText() {
            return this.activeCategory?.nodes?.[this.activeNode]?.text ?? '';
        },

        get readout() {
            const key = this.preview ?? this.active;

            if (key !== null) {
                const category = this.categories.find((item) => item.key === key);
                if (category) return category.description;
            }

            const mode = this.payload.modes?.[this.mode];
            return mode ? mode.description : '';
        },

        /**
         * The caption's small line: which system is speaking. A discipline
         * carries its own territory, so the eyebrow answers "why is this
         * here?" before the visitor has read the name.
         */
        get readoutEyebrow() {
            const key = this.preview ?? this.active;

            if (key !== null) {
                const category = this.categories.find((item) => item.key === key);

                if (category) {
                    if (category.affinity === 'fast') return 'System 1 territory';
                    if (category.affinity === 'deep') return 'System 2 territory';

                    return 'Where both systems meet';
                }
            }

            return 'Thinking mode';
        },

        /**
         * The caption's headline: the discipline under the pointer, or the
         * active system when nothing is previewed. The mode name is
         * operator copy, so it comes from the payload rather than being
         * written here.
         */
        get readoutTitle() {
            const key = this.preview ?? this.active;

            if (key !== null) {
                const category = this.categories.find((item) => item.key === key);

                if (category) return category.name;
            }

            return this.payload.modes?.[this.mode]?.name ?? '';
        },

        toggle(key) {
            this.active = this.active === key ? null : key;
            this.activeNode = null;
        },

        back() {
            this.active = null;
            this.activeNode = null;
            this.preview = null;
        },
    };
};
