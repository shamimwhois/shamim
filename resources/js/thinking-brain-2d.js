/**
 * The Thinking Explorer — the non-WebGL fallback renderer.
 *
 * This is the honest second path: when the browser cannot give us a WebGL
 * context, the band still shows a *brain* rather than collapsing to a static
 * picture. It is a 2D canvas renderer that projects a real 3D point cloud
 * with a perspective transform — genuine 3D coordinates, genuine rotation,
 * genuine depth sorting of alpha and size — but no WebGL context and nothing
 * to download.
 *
 * The WebGL scene (`thinking-brain-3d.js`) is the primary renderer. This one
 * only runs when that scene reports that it could not start, and it keeps the
 * same contract: it is told how many clusters exist and which one is active,
 * and it reads everything else through `getState()`.
 *
 * The band is themed, not painted: the stage publishes the colour currently
 * in force as four custom properties (`--te-accent`, `--te-chain`,
 * `--te-idle`, `--te-hub`), and the canvas borrows the resolved values
 * through a zero-size probe element. That is why the brain follows the
 * light/dark theme and the accent the visitor picked, and why System 1
 * (fast) wears the accent while System 2 (deep) wears the ink.
 */

/**
 * The colour roles the fallback borrows from the band, and the CSS property
 * each one is read through. Four values can be resolved from one element in
 * one style pass by pointing four different properties at the custom
 * properties.
 */
const PALETTE_PROPS = {
    accent: 'color',
    chain: 'backgroundColor',
    idle: 'borderTopColor',
    hub: 'outlineColor',
};

/**
 * Parse a computed colour into an [r, g, b] triple.
 *
 * The browser hands back `rgb()` / `rgba()` for every colour it computed;
 * the hex branch is a fallback for engines that keep the literal.
 *
 * @param {string} value
 * @returns {[number, number, number]}
 */
function parseRgb(value) {
    const rgb = /rgba?\(([^)]+)\)/.exec(value || '');

    if (rgb) {
        const parts = rgb[1].split(/[\s,/]+/).filter(Boolean).map(Number);

        return [parts[0] || 0, parts[1] || 0, parts[2] || 0];
    }

    const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec((value || '').trim());

    if (hex) {
        const digits = hex[1].length === 3 ? hex[1].split('').map((c) => c + c).join('') : hex[1];

        return [
            parseInt(digits.slice(0, 2), 16),
            parseInt(digits.slice(2, 4), 16),
            parseInt(digits.slice(4, 6), 16),
        ];
    }

    return [120, 120, 120];
}

const rgba = (rgb, a) => `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, ${a})`;

/**
 * The idle sway, in radians, and its phase speed.
 *
 * The fallback sways exactly as the WebGL scene does: a slow rock rather
 * than a turntable. A fast spin would sweep the cluster anchors across the
 * canvas and make every connector line unreadable. The numbers match the
 * 3D scene's (`thinking-brain-3d.js`), converted from its milliseconds
 * clock to this module's seconds clock — the WebGL path phases at
 * `elapsed_ms * 0.00012`, so a 52-second period, and the same period here
 * is `seconds * 0.12`. If the two drift apart, one renderer visibly
 * breathes while the other is frozen.
 */
const IDLE_SWAY = 0.42;
const IDLE_SWAY_SPEED = 0.12;

/**
 * How far a drag or the arrow keys may turn the model, in radians.
 */
const MAX_YAW = Math.PI * 0.6;
const MAX_PITCH = Math.PI * 0.28;

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
 *
 * @param {HTMLCanvasElement} canvas
 * @param {number} clusterCount
 * @param {() => {mode: string, active: string|null, categories: Array<{key: string}>}} getState
 * @param {(hubs: Record<string, {x: number, y: number, depth: number, visible: boolean}>) => void} [onProject]
 */
export function thinkingBrain(canvas, clusterCount, getState, onProject) {
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
    let tilt = 0;
    let tiltTarget = 0;
    let time = 0;

    /**
     * The visitor's own turn, in radians, layered on top of the ambient
     * sway. Kept apart so the sway never overwrites it.
     */
    let yaw = 0;
    let pitch = 0;

    let dragging = false;
    let lastPointerX = 0;
    let lastPointerY = 0;

    let paused = false;

    const signals = [];

    // The probe: a zero-size element inside the stage whose four CSS
    // properties are pointed at the band's custom properties, so the
    // computed style hands back the resolved colours.
    const probe = document.createElement('span');
    probe.setAttribute('aria-hidden', 'true');
    probe.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden;pointer-events:none;';
    canvas.parentElement.appendChild(probe);

    /**
     * Read the four colours the band is currently painted with.
     *
     * @returns {{ accent: number[], chain: number[], idle: number[], hub: number[] }}
     */
    const readPalette = () => {
        for (const [key, property] of Object.entries(PALETTE_PROPS)) {
            probe.style[property] = `var(--te-${key})`;
        }

        const styles = getComputedStyle(probe);
        const palette = {};

        for (const [key, property] of Object.entries(PALETTE_PROPS)) {
            palette[key] = parseRgb(styles.getPropertyValue(property));
        }

        return palette;
    };

    let palette = readPalette();

    // The theme (the `dark` class, the accent attribute) and the band's mode
    // (the `te-stage--deep` class) both change the colours, so watch for
    // them and re-read the palette when they move.
    const theme = new MutationObserver(() => {
        palette = readPalette();
    });

    theme.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-accent'] });

    const stage = canvas.closest('.te-stage');

    if (stage) {
        theme.observe(stage, { attributes: true, attributeFilter: ['class'] });
    }

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
        // The idle sway, the visitor's own turn and the resting angle are
        // combined here rather than accumulated, so a drag never fights
        // the ambient motion.
        const rotation = -0.4 + Math.sin(time * IDLE_SWAY_SPEED) * IDLE_SWAY + yaw;
        const tiltAngle = tilt + pitch;

        const cosR = Math.cos(rotation);
        const sinR = Math.sin(rotation);
        const cosT = Math.cos(tiltAngle);
        const sinT = Math.sin(tiltAngle);

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

            ctx.strokeStyle = rgba(inCluster ? palette.accent : palette.idle, Math.min(alpha, 0.5));
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

            ctx.fillStyle = rgba(inCluster ? palette.accent : palette.idle, Math.min(alpha, 1));
            ctx.beginPath();
            ctx.arc(p.sx, p.sy, Math.max(radius, 0.4), 0, Math.PI * 2);
            ctx.fill();
        });

        // Category hubs: always-visible green status anchors.
        hubProjected.forEach((p, h) => {
            if (p.scale <= 0.5) return;
            const isActive = activeHub === h;
            ctx.fillStyle = rgba(palette.hub, isActive ? 0.95 : 0.55);
            ctx.beginPath();
            ctx.arc(p.sx, p.sy, (isActive ? 3.4 : 2.4) * p.scale, 0, Math.PI * 2);
            ctx.fill();
            if (isActive) {
                ctx.strokeStyle = rgba(palette.hub, 0.4);
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.arc(p.sx, p.sy, 7 * p.scale, 0, Math.PI * 2);
                ctx.stroke();
            }
        });

        // Report where the hubs landed, so the HTML labels can be pinned to
        // the geometry rather than floated at hand-tuned percentages. The
        // WebGL scene reports the same shape, so the labels behave
        // identically whichever renderer is live.
        if (onProject) {
            const projectedHubs = {};

            state.categories.forEach((category, index) => {
                const p = hubProjected[index];

                if (p === undefined) return;

                projectedHubs[category.key] = {
                    x: p.sx,
                    y: p.sy,
                    depth: p.depth,
                    visible: true,
                };
            });

            onProject(projectedHubs);
        }

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
        tilt += (tiltTarget - tilt) * 0.06;

        // Keep the populations alive; the mode decides the rhythm.
        const state = getState();
        const target = state.mode === 'system_2' ? 6 : 18;
        while (signals.length < target) spawnSignal(state.mode, state.active !== null ? state.categories.findIndex((c) => c.key === state.active) : null);

        draw();
        raf = requestAnimationFrame(frame);
    };

    const start = () => {
        if (running || reduced || paused || !inView || document.hidden) return;
        running = true;
        raf = requestAnimationFrame(frame);
    };

    const stop = () => {
        running = false;
        cancelAnimationFrame(raf);
    };

    /**
     * Stop or resume the motion. The band offers this switch, and the
     * operating system's reduced-motion setting starts the scene paused.
     *
     * @param {boolean} value
     */
    const setPaused = (value) => {
        paused = Boolean(value);

        if (paused) {
            stop();
        } else {
            start();
        }
    };

    /**
     * Turn the model — by drag, or by the arrow keys on the scene.
     *
     * @param {number} yawDelta
     * @param {number} pitchDelta
     */
    const rotate = (yawDelta, pitchDelta) => {
        yaw = Math.max(-MAX_YAW, Math.min(MAX_YAW, yaw + yawDelta));
        pitch = Math.max(-MAX_PITCH, Math.min(MAX_PITCH, pitch + pitchDelta));
    };

    const onPointerDown = (event) => {
        if (event.button !== 0) return;

        dragging = true;
        lastPointerX = event.clientX;
        lastPointerY = event.clientY;

        canvas.setPointerCapture?.(event.pointerId);
    };

    const onPointerUp = (event) => {
        dragging = false;

        canvas.releasePointerCapture?.(event.pointerId);
    };

    const onPointerDrag = (event) => {
        if (!dragging) return;

        const dx = event.clientX - lastPointerX;
        const dy = event.clientY - lastPointerY;

        lastPointerX = event.clientX;
        lastPointerY = event.clientY;

        rotate(dx * 0.006, dy * 0.004);
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
    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointermove', onPointerDrag);
    canvas.addEventListener('pointerup', onPointerUp);
    canvas.addEventListener('pointercancel', onPointerUp);
    document.addEventListener('visibilitychange', onVisibilityChange);

    if (reduced) {
        // One honest still frame: the structure, none of the motion.
        draw();
    } else {
        start();
    }

    return {
        redraw: draw,
        rotate,
        setPaused,
        dispose() {
            stop();
            observer.disconnect();
            visibility.disconnect();
            theme.disconnect();
            probe.remove();
            canvas.removeEventListener('pointermove', onPointerMove);
            canvas.removeEventListener('pointerleave', onPointerLeave);
            canvas.removeEventListener('pointerdown', onPointerDown);
            canvas.removeEventListener('pointerup', onPointerUp);
            canvas.removeEventListener('pointercancel', onPointerUp);
            document.removeEventListener('visibilitychange', onVisibilityChange);
        },
    };
}
