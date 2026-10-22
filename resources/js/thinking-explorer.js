/**
 * The Thinking Explorer — section 02 of the About page.
 *
 * Two pieces live here:
 *
 * 1. `thinkingBrain` — a procedural neural brain renderer on a 2D canvas.
 *    A real 3D model would cost a runtime dependency and a multi-megabyte
 *    asset for a section that needs to stay lightweight, so the brain is a
 *    point cloud projected with an honest perspective transform instead:
 *    real 3D coordinates, real rotation, real depth sorting of alpha and
 *    size — but no WebGL context and nothing to download. It is decorative
 *    (aria-hidden in the markup); every essential interaction lives in
 *    real buttons the component owns.
 *
 * 2. `thinkingExplorer` — the Alpine data provider. The Blade component
 *    stays markup and reads its content from a JSON script tag, the same
 *    division `richTextEditor` and `labSandbox` use. State is deliberately
 *    small: the active mode, the previewed/selected category and the
 *    selected mind-map node. Everything else derives.
 *
 * Behavioural contract (brief §3/§4/§10):
 * - System 1: many short, fast signals. System 2: few long, sequential
 *   chains. The mode also retunes the canvas accent.
 * - Selecting a category brightens its cluster's nodes and edges and biases
 *   the signal routing through it; the mind-map panel opens beside it.
 * - `prefers-reduced-motion` renders a single static frame — no rAF loop,
 *   no pointer tracking — and hides the travelling signals entirely.
 * - The loop pauses when the canvas leaves the viewport or the tab hides,
 *   and `dispose()` tears every listener down when Livewire removes the
 *   component from the DOM.
 */

/** Perceptual-ready palette; mirrors the band's CSS tokens. */
const COLORS = {
    system_1: { accent: [0, 217, 255], chain: [125, 234, 255] },
    system_2: { accent: [139, 92, 246], chain: [0, 229, 160] },
    idle: [96, 138, 168],
    hub: [0, 229, 160],
};

const rgba = (rgb, a) => `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, ${a})`;

/**
 * Build the brain geometry once: two hemispheres of sampled surface points,
 * one hub per category, a neighbour graph, and cluster assignment by nearest
 * hub. Deterministic (fixed seed) so every render draws the same brain.
 */
function buildGeometry(clusterCount) {
    // Simple deterministic LCG — Math.random() would re-draw a different
    // brain on every resize.
    let seed = 0x2f6e2b1;
    const rand = () => {
        seed = (seed * 1103515245 + 12345) % 2147483648;
        return seed / 2147483648;
    };

    const nodes = [];
    const pointCount = clusterCount > 6 ? 380 : 340;

    for (let i = 0; i < pointCount; i++) {
        // Fibonacci sphere for an even shell, then shaped into two lobes.
        const k = i + 0.5;
        const phi = Math.acos(1 - (2 * k) / pointCount);
        const theta = Math.PI * (1 + Math.sqrt(5)) * k;
        const sx = Math.cos(theta) * Math.sin(phi);
        const sy = Math.cos(phi);
        const sz = Math.sin(theta) * Math.sin(phi);

        const side = i % 2 === 0 ? 1 : -1;
        const puff = 0.9 + rand() * 0.2;

        nodes.push({
            x: side * (0.46 + 0.10 * Math.abs(sx) + 0.05 * sy * sy) * puff,
            y: 0.62 * sy + 0.06,
            z: 0.55 * sz * puff,
            hub: 0,
        });
    }

    // Hubs: one per category, spread on a flattened ring that crosses both
    // hemispheres. Cluster assignment is nearest-hub.
    const hubs = [];
    for (let h = 0; h < clusterCount; h++) {
        const angle = (h / clusterCount) * Math.PI * 2;
        hubs.push({
            x: Math.cos(angle) * 0.5 * (h % 2 === 0 ? 1 : 0.86),
            y: Math.sin(angle) * 0.34,
            z: Math.sin(angle * 2) * 0.3,
        });
    }

    for (const node of nodes) {
        let best = 0;
        let bestDist = Infinity;
        for (let h = 0; h < hubs.length; h++) {
            const dx = node.x - hubs[h].x;
            const dy = node.y - hubs[h].y;
            const dz = node.z - hubs[h].z;
            const dist = dx * dx + dy * dy + dz * dz;
            if (dist < bestDist) {
                bestDist = dist;
                best = h;
            }
        }
        node.hub = best;
    }

    // Edges: two nearest neighbours per node plus a sparse hub spine, so the
    // System 2 chains have long paths to walk.
    const edges = [];
    const seen = new Set();
    const push = (a, b) => {
        const key = a < b ? `${a}:${b}` : `${b}:${a}`;
        if (a === b || seen.has(key)) return;
        seen.add(key);
        edges.push([a, b]);
    };

    for (let i = 0; i < nodes.length; i++) {
        const near = [];
        for (let j = 0; j < nodes.length; j++) {
            if (i === j) continue;
            const dx = nodes[i].x - nodes[j].x;
            const dy = nodes[i].y - nodes[j].y;
            const dz = nodes[i].z - nodes[j].z;
            near.push([dx * dx + dy * dy + dz * dz, j]);
        }
        near.sort((a, b) => a[0] - b[0]);
        push(i, near[0][1]);
        if (near[1][1] !== undefined && rand() > 0.35) push(i, near[1][1]);
        if (rand() > 0.82) push(i, nodes.length - 1 - (i % 40));
    }

    // Hub spine: consecutive hubs linked through their closest nodes so
    // inter-cluster chains cross real tissue.
    for (let h = 0; h < hubs.length; h++) {
        const from = hubs[h];
        const to = hubs[(h + 1) % hubs.length];
        let bestA = 0;
        let bestB = 0;
        let bestDist = Infinity;
        for (const node of nodes) {
            if (node.hub !== h) continue;
            for (const other of nodes) {
                if (other.hub !== (h + 1) % hubs.length) continue;
                const d =
                    (node.x - other.x) ** 2 + (node.y - other.y) ** 2 + (node.z - other.z) ** 2;
                if (d < bestDist) {
                    bestDist = d;
                    bestA = nodes.indexOf(node);
                    bestB = nodes.indexOf(other);
                }
            }
        }
        push(bestA, bestB);
    }

    return { nodes, hubs, edges, rand };
}

/**
 * Create the canvas renderer. `getState` is read every frame so Alpine state
 * (mode, active category) flows in without re-instantiating the engine.
 */
function thinkingBrain(canvas, clusterCount, getState) {
    const ctx = canvas.getContext('2d');
    if (ctx === null) return { dispose() {} };

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const { nodes, hubs, edges, rand } = buildGeometry(clusterCount);

    let width = 0;
    let height = 0;
    let dpr = 1;
    let raf = 0;
    let running = false;
    let inView = true;
    let rotation = -0.4;
    let tilt = 0;
    let tiltTarget = 0;
    let time = 0;

    const signals = [];

    const spawnSignal = (mode, activeHub) => {
        const edge = edges[Math.floor(rand() * edges.length)];
        let from = edge[0];
        let to = edge[1];

        // Bias routing through the selected cluster when one is active.
        if (activeHub !== null && rand() > 0.4) {
            const inCluster = edges.filter(
                ([a, b]) => nodes[a].hub === activeHub || nodes[b].hub === activeHub,
            );
            if (inCluster.length > 0) {
                const pick = inCluster[Math.floor(rand() * inCluster.length)];
                from = pick[0];
                to = pick[1];
            }
        }

        if (mode === 'system_2') {
            // Deep thinking: build a sequential 4-hop chain from this edge.
            const chain = [from, to];
            for (let hop = 0; hop < 3; hop++) {
                const tail = chain[chain.length - 1];
                const options = edges.filter(
                    ([a, b]) => a === tail || b === tail,
                );
                if (options.length === 0) break;
                const nextEdge = options[Math.floor(rand() * options.length)];
                const next =
                    nextEdge[0] === tail
                        ? nextEdge[1]
                        : nextEdge[0];
                chain.push(next);
            }
            signals.push({ chain, at: 0, speed: 0.35 + rand() * 0.2, deep: true });
            return;
        }

        signals.push({ chain: [from, to], at: 0, speed: 2.2 + rand() * 1.4, deep: false });
    };

    const resize = () => {
        const rect = canvas.parentElement.getBoundingClientRect();
        dpr = Math.min(window.devicePixelRatio || 1, 2);
        width = Math.max(rect.width, 10);
        height = Math.max(rect.height, 10);
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
        canvas.style.width = `${width}px`;
        canvas.style.height = `${height}px`;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    };

    const project = (p) => {
        const cosR = Math.cos(rotation);
        const sinR = Math.sin(rotation);
        const cosT = Math.cos(tilt);
        const sinT = Math.sin(tilt);

        const x1 = p.x * cosR - p.z * sinR;
        const z1 = p.x * sinR + p.z * cosR;
        const y1 = p.y * cosT - z1 * sinT;
        const z2 = p.y * sinT + z1 * cosT;

        const focal = 2.6;
        const scale = focal / (focal + z2);

        return {
            sx: width / 2 + x1 * scale * (width / 3.1),
            sy: height / 2 - y1 * scale * (height / 3.1),
            scale,
            depth: z2,
        };
    };

    const draw = () => {
        const state = getState();
        const palette = COLORS[state.mode] ?? COLORS.system_1;
        const activeHub =
            state.active !== null
                ? state.categories.findIndex((c) => c.key === state.active)
                : null;

        ctx.clearRect(0, 0, width, height);

        const projected = nodes.map(project);
        const hubProjected = hubs.map(project);

        // Edges first (tissue), then nodes (synapses), then signals.
        for (const [a, b] of edges) {
            const pa = projected[a];
            const pb = projected[b];
            const inCluster =
                activeHub !== null &&
                (nodes[a].hub === activeHub || nodes[b].hub === activeHub);
            const alpha =
                (inCluster ? 0.34 : 0.07) * ((pa.scale + pb.scale) / 2 - 0.55);

            if (alpha <= 0.004) continue;

            ctx.strokeStyle = rgba(inCluster ? palette.accent : COLORS.idle, Math.min(alpha, 0.5));
            ctx.lineWidth = inCluster ? 1.1 : 0.7;
            ctx.beginPath();
            ctx.moveTo(pa.sx, pa.sy);
            ctx.lineTo(pb.sx, pb.sy);
            ctx.stroke();
        }

        nodes.forEach((node, i) => {
            const p = projected[i];
            if (p.scale <= 0.5) return;
            const inCluster = activeHub !== null && node.hub === activeHub;
            const radius = (inCluster ? 2.1 : 1.25) * p.scale;
            const alpha = (inCluster ? 0.95 : 0.4) * Math.min((p.scale - 0.5) * 2.4, 1);

            ctx.fillStyle = rgba(inCluster ? palette.accent : COLORS.idle, Math.min(alpha, 1));
            ctx.beginPath();
            ctx.arc(p.sx, p.sy, Math.max(radius, 0.4), 0, Math.PI * 2);
            ctx.fill();
        });

        // Category hubs: always-visible green status anchors.
        hubProjected.forEach((p, h) => {
            if (p.scale <= 0.5) return;
            const isActive = activeHub === h;
            ctx.fillStyle = rgba(COLORS.hub, isActive ? 0.95 : 0.55);
            ctx.beginPath();
            ctx.arc(p.sx, p.sy, (isActive ? 3.4 : 2.4) * p.scale, 0, Math.PI * 2);
            ctx.fill();
            if (isActive) {
                ctx.strokeStyle = rgba(COLORS.hub, 0.4);
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.arc(p.sx, p.sy, 7 * p.scale, 0, Math.PI * 2);
                ctx.stroke();
            }
        });

        // Signals: progress along their chain, hop to hop.
        for (let i = signals.length - 1; i >= 0; i--) {
            const signal = signals[i];
            signal.at += signal.speed / 60;

            while (signal.at >= 1 && signal.chain.length > 2) {
                signal.at -= 1;
                signal.chain.shift();
            }

            if (signal.at >= 1) {
                signals.splice(i, 1);
                continue;
            }

            const a = projected[signal.chain[0]];
            const b = projected[signal.chain[1]];
            const x = a.sx + (b.sx - a.sx) * signal.at;
            const y = a.sy + (b.sy - a.sy) * signal.at;
            const rgb = signal.deep ? palette.chain : palette.accent;

            ctx.fillStyle = rgba(rgb, signal.deep ? 0.95 : 0.75);
            ctx.beginPath();
            ctx.arc(x, y, signal.deep ? 2.6 : 1.6, 0, Math.PI * 2);
            ctx.fill();
        }
    };

    const frame = () => {
        time += 1 / 60;
        rotation += 0.0022;
        tilt += (tiltTarget - tilt) * 0.06;

        // Keep the populations alive; the mode decides the rhythm.
        const state = getState();
        const target = state.mode === 'system_2' ? 6 : 18;
        while (signals.length < target) spawnSignal(state.mode, state.active !== null ? state.categories.findIndex((c) => c.key === state.active) : null);

        draw();
        raf = requestAnimationFrame(frame);
    };

    const start = () => {
        if (running || reduced || !inView || document.hidden) return;
        running = true;
        raf = requestAnimationFrame(frame);
    };

    const stop = () => {
        running = false;
        cancelAnimationFrame(raf);
    };

    const onPointerMove = (event) => {
        if (reduced) return;
        const rect = canvas.getBoundingClientRect();
        tiltTarget = ((event.clientY - rect.top) / rect.height - 0.5) * 0.5;
    };

    const onPointerLeave = () => {
        tiltTarget = 0;
    };

    resize();

    const observer = new ResizeObserver(resize);
    observer.observe(canvas.parentElement);

    const visibility = new IntersectionObserver(([entry]) => {
        inView = entry.isIntersecting;
        if (inView) start();
        else stop();
    });
    visibility.observe(canvas);

    const onVisibilityChange = () => {
        if (document.hidden) stop();
        else start();
    };

    canvas.addEventListener('pointermove', onPointerMove);
    canvas.addEventListener('pointerleave', onPointerLeave);
    document.addEventListener('visibilitychange', onVisibilityChange);

    if (reduced) {
        // One honest still frame: the structure, none of the motion.
        draw();
    } else {
        start();
    }

    return {
        redraw: draw,
        dispose() {
            stop();
            observer.disconnect();
            visibility.disconnect();
            canvas.removeEventListener('pointermove', onPointerMove);
            canvas.removeEventListener('pointerleave', onPointerLeave);
            document.removeEventListener('visibilitychange', onVisibilityChange);
        },
    };
}

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

        init() {
            const source = this.$refs.payload;

            if (source !== undefined) {
                try {
                    this.payload = JSON.parse(source.textContent);
                } catch {
                    this.payload = { modes: {}, categories: [] };
                }
            }

            const canvas = this.$refs.canvas;

            if (canvas !== undefined && canvas.getContext !== undefined) {
                this.engine = thinkingBrain(canvas, this.categories.length, () => ({
                    mode: this.mode,
                    active: this.active,
                    categories: this.categories,
                }));
            }
        },

        destroy() {
            this.engine?.dispose();
            this.engine = null;
        },

        get categories() {
            return this.payload.categories ?? [];
        },

        get modes() {
            return Object.values(this.payload.modes ?? {});
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

        toggle(key) {
            this.active = this.active === key ? null : key;
            this.activeNode = null;
        },

        back() {
            this.active = null;
            this.activeNode = null;
            this.preview = null;
        },

        nodePosition(index, count) {
            const angle = -Math.PI / 2 + (index / Math.max(count, 1)) * Math.PI * 2;
            return {
                x: 50 + Math.cos(angle) * 33,
                y: 50 + Math.sin(angle) * 40,
            };
        },

        chipPosition(index, count) {
            // Desktop orbit: chips flank the brain along two shallow arcs.
            // Percentages, so any canvas size holds together.
            const perSide = Math.ceil(count / 2);
            const left = index < perSide;
            const row = left ? index : index - perSide;
            const spread = perSide > 1 ? row / (perSide - 1) : 0.5;
            const inset = 2 + (1 - Math.abs(spread - 0.5) * 2) * 2;

            return {
                top: `${14 + spread * 66}%`,
                left: left ? `${inset}%` : null,
                right: left ? null : `${inset}%`,
            };
        },
    };
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { thinkingBrain, thinkingExplorer };
}
