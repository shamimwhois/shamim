/**
 * The Thinking Explorer — the real 3D brain renderer.
 *
 * This module owns a WebGL scene that the section 02 band frames: two
 * procedural brain hemispheres with gyri, a glowing neural synapse cloud,
 * connection pathways, orbital rings, a starfield and travelling signals that
 * route along the pathways. It is deliberately a *renderer* and nothing else:
 * it owns no content, no copy and no DOM beyond the canvas it is handed. The
 * Alpine provider reads state through `getState()` every frame and pushes
 * back only what it needs — the screen position of each category hub, so the
 * orbital labels can be pinned to the geometry rather than floated at
 * hand-tuned percentages.
 *
 * Design notes:
 *
 * - **Content comes from the DOM, not from here.** The scene is told how many
 *   clusters to build (`clusterCount`) and which one is active; it never knows
 *   what "Debugging & Analysis" means.
 *
 * - **Theme-aware, not theme-fixed.** The band publishes its colours as CSS
 *   custom properties (`--te-accent`, `--te-chain`, `--te-idle`, `--te-hub`),
 *   and the scene reads the *resolved* values through a zero-size probe, the
 *   same trick the 2D canvas renderer used. That is why the brain follows the
 *   light/dark theme and the accent the visitor picked, and why switching to
 *   System 2 retunes the whole scene.
 *
 * - **Two modes, visibly different.** System 1 fires many short, fast pulses
 *   along single edges. System 2 fires fewer, slower pulses that walk a
 *   multi-hop chain, so the difference is legible as *rhythm*, not as a label.
 *
 * - **Honest about WebGL.** If the context cannot be created the caller is
 *   told so and the 2D canvas fallback takes over. Nothing here pretends to
 *   be 3D when it is not.
 *
 * The module is loaded through a dynamic `import()` from the Alpine provider,
 * so Three.js ships in its own chunk and costs the visitor nothing until the
 * band scrolls into view.
 */

import * as THREE from 'three';

/* ------------------------------------------------------------------ */
/* Deterministic noise                                                  */
/* ------------------------------------------------------------------ */

/**
 * An integer-lattice hash. `Math.imul` keeps the multiplication in 32-bit
 * space, so the result is identical in every engine — the brain must not
 * redraw differently on every visit.
 *
 * @param {number} ix
 * @param {number} iy
 * @param {number} iz
 * @returns {number} A value in [0, 1).
 */
function hash3(ix, iy, iz) {
    let h = Math.imul(ix, 374761393) ^ Math.imul(iy, 668265263) ^ Math.imul(iz, 2147483647);

    h = Math.imul(h ^ (h >>> 13), 1274126177);

    return ((h ^ (h >>> 16)) >>> 0) / 4294967295;
}

/**
 * Smoothstep, so the lattice interpolation has no visible creases.
 *
 * @param {number} t
 * @returns {number}
 */
function smooth(t) {
    return t * t * (3 - 2 * t);
}

/**
 * Trilinear value noise in [-1, 1].
 *
 * @param {number} x
 * @param {number} y
 * @param {number} z
 * @returns {number}
 */
function valueNoise(x, y, z) {
    const ix = Math.floor(x);
    const iy = Math.floor(y);
    const iz = Math.floor(z);
    const fx = smooth(x - ix);
    const fy = smooth(y - iy);
    const fz = smooth(z - iz);

    let result = 0;

    for (let dz = 0; dz <= 1; dz++) {
        const wz = dz === 1 ? fz : 1 - fz;

        for (let dy = 0; dy <= 1; dy++) {
            const wy = dy === 1 ? fy : 1 - fy;

            for (let dx = 0; dx <= 1; dx++) {
                const wx = dx === 1 ? fx : 1 - fx;

                result += wx * wy * wz * hash3(ix + dx, iy + dy, iz + dz);
            }
        }
    }

    return result * 2 - 1;
}

/**
 * Fractal Brownian motion — a few octaves of value noise.
 *
 * @param {number} x
 * @param {number} y
 * @param {number} z
 * @param {number} octaves
 * @returns {number}
 */
function fbm(x, y, z, octaves = 4) {
    let sum = 0;
    let amplitude = 1;
    let frequency = 1;
    let norm = 0;

    for (let i = 0; i < octaves; i++) {
        sum += valueNoise(x * frequency, y * frequency, z * frequency) * amplitude;
        norm += amplitude;
        amplitude *= 0.5;
        frequency *= 2.07;
    }

    return sum / norm;
}

/* ------------------------------------------------------------------ */
/* Brain geometry                                                      */
/* ------------------------------------------------------------------ */

/**
 * The half-lobe parameterisation.
 *
 * A lobe is one hemisphere of an ovoid: the inner face is cut flat so the two
 * lobes meet at a longitudinal fissure, the whole form is tapered towards the
 * crown and the stem, and the surface is displaced by ridged noise so it
 * reads as folded cortex rather than as a smooth ball.
 *
 * @param {number} nx Unit direction, x (the left lobe keeps x <= 0).
 * @param {number} ny Unit direction, y.
 * @param {number} nz Unit direction, z.
 * @param {number} side -1 for the left lobe, 1 for the right.
 * @param {{x:number,y:number,z:number}} out Mutated return value.
 * @returns {{x:number,y:number,z:number}}
 */
function lobePoint(nx, ny, nz, side, out) {
    // Normalise first: the mesh hands over a raw sphere vertex while the
    // synapse sampler hands over a unit direction, and both must land on the
    // same surface.
    const length = Math.hypot(nx, ny, nz) || 1;

    nx /= length;
    ny /= length;
    nz /= length;

    // Keep the half that faces away from the fissure.
    const cx = side < 0 ? Math.min(nx, 0) : Math.max(nx, 0);

    // Ridged noise: folding the field around zero turns smooth noise into
    // the raised seams a cortex is made of.
    const ridge = 1 - Math.abs(fbm(cx * 3.4 + side * 17.3, ny * 3.4, nz * 3.4, 4));
    const fine = fbm(cx * 7.1 - side * 5.7, ny * 7.1, nz * 7.1, 3);
    const bump = 0.085 * ridge * ridge + 0.028 * fine;

    // Taper: narrower at the crown and the stem, fuller at the equator.
    const taper = 1 - 0.2 * Math.abs(ny) - 0.06 * ny * ny;

    let x = cx * 0.74 * taper;
    let y = ny * 0.86 * taper - 0.04;
    let z = nz * 1.0 * taper;

    // Displace outward along the radial direction of the lobe.
    const radial = Math.hypot(x, y, z) || 1;

    x += (x / radial) * bump;
    y += (y / radial) * bump;
    z += (z / radial) * bump;

    // Push the lobes apart just enough to leave a crease. The gap used to be
    // 0.16, which opened each clamped hemisphere's flat cut to the camera and
    // put a pane of glass in the middle of the model; at 0.03 the two surfaces
    // meet at the midline the way the hemispheres of a brain do.
    x += side * 0.03;

    out.x = x;
    out.y = y;
    out.z = z;

    return out;
}

/**
 * A Fibonacci hemisphere — an even, non-clustered spread of directions over
 * one half of a sphere.
 *
 * @param {number} count
 * @param {number} side
 * @returns {Array<{x:number,y:number,z:number}>}
 */
function hemisphereDirections(count, side) {
    const directions = [];

    for (let i = 0; i < count; i++) {
        const k = i + 0.5;
        const y = 1 - (k / count) * 1.06;
        const radius = Math.sqrt(Math.max(0, 1 - y * y));
        const phi = Math.PI * (1 + Math.sqrt(5)) * k;

        directions.push({
            x: Math.cos(phi) * radius * (side < 0 ? -1 : 1),
            y,
            z: Math.sin(phi) * radius,
        });
    }

    return directions;
}

/**
 * Build the whole brain: two lobe meshes, the synapse cloud that sits on
 * them, the pathways between synapses, and the hub positions that the
 * category labels are pinned to.
 *
 * @param {number} clusterCount
 * @returns {{synapses: Array, pathways: Array, hubs: Array}}
 */
function buildBrain(clusterCount) {
    const lobes = [-1, 1];
    const synapseCount = clusterCount > 6 ? 460 : 400;
    const perLobe = Math.floor(synapseCount / 2);

    const synapses = [];
    const scratch = { x: 0, y: 0, z: 0 };

    for (const side of lobes) {
        const directions = hemisphereDirections(perLobe, side);

        for (const direction of directions) {
            lobePoint(direction.x, direction.y, direction.z, side, scratch);

            synapses.push({
                x: scratch.x,
                y: scratch.y,
                z: scratch.z,
                cluster: 0,
            });
        }
    }

    // Hubs sit on the cortex, spread across both lobes, so a category label
    // is always anchored to a visible piece of the brain.
    const hubs = [];

    for (let h = 0; h < clusterCount; h++) {
        const angle = (h / clusterCount) * Math.PI * 2 + 0.6;
        const side = h % 2 === 0 ? -1 : 1;
        const elevation = Math.sin(angle * 1.7) * 0.55;

        const direction = {
            x: Math.cos(angle) * 0.82 * (side < 0 ? -1 : 1),
            y: elevation,
            z: Math.sin(angle) * 0.6,
        };

        const length = Math.hypot(direction.x, direction.y, direction.z) || 1;

        direction.x /= length;
        direction.y /= length;
        direction.z /= length;

        lobePoint(direction.x, direction.y, direction.z, side, scratch);

        hubs.push({ x: scratch.x, y: scratch.y, z: scratch.z, cluster: h });
    }

    // Cluster assignment: nearest hub in space.
    for (const synapse of synapses) {
        let best = 0;
        let bestDistance = Infinity;

        for (let h = 0; h < hubs.length; h++) {
            const dx = synapse.x - hubs[h].x;
            const dy = synapse.y - hubs[h].y;
            const dz = synapse.z - hubs[h].z;
            const distance = dx * dx + dy * dy + dz * dz;

            if (distance < bestDistance) {
                bestDistance = distance;
                best = h;
            }
        }

        synapse.cluster = best;
    }

    // Pathways: each synapse is wired to its two nearest neighbours, with a
    // few long hops that let a signal cross the fissure.
    const pathways = [];
    const seen = new Set();

    const link = (a, b) => {
        if (a === b) return;

        const key = a < b ? `${a}:${b}` : `${b}:${a}`;

        if (seen.has(key)) return;

        seen.add(key);
        pathways.push([a, b]);
    };

    for (let i = 0; i < synapses.length; i++) {
        const near = [];

        for (let j = 0; j < synapses.length; j++) {
            if (i === j) continue;

            const dx = synapses[i].x - synapses[j].x;
            const dy = synapses[i].y - synapses[j].y;
            const dz = synapses[i].z - synapses[j].z;

            near.push([dx * dx + dy * dy + dz * dz, j]);
        }

        near.sort((a, b) => a[0] - b[0]);

        link(i, near[0][1]);
        link(i, near[1][1]);

        // A sparse cross-hemisphere hop, so a deep chain can leave one side
        // and arrive on the other.
        if (i % 11 === 0) {
            const opposite = Math.floor((i * 7 + 13) % synapses.length);

            if (synapses[opposite].cluster !== synapses[i].cluster) {
                link(i, opposite);
            }
        }
    }

    return { synapses, pathways, hubs };
}

export { buildBrain, lobePoint, hemisphereDirections };
