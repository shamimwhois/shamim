/**
 * The Thinking Explorer — WebGL scene.
 *
 * Owns the renderer, the camera, the brain meshes, the synapse cloud, the
 * pathways, the orbital rings, the starfield and the travelling signals. It is
 * a pure renderer: it is told how many clusters exist and which one is
 * selected, and it reports back the screen position of each cluster hub so
 * the Blade can pin the category labels to the geometry.
 *
 * The synapse cloud animates per particle: every point carries its own fire
 * phase, fire rate and breathing axis as vertex attributes, and one `uTime`
 * uniform per frame drives the whole field on the GPU. Reduced-motion users
 * still get one honest still frame.
 *
 * The scene is themed through CSS custom properties rather than hard-coded
 * colours, so it follows the light/dark theme and the visitor's accent
 * choice, and it retunes itself when the band switches between System 1
 * (fast, many short pulses) and System 2 (slow, layered chains).
 *
 * Loaded through a dynamic `import()` by the Alpine provider, so Three.js
 * arrives in its own chunk and only when the band is actually on screen.
 */

import * as THREE from 'three';

import { buildBrain, lobePoint } from './thinking-brain-geometry';

/**
 * The idle motion.
 *
 * The brain sways rather than spins. A full revolution would swing the
 * cluster anchors right across the frame and turn every connector line
 * into a scribble; a slow sway keeps the front of the brain — and the
 * clusters that matter — in view.
 *
 * The period is about fifty seconds: slow enough that the picture is
 * alive rather than busy, and slow enough that a visitor can follow a
 * single cluster with their eye.
 */
const IDLE_SWAY = 0.42;
const IDLE_SWAY_SPEED = 0.00012;

/**
 * How far a drag or the arrow keys may turn the brain, in radians. The
 * head stays a head: it can be turned to look at a cluster, not spun
 * inside out.
 */
const MAX_YAW = Math.PI * 0.6;
const MAX_PITCH = Math.PI * 0.28;

/**
 * The colour roles the scene borrows from the band, and the CSS property
 * each one is read through. Five values can be resolved from one element in
 * one style pass by pointing five different properties at the custom
 * properties.
 *
 * `tissue` is the one the cortex wears: the plate publishes the surface the
 * model is sculpted from, light in the light theme and dark in the dark one,
 * so the brain sits *in* the plate instead of fighting it.
 */
const PALETTE_PROPS = {
    accent: 'color',
    chain: 'backgroundColor',
    idle: 'borderTopColor',
    hub: 'outlineColor',
    tissue: 'borderRightColor',
};

/**
 * Parse a computed colour into an [r, g, b] triple.
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

/**
 * The vertex shader for every glowing point in the scene — synapses, signals
 * and stars all share it, so the scene carries one program instead of three.
 *
 * Per-particle neural animation lives here, on the GPU: every point carries
 * its own fire phase, fire rate and breathing axis, so the synapse cloud
 * fizzles one neuron at a time instead of pulsing as a flat sheet. Three
 * attributes and a uniform are all the CPU pays per frame.
 */
const POINT_VERTEX_SHADER = `
    attribute float aSize;
    attribute float aAlpha;
    attribute vec3 aColor;
    attribute float aPhase;
    attribute float aTwinkle;
    attribute vec3 aDrift;

    uniform float uTime;
    uniform float uWobble;
    uniform float uTwinkle;

    varying vec3 vColor;
    varying float vAlpha;

    void main() {
        // Each particle fires on its own clock: sin() of its own phase and
        // rate, then remapped to 0..1 so it is a peak, not a sawtooth.
        float fire = sin(uTime * aTwinkle + aPhase * 6.28318) * 0.5 + 0.5;
        float fireSquared = fire * fire;

        vColor = aColor * mix(1.0, 0.78 + 0.22 * fireSquared, uTwinkle);
        vAlpha = aAlpha * mix(1.0, 0.4 + 0.6 * fireSquared, uTwinkle);

        // The point breathes a few thousandths of a unit along its own
        // private axis, so the tissue trembles rather than shimmers in lockstep.
        vec3 displaced = position + aDrift * (fire - 0.5) * uWobble;

        vec4 viewPosition = modelViewMatrix * vec4(displaced, 1.0);

        // 24 px of screen for every size unit at the camera distance — the
        // scale that keeps a synapse a neuron-sized spark rather than a
        // ~170 px glow that drowns the whole cortex in additive mush.
        gl_PointSize = aSize * mix(1.0, 0.85 + 0.45 * fireSquared, uTwinkle) * (24.0 / max(-viewPosition.z, 0.001));
        gl_Position = projectionMatrix * viewPosition;
    }
`;

/**
 * The fragment shader: a soft radial falloff, so a point reads as a glow
 * rather than as a hard disc.
 */
const POINT_FRAGMENT_SHADER = `
    varying vec3 vColor;
    varying float vAlpha;

    void main() {
        float distanceToCentre = length(gl_PointCoord - vec2(0.5));

        if (distanceToCentre > 0.5) discard;

        float glow = pow(1.0 - distanceToCentre * 2.0, 2.4);

        gl_FragColor = vec4(vColor, glow * vAlpha);
    }
`;

/**
 * Build a `THREE.Points` object with the shared glowing-point material.
 *
 * Every cloud gets a deterministic-but-distinct set of per-particle
 * attributes: a `aPhase` fire phase in [0, 1), an `aTwinkle` fire rate in
 * rad/s, and an `aDrift` private breathing axis. The LCG seed is derived
 * from the count, so two clouds never share a rhythm and the scene is
 * identical on every visit.
 *
 * @param {number} count
 * @param {{x:number,y:number,z:number}[]} positions
 * @param {number} size
 * @param {{wobble?: number, twinkle?: number}} [options] `wobble` is the breathing amplitude in world units, `twinkle` balances how much the per-particle fire drives colour and alpha (0 = flat).
 * @returns {{points: THREE.Points, geometry: THREE.BufferGeometry, material: THREE.ShaderMaterial}}
 */
function createGlowPoints(count, positions, size, { wobble = 0, twinkle = 0 } = {}) {
    const geometry = new THREE.BufferGeometry();
    const coordinate = new Float32Array(count * 3);
    const color = new Float32Array(count * 3);
    const alpha = new Float32Array(count);
    const pointSize = new Float32Array(count);
    const phase = new Float32Array(count);
    const rate = new Float32Array(count);
    const drift = new Float32Array(count * 3);

    let seed = (count * 7919 + 13) >>> 0;
    const nextRandom = () => {
        seed = (seed * 1103515245 + 12345) % 2147483648;
        return seed / 2147483648;
    };

    for (let i = 0; i < count; i++) {
        const position = positions[i] ?? { x: 0, y: 0, z: 0 };

        coordinate[i * 3] = position.x;
        coordinate[i * 3 + 1] = position.y;
        coordinate[i * 3 + 2] = position.z;
        pointSize[i] = size;

        phase[i] = nextRandom();
        rate[i] = 0.9 + nextRandom() * 3.1;

        const azimuth = nextRandom() * Math.PI * 2;
        const elevation = nextRandom() * 2 - 1;
        const radial = Math.sqrt(Math.max(1 - elevation * elevation, 0));

        drift[i * 3] = Math.cos(azimuth) * radial;
        drift[i * 3 + 1] = elevation;
        drift[i * 3 + 2] = Math.sin(azimuth) * radial;
    }

    geometry.setAttribute('position', new THREE.BufferAttribute(coordinate, 3));
    geometry.setAttribute('aColor', new THREE.BufferAttribute(color, 3));
    geometry.setAttribute('aAlpha', new THREE.BufferAttribute(alpha, 1));
    geometry.setAttribute('aSize', new THREE.BufferAttribute(pointSize, 1));
    geometry.setAttribute('aPhase', new THREE.BufferAttribute(phase, 1));
    geometry.setAttribute('aTwinkle', new THREE.BufferAttribute(rate, 1));
    geometry.setAttribute('aDrift', new THREE.BufferAttribute(drift, 3));

    const material = new THREE.ShaderMaterial({
        uniforms: {
            uTime: { value: 0 },
            uWobble: { value: wobble },
            uTwinkle: { value: twinkle },
        },
        vertexShader: POINT_VERTEX_SHADER,
        fragmentShader: POINT_FRAGMENT_SHADER,
        transparent: true,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
    });

    const points = new THREE.Points(geometry, material);
    points.frustumCulled = false;

    return { points, geometry, material };
}

/**
 * Create the brain scene.
 *
 * @param {{
 *     canvas: HTMLCanvasElement,
 *     clusterCount: number,
 *     getState: () => {mode: string, active: string|null, preview: string|null, clusters: Array<{key: string}>},
 *     reduced?: boolean,
 *     onProject?: (hubs: Record<string, {x: number, y: number, depth: number, visible: boolean}>) => void,
 * }} options
 * @returns {{
 *     dispose: () => void,
 *     redraw: () => void,
 *     resize: () => void,
 *     projectHubs: () => Record<string, {x: number, y: number, depth: number, visible: boolean}>,
 *     supportsWebgl: boolean,
 *     rotate: (yaw: number, pitch: number) => void,
 *     setPaused: (value: boolean) => void,
 * }}
 */
export function createBrainScene({ canvas, clusterCount, getState, reduced = false, onProject }) {
    let renderer;

    try {
        renderer = new THREE.WebGLRenderer({
            canvas,
            alpha: true,
            antialias: true,
            powerPreference: 'high-performance',
        });
    } catch {
        return {
            dispose() {},
            redraw() {},
            resize() {},
            projectHubs: () => ({}),
            supportsWebgl: false,
        };
    }

    if (!renderer.getContext()) {
        return {
            dispose() {},
            redraw() {},
            resize() {},
            projectHubs: () => ({}),
            supportsWebgl: false,
        };
    }

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 100);
    // Far enough back that the model sits *in* the plate rather than filling
    // it edge to edge: the rail and the caption need air around the object,
    // and a specimen that touches its own frame stops reading as a specimen.
    camera.position.set(0, 0.08, 2.92);
    camera.lookAt(0, 0, 0);

    renderer.setClearColor(0x000000, 0);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.15;

    const { synapses, pathways, hubs } = buildBrain(clusterCount);

    /* -------------------------------------------------------------- */
    /* The brain group — everything that rotates with the idle spin.   */
    /* -------------------------------------------------------------- */

    const brain = new THREE.Group();

    /**
     * The pivot carries the visitor's *own* orientation of the head — the
     * drag, the arrow keys. The brain inside it carries the ambient pose:
     * the idle turn and the lean toward the cursor.
     *
     * The two are separate on purpose. The ambient pose has to be held
     * still whenever the visitor is engaging with the band (the category
     * labels are pinned to this geometry, so a label that drifts under the
     * cursor is a label nobody can reach) — and the visitor must not lose
     * control of the view while that happens.
     */
    const pivot = new THREE.Group();

    scene.add(pivot);
    pivot.add(brain);

    /* --- Cortex: two translucent, folded hemispheres. --- */

    const cortexMaterial = new THREE.MeshStandardMaterial({
        // Brushed metal over the plate's own surface colour. The base and
        // the emissive are both repainted from the palette (`--te-tissue`
        // and the accent), so the organ is sculpted from the same material
        // the panel is painted with — porcelain in the light theme, steel in
        // the dark one — instead of wearing a studio blue that fought the
        // page. Real lights (below) give the folds a moving sheen.
        //
        // Both hemispheres share the same emissive hue on purpose. They used
        // to be tinted one blue and one green, and at this size the green
        // lobe read as a second planet parked next to the first: two objects
        // instead of one organ. The two systems are already told apart by the
        // mode switch, the rail's territory tags and the synapse colour — the
        // cortex does not have to carry it as well.
        color: 0x3b4653,
        emissive: 0x0b3350,
        emissiveIntensity: 0.95,
        roughness: 0.44,
        metalness: 0.42,
        // Solid, not glass. At 0.32 the far lobe showed through the near one
        // and the brain read as a Venn diagram of two bubbles; a solid mass
        // with a lit rim reads as an organ. The glow lives on the surface
        // (the synapse cloud), not inside the tissue.
        side: THREE.FrontSide,
    });

    const shellMaterial = new THREE.MeshBasicMaterial({
        color: 0x1c6f9c,
        transparent: true,
        opacity: 0.035,
        side: THREE.DoubleSide,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
    });

    /* --- The technology pass on the cortex: a fresnel rim and a scan band.
         Both are injected into the standard material instead of being faked
         with a second mesh, so the rim follows the real folds and the band is
         lit by the same lights as the tissue. One shared set of uniforms
         drives both hemispheres, so a single band sweeps the whole organ. --- */

    const cortexScanUniforms = {
        uScanTime: { value: 0 },
        uScanColor: { value: new THREE.Color(0x10b981) },
        uScanStrength: { value: 1 },
    };

    const attachCortexScan = (material) => {
        material.onBeforeCompile = (shader) => {
            shader.uniforms.uScanTime = cortexScanUniforms.uScanTime;
            shader.uniforms.uScanColor = cortexScanUniforms.uScanColor;
            shader.uniforms.uScanStrength = cortexScanUniforms.uScanStrength;

            shader.vertexShader = shader.vertexShader
                .replace('#include <common>', '#include <common>\nvarying vec3 vScanLocal;')
                .replace('#include <begin_vertex>', '#include <begin_vertex>\nvScanLocal = position;');

            shader.fragmentShader = shader.fragmentShader
                .replace(
                    '#include <common>',
                    '#include <common>\nuniform float uScanTime;\nuniform vec3 uScanColor;\nuniform float uScanStrength;\nvarying vec3 vScanLocal;',
                )
                .replace(
                    '#include <emissivemap_fragment>',
                    `#include <emissivemap_fragment>
{
    vec3 scanView = normalize(vViewPosition);
    float facing = clamp(dot(normalize(normal), scanView), 0.0, 1.0);

    // Fresnel: the surface turns brightest where it edges away from the eye.
    // That silhouette lift is what makes a rendered organ read as a scanned
    // object rather than as a painted one.
    float rim = pow(1.0 - facing, 3.0);

    // The band climbs the model on the scene clock and lights the fold it is
    // passing through, the way a scanner bar crosses a specimen.
    float sweep = -0.95 + fract(uScanTime * 0.16) * 1.9;
    float band = 1.0 - smoothstep(0.0, 0.07, abs(vScanLocal.y - sweep));

    totalEmissiveRadiance += uScanColor * (rim * 0.5 + band * 1.05) * uScanStrength;
}`,
                );
        };

        material.needsUpdate = true;
    };

    /* --- Lighting: the scene had none, so the standard material could only
         show its emissive. A cool key light, a cool rim and a sky give the
         steel its moving sheen as the brain spins. --- */

    const keyLight = new THREE.DirectionalLight(0xcfe0ec, 1.5);
    keyLight.position.set(2.5, 3, 2.2);
    scene.add(keyLight);

    const rimLight = new THREE.DirectionalLight(0x4ecfe0, 1.35);
    rimLight.position.set(-2.6, 1.2, -2.4);
    scene.add(rimLight);

    const hemisphereLight = new THREE.HemisphereLight(0x9db8c9, 0x05080d, 0.5);
    scene.add(hemisphereLight);

    // A low front fill, so the near face of the cortex reads instead of
    // falling into the background: the key is high and to the right, which
    // left the left hemisphere as a silhouette.
    const fillLight = new THREE.DirectionalLight(0x9fc4dd, 0.45);
    fillLight.position.set(-1.8, -0.6, 3);
    scene.add(fillLight);

    const lobeMeshes = [];
    const shellMeshes = [];

    for (const side of [-1, 1]) {
        const geometry = new THREE.SphereGeometry(1, 96, 72);
        const position = geometry.attributes.position;
        const vertex = new THREE.Vector3();
        const scratch = { x: 0, y: 0, z: 0 };

        for (let i = 0; i < position.count; i++) {
            vertex.fromBufferAttribute(position, i).normalize();

            // The same parameterisation the synapse cloud uses, so the points
            // sit exactly on the folded surface.
            lobePoint(vertex.x, vertex.y, vertex.z, side, scratch);

            position.setXYZ(i, scratch.x, scratch.y, scratch.z);
        }

        geometry.computeVertexNormals();

        const mesh = new THREE.Mesh(geometry, cortexMaterial.clone());

        attachCortexScan(mesh.material);

        // The two hemispheres breathe a hair out of phase, so the organ
        // never pulses in unison like a beating heart. The phase lives on
        // the mesh rather than being recovered from its colour — the colour
        // is now palette-owned and changes with the theme.
        mesh.userData.breathPhase = side < 0 ? 0 : Math.PI;

        const shell = new THREE.Mesh(geometry, shellMaterial.clone());
        shell.scale.setScalar(1.045);

        brain.add(mesh);
        brain.add(shell);
        lobeMeshes.push(mesh);
        shellMeshes.push(shell);
    }


    /* --- Synapses: the glowing point cloud on the cortex. ---
         The strongest per-particle setting: the cloud fizzles neuron by
         neuron and trembles on the folded surface. */

    const synapseCloud = createGlowPoints(synapses.length, synapses, 1.9, {
        wobble: 0.018,
        twinkle: 1,
    });
    brain.add(synapseCloud.points);

    /* --- Pathways: the neural connections. --- */

    const pathwayGeometry = new THREE.BufferGeometry();
    const pathwayCoordinates = new Float32Array(pathways.length * 6);
    const pathwayColors = new Float32Array(pathways.length * 6);
    const pathwayAlphas = new Float32Array(pathways.length * 2);

    for (let i = 0; i < pathways.length; i++) {
        const [a, b] = pathways[i];
        const from = synapses[a];
        const to = synapses[b];

        pathwayCoordinates[i * 6] = from.x;
        pathwayCoordinates[i * 6 + 1] = from.y;
        pathwayCoordinates[i * 6 + 2] = from.z;
        pathwayCoordinates[i * 6 + 3] = to.x;
        pathwayCoordinates[i * 6 + 4] = to.y;
        pathwayCoordinates[i * 6 + 5] = to.z;
    }

    pathwayGeometry.setAttribute('position', new THREE.BufferAttribute(pathwayCoordinates, 3));
    pathwayGeometry.setAttribute('aColor', new THREE.BufferAttribute(pathwayColors, 3));
    pathwayGeometry.setAttribute('aAlpha', new THREE.BufferAttribute(pathwayAlphas, 1));

    const pathwayMaterial = new THREE.ShaderMaterial({
        uniforms: {},
        vertexShader: `
            attribute vec3 aColor;
            attribute float aAlpha;

            varying vec3 vColor;
            varying float vAlpha;

            void main() {
                vColor = aColor;
                vAlpha = aAlpha;

                gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
            }
        `,
        fragmentShader: `
            varying vec3 vColor;
            varying float vAlpha;

            void main() {
                gl_FragColor = vec4(vColor, vAlpha);
            }
        `,
        transparent: true,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
    });

    const pathwayLines = new THREE.LineSegments(pathwayGeometry, pathwayMaterial);
    pathwayLines.frustumCulled = false;
    brain.add(pathwayLines);

    /* --- Orbital rings: the technical linework around the brain. --- */

    const orbitalGroup = new THREE.Group();
    scene.add(orbitalGroup);

    const orbitalRings = [];

    for (let r = 0; r < 3; r++) {
        const radius = 1.42 + r * 0.28;
        const segments = 160;
        const coordinates = new Float32Array(segments * 3);

        for (let i = 0; i < segments; i++) {
            const angle = (i / segments) * Math.PI * 2;

            coordinates[i * 3] = Math.cos(angle) * radius;
            coordinates[i * 3 + 1] = Math.sin(angle) * radius * 0.28;
            coordinates[i * 3 + 2] = 0;
        }

        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(coordinates, 3));

        const material = new THREE.LineBasicMaterial({
            color: r === 1 ? 0x22c58a : 0x2f9fd6,
            transparent: true,
            opacity: 0.28 - r * 0.05,
            // Normal blending, not additive: on the light plate an additive
            // arc sums toward white and disappears, which is how the
            // instrument lost its dial in that theme. The colour is painted
            // over the plate, so the same line reads in both themes.
            blending: THREE.NormalBlending,
            depthWrite: false,
        });

        const ring = new THREE.LineLoop(geometry, material);
        ring.rotation.x = 1.15 - r * 0.42;
        ring.rotation.y = r * 0.6;
        ring.userData.speed = (r % 2 === 0 ? 1 : -1) * (0.05 + r * 0.03);

        orbitalGroup.add(ring);
        orbitalRings.push(ring);
    }

    /* --- The dial: a measuring scale of ticks around the outer ring, so
         the linework reads as an instrument face rather than as an orbit
         drawn for decoration. --- */

    const tickCount = 72;
    const tickRadius = 1.98;
    const tickCoordinates = new Float32Array(tickCount * 6);

    for (let i = 0; i < tickCount; i++) {
        const angle = (i / tickCount) * Math.PI * 2;
        const length = i % 6 === 0 ? 0.17 : 0.07;
        const cos = Math.cos(angle);
        const sin = Math.sin(angle);

        tickCoordinates[i * 6] = cos * tickRadius;
        tickCoordinates[i * 6 + 1] = sin * tickRadius * 0.28;
        tickCoordinates[i * 6 + 2] = 0;
        tickCoordinates[i * 6 + 3] = cos * (tickRadius + length);
        tickCoordinates[i * 6 + 4] = sin * (tickRadius + length) * 0.28;
        tickCoordinates[i * 6 + 5] = 0;
    }

    const tickGeometry = new THREE.BufferGeometry();
    tickGeometry.setAttribute('position', new THREE.BufferAttribute(tickCoordinates, 3));

    const tickMaterial = new THREE.LineBasicMaterial({
        color: 0x22c58a,
        transparent: true,
        opacity: 0.34,
        blending: THREE.NormalBlending,
        depthWrite: false,
    });

    // A child of the outer ring, so the scale turns with the ring it
    // belongs to.
    orbitalRings[2].add(new THREE.LineSegments(tickGeometry, tickMaterial));

    /* --- The floor: a receding grid, so the model hangs in a space
         instead of in a void. The plane carries its own alpha ramp, so
         the lines dissolve towards the horizon rather than ending at a
         hard edge. --- */

    const groundGeometry = new THREE.PlaneGeometry(16, 16, 24, 24);
    groundGeometry.rotateX(-Math.PI / 2);

    const groundPositions = groundGeometry.attributes.position;
    const groundColors = new Float32Array(groundPositions.count * 4);

    for (let i = 0; i < groundPositions.count; i++) {
        const distance = Math.hypot(groundPositions.getX(i), groundPositions.getZ(i));
        const fade = Math.max(0, 1 - distance / 6.4);

        // White with an alpha ramp: the material's own colour carries the
        // hue, so the floor follows the accent without a second texture.
        groundColors[i * 4] = 1;
        groundColors[i * 4 + 1] = 1;
        groundColors[i * 4 + 2] = 1;
        groundColors[i * 4 + 3] = fade * fade;
    }

    groundGeometry.setAttribute('color', new THREE.BufferAttribute(groundColors, 4));

    /* The grid itself is a 64px tile with a hairline on two of its edges,
       repeated across the plane. Painted rather than shaded, so it costs
       one texture instead of a second program. */
    const groundTile = document.createElement('canvas');
    groundTile.width = 64;
    groundTile.height = 64;

    const groundContext = groundTile.getContext('2d');

    groundContext.strokeStyle = 'rgba(255, 255, 255, 0.85)';
    groundContext.lineWidth = 1;
    groundContext.beginPath();
    groundContext.moveTo(0.5, 0);
    groundContext.lineTo(0.5, 64);
    groundContext.moveTo(0, 0.5);
    groundContext.lineTo(64, 0.5);
    groundContext.stroke();

    const groundTexture = new THREE.CanvasTexture(groundTile);
    groundTexture.wrapS = THREE.RepeatWrapping;
    groundTexture.wrapT = THREE.RepeatWrapping;
    groundTexture.repeat.set(24, 24);
    groundTexture.anisotropy = 4;

    const groundMaterial = new THREE.MeshBasicMaterial({
        map: groundTexture,
        color: 0x22d3ee,
        vertexColors: true,
        transparent: true,
        depthWrite: false,
        side: THREE.DoubleSide,
        // Painted rather than summed, for the same reason as the arcs: an
        // additive grid on a light plate renders as nothing at all.
        blending: THREE.NormalBlending,
    });

    const ground = new THREE.Mesh(groundGeometry, groundMaterial);
    ground.position.y = -1.5;
    scene.add(ground);

    /* --- The instrument linework: the floor the specimen stands over, the
         orbital arcs, and the measuring dial around it. ---
       It was switched off for a while because it read as scratches across
       the specimen — three arcs cutting the cortex and a perspective grid
       that turned the model into a diagram of a machine rather than a
       picture of a mind. The real fault was the colour: the arcs and the
       dial wore fixed studio hues, so on a light plate they cut across the
       cortex as foreign marks. They are repainted from the palette now (see
       `applyScenePalette`), so they read as the plate's own instrument rather
       than as damage to the tissue. */
    orbitalGroup.visible = true;
    ground.visible = true;

    /* --- Starfield: the depth cue behind everything. --- */

    const starCount = 420;
    const starPositions = [];

    for (let i = 0; i < starCount; i++) {
        // A deterministic shell, so the sky is the same on every visit.
        const theta = i * 2.399963;
        const phi = Math.acos(1 - (2 * (i + 0.5)) / starCount);
        const radius = 6 + ((i * 37) % 100) / 100 * 5;

        starPositions.push({
            x: Math.cos(theta) * Math.sin(phi) * radius,
            y: Math.cos(phi) * radius * 0.7,
            z: Math.sin(theta) * Math.sin(phi) * radius - 2,
        });
    }

    // The stars twinkle per particle too, at a calmer rate than synapses,
    // so the depth cue never reads as static
    const stars = createGlowPoints(starCount, starPositions, 1.1, { twinkle: 0.55 });
    scene.add(stars.points);

    /* --- Dust: the near field. Slow motes drifting through the frame,
         close enough to the camera to parallax against the brain — the
         cue that tells the eye the model is sitting in a volume. --- */

    const dustCount = 160;
    const dustPositions = [];
    const dustDrift = new Float32Array(dustCount);

    let dustSeed = 20251022;

    const dustRandom = () => {
        dustSeed = (dustSeed * 1103515245 + 12345) % 2147483648;

        return dustSeed / 2147483648;
    };

    for (let i = 0; i < dustCount; i++) {
        dustPositions.push({
            x: (dustRandom() - 0.5) * 5.5,
            y: (dustRandom() - 0.5) * 3.6,
            z: (dustRandom() - 0.5) * 5 - 0.6,
        });

        dustDrift[i] = 0.35 + dustRandom() * 0.9;
    }

    const dust = createGlowPoints(dustCount, dustPositions, 0.85, { twinkle: 0.7 });
    scene.add(dust.points);

    /* --- Signals: the travelling pulses along the pathways. --- */

    const signalCount = 96;
    const signalPositions = new Array(signalCount)
        .fill(null)
        .map(() => ({ x: 0, y: 0, z: 0 }));
    // The pulses get a mild individual shimmer on top of their travel, so
    // even two signals on the same edge do not read as twins.
    const signalCloud = createGlowPoints(signalCount, signalPositions, 3.4, {
        twinkle: 0.4,
    });
    brain.add(signalCloud.points);

    const signals = [];

    /* -------------------------------------------------------------- */
    /* Palette                                                         */
    /* -------------------------------------------------------------- */

    const probe = document.createElement('span');
    probe.setAttribute('aria-hidden', 'true');
    probe.style.cssText =
        'position:absolute;width:0;height:0;overflow:hidden;pointer-events:none;';
    canvas.parentElement?.appendChild(probe);

    /**
     * Read the four colours the band is currently painted with.
     *
     * @returns {{accent: number[], chain: number[], idle: number[], hub: number[]}}
     */
    const readPalette = () => {
        if (probe.parentElement === null) {
            return {
                accent: [56, 189, 248],
                chain: [125, 211, 252],
                idle: [148, 163, 184],
                hub: [45, 212, 191],
                tissue: [59, 70, 83],
            };
        }

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

    /**
     * The base emissive intensity the frame loop breathes around. A dark
     * tissue glows from within at full strength; a light one has to whisper
     * or it blows out to a white blob. Repainted with the palette.
     */
    let emissiveBase = 0.95;

    /**
     * Paint the parts of the scene that belong to no cluster: the
     * starfield, the near-field dust and the floor. They follow the
     * palette, so a theme or an accent change repaints them too.
     */
    const applyScenePalette = () => {
        const starColours = stars.geometry.attributes.aColor;
        const starAlphas = stars.geometry.attributes.aAlpha;

        for (let i = 0; i < starCount; i++) {
            starColours.setXYZ(
                i,
                palette.idle[0] / 255,
                palette.idle[1] / 255,
                palette.idle[2] / 255,
            );
            starAlphas.setX(i, 0.3);
        }

        starColours.needsUpdate = true;
        starAlphas.needsUpdate = true;

        const dustColours = dust.geometry.attributes.aColor;
        const dustAlphas = dust.geometry.attributes.aAlpha;

        for (let i = 0; i < dustCount; i++) {
            dustColours.setXYZ(
                i,
                palette.chain[0] / 255,
                palette.chain[1] / 255,
                palette.chain[2] / 255,
            );
            dustAlphas.setX(i, 0.18);
        }

        dustColours.needsUpdate = true;
        dustAlphas.needsUpdate = true;

        groundMaterial.color.setRGB(
            palette.accent[0] / 255,
            palette.accent[1] / 255,
            palette.accent[2] / 255,
        );

        // The instrument linework wears the same palette: the orbital arcs
        // take the accent on the even rings and the chain on the odd, and
        // the measuring dial takes the accent. Hard-coded studio hues here
        // would have been the one part of the plate that ignored the theme
        // switch.
        orbitalRings.forEach((ring, index) => {
            const colour = index === 1 ? palette.chain : palette.accent;

            ring.material.color.setRGB(colour[0] / 255, colour[1] / 255, colour[2] / 255);
        });

        tickMaterial.color.setRGB(
            palette.accent[0] / 255,
            palette.accent[1] / 255,
            palette.accent[2] / 255,
        );

        // The scan bar on the cortex takes the accent too, so it tracks the
        // accent picker and the mode switch (which re-points `--te-accent`).
        cortexScanUniforms.uScanColor.value.setRGB(
            palette.accent[0] / 255,
            palette.accent[1] / 255,
            palette.accent[2] / 255,
        );

        // The organ itself. The tissue takes the plate's own surface colour;
        // the inner glow takes the accent, pulled a quarter of the way back
        // to black so it reads as light *inside* the mass rather than as a
        // neon tint on its skin. The shell — the halo a hair outside the
        // cortex — is pure accent.
        const tissue = palette.tissue;
        const glow = [
            palette.accent[0] * 0.28 + tissue[0] * 0.1,
            palette.accent[1] * 0.28 + tissue[1] * 0.1,
            palette.accent[2] * 0.28 + tissue[2] * 0.1,
        ];

        // A light tissue cannot carry the same inner lamp as a dark one — a
        // porcelain brain lit from inside at the steel's intensity just
        // blows out to a white blob. The emissive falls off as the tissue
        // brightens, and the breathing in the frame loop rides on top of
        // this base.
        const tissueLuminance =
            (tissue[0] * 0.299 + tissue[1] * 0.587 + tissue[2] * 0.114) / 255;

        emissiveBase = 0.95 - tissueLuminance * 0.62;

        // The rim and the scan bar are emissive too, so the same bright
        // porcelain plate needs less of them or the folds wash out. Scaled
        // from the same luminance as the cortex glow above.
        cortexScanUniforms.uScanStrength.value = 0.92 - tissueLuminance * 0.42;

        for (const lobe of lobeMeshes) {
            lobe.material.color.setRGB(
                tissue[0] / 255,
                tissue[1] / 255,
                tissue[2] / 255,
            );
            lobe.material.emissive.setRGB(
                glow[0] / 255,
                glow[1] / 255,
                glow[2] / 255,
            );

            // Metal on porcelain reads as plastic; the metalness falls with
            // the tissue's brightness so the light theme gets a ceramic
            // organ and the dark one keeps its brushed steel.
            lobe.material.metalness = 0.42 * (1 - tissueLuminance * 0.72);
        }

        shellMaterial.color.setRGB(
            palette.accent[0] / 255,
            palette.accent[1] / 255,
            palette.accent[2] / 255,
        );
        for (const lobe of lobeMeshes) {
            lobe.material.needsUpdate = true;
        }

        for (const shell of shellMeshes) {
            shell.material.color.setRGB(
                palette.accent[0] / 255,
                palette.accent[1] / 255,
                palette.accent[2] / 255,
            );
        }

        // The lights follow too: a dark plate keeps the cool steel key, a
        // light plate gets a warmer, brighter one so the folds still carve
        // shadows across the porcelain.
        const onLight = tissueLuminance > 0.5;

        keyLight.color.setRGB(
            onLight ? 1 : 0.81,
            onLight ? 0.99 : 0.88,
            onLight ? 0.96 : 0.93,
        );
        keyLight.intensity = onLight ? 2.1 : 1.5;

        fillLight.intensity = onLight ? 0.85 : 0.45;
        hemisphereLight.intensity = onLight ? 0.75 : 0.5;
    };

    applyScenePalette();

    const theme = new MutationObserver(() => {
        palette = readPalette();
        applyScenePalette();
    });

    theme.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class', 'data-accent'],
    });

    const stage = canvas.closest('.te-stage');

    if (stage) {
        theme.observe(stage, { attributes: true, attributeFilter: ['class'] });
    }

    /* -------------------------------------------------------------- */
    /* State                                                           */
    /* -------------------------------------------------------------- */

    /**
     * The colour a given synapse should wear right now.
     *
     * @param {object} synapse
     * @param {number} activeCluster
     * @param {number} previewCluster
     * @param {boolean} deep
     * @returns {number[]} An [r, g, b] triple.
     */
    const synapseColour = (synapse, activeCluster, previewCluster, deep) => {
        if (activeCluster !== null && synapse.cluster === activeCluster) {
            return palette.accent;
        }

        if (previewCluster !== null && synapse.cluster === previewCluster) {
            return palette.chain;
        }

        return deep ? palette.idle : palette.hub;
    };

    let lastSignature = '';

    /**
     * Re-colour the synapse cloud and the pathways. Only runs when the
     * selection actually changed — the attributes are the expensive part.
     */
    const refreshClusterColours = (activeCluster, previewCluster, deep) => {
        const colours = synapseCloud.geometry.attributes.aColor;
        const alphas = synapseCloud.geometry.attributes.aAlpha;
        const sizes = synapseCloud.geometry.attributes.aSize;

        for (let i = 0; i < synapses.length; i++) {
            const synapse = synapses[i];
            const colour = synapseColour(synapse, activeCluster, previewCluster, deep);

            colours.setXYZ(i, colour[0] / 255, colour[1] / 255, colour[2] / 255);

            const isActive = activeCluster !== null && synapse.cluster === activeCluster;
            const isPreview = previewCluster !== null && synapse.cluster === previewCluster;

            alphas.setX(i, isActive ? 1 : isPreview ? 0.8 : deep ? 0.45 : 0.32);
            sizes.setX(i, isActive ? 2.6 : isPreview ? 2.2 : 1.5);
        }

        colours.needsUpdate = true;
        alphas.needsUpdate = true;
        sizes.needsUpdate = true;

        const pathColours = pathwayGeometry.attributes.aColor;
        const pathAlphas = pathwayGeometry.attributes.aAlpha;

        for (let i = 0; i < pathways.length; i++) {
            const [a, b] = pathways[i];
            const inActive =
                activeCluster !== null &&
                (synapses[a].cluster === activeCluster || synapses[b].cluster === activeCluster);
            const inPreview =
                previewCluster !== null &&
                (synapses[a].cluster === previewCluster || synapses[b].cluster === previewCluster);

            const colour = inActive ? palette.accent : inPreview ? palette.chain : palette.idle;
            const alpha = inActive ? 0.5 : inPreview ? 0.32 : deep ? 0.13 : 0.09;

            pathColours.setXYZ(i * 2, colour[0] / 255, colour[1] / 255, colour[2] / 255);
            pathColours.setXYZ(i * 2 + 1, colour[0] / 255, colour[1] / 255, colour[2] / 255);
            pathAlphas.setX(i * 2, alpha);
            pathAlphas.setX(i * 2 + 1, alpha);
        }

        pathColours.needsUpdate = true;
        pathAlphas.needsUpdate = true;
    };

    /* -------------------------------------------------------------- */
    /* Signals                                                         */
    /* -------------------------------------------------------------- */

    /**
     * A tiny deterministic generator, so the signal field is reproducible.
     *
     * @returns {() => number}
     */
    const makeRandom = () => {
        let seed = 0x51ed270b;

        return () => {
            seed = (seed * 1103515245 + 12345) % 2147483648;

            return seed / 2147483648;
        };
    };

    const random = makeRandom();

    /**
     * Spawn a signal.
     *
     * System 1 fires a single short hop, fast. System 2 walks a multi-hop
     * chain, slowly — the rhythm is the difference between the two modes.
     *
     * @param {boolean} deep
     * @param {number} activeCluster
     */
    const spawnSignal = (deep, activeCluster) => {
        let from;
        let to;

        if (activeCluster !== null && random() > 0.35) {
            const inCluster = pathways.filter(
                ([a, b]) =>
                    synapses[a].cluster === activeCluster || synapses[b].cluster === activeCluster,
            );

            if (inCluster.length > 0) {
                const pick = inCluster[Math.floor(random() * inCluster.length)];

                from = pick[0];
                to = pick[1];
            }
        }

        if (from === undefined) {
            const pick = pathways[Math.floor(random() * pathways.length)];

            from = pick[0];
            to = pick[1];
        }

        if (!deep) {
            signals.push({ chain: [from, to], at: 0, speed: 0.9 + random() * 0.7, deep: false });

            return;
        }

        const chain = [from, to];

        for (let hop = 0; hop < 3; hop++) {
            const tail = chain[chain.length - 1];
            const options = pathways.filter(([a, b]) => a === tail || b === tail);

            if (options.length === 0) break;

            const next = options[Math.floor(random() * options.length)];

            chain.push(next[0] === tail ? next[1] : next[0]);
        }

        signals.push({ chain, at: 0, speed: 0.16 + random() * 0.1, deep: true });
    };

    /* -------------------------------------------------------------- */
    /* Interaction                                                     */
    /* -------------------------------------------------------------- */

    let pointerX = 0;
    let pointerY = 0;
    let targetTiltX = 0;
    let targetTiltY = 0;

    const onPointerMove = (event) => {
        if (reduced) return;

        const rect = canvas.getBoundingClientRect();

        pointerX = ((event.clientX - rect.left) / rect.width - 0.5) * 2;
        pointerY = ((event.clientY - rect.top) / rect.height - 0.5) * 2;

        targetTiltY = pointerX * 0.22;
        targetTiltX = -pointerY * 0.14;
    };

    const onPointerLeave = () => {
        targetTiltX = 0;
        targetTiltY = 0;
        pointerX = 0;
        pointerY = 0;
    };

    canvas.addEventListener('pointermove', onPointerMove);
    canvas.addEventListener('pointerleave', onPointerLeave);

    /* -------------------------------------------------------------- */
    /* Dragging and the arrow keys                                     */
    /* -------------------------------------------------------------- */

    /**
     * The visitor's own orientation of the head, in radians. Held on the
     * pivot, so it survives the ambient sway and is never overwritten by
     * it.
     */
    let yaw = 0;
    let pitch = 0;

    let dragging = false;
    let lastPointerX = 0;
    let lastPointerY = 0;

    /**
     * Turn the model. The head stays a head: it can be turned to look at a
     * cluster, not spun inside out.
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

    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointermove', onPointerDrag);
    canvas.addEventListener('pointerup', onPointerUp);
    canvas.addEventListener('pointercancel', onPointerUp);


    /* -------------------------------------------------------------- */
    /* Sizing                                                          */
    /* -------------------------------------------------------------- */

    // The canvas rectangle is read on resize rather than every frame — a
    // per-frame layout read would force a synchronous reflow sixty times a
    // second for no reason.
    let canvasRect = canvas.getBoundingClientRect();

    const resize = () => {
        const parent = canvas.parentElement;

        if (!parent) return;

        const rect = parent.getBoundingClientRect();
        const width = Math.max(rect.width, 10);
        const height = Math.max(rect.height, 10);

        canvasRect = canvas.getBoundingClientRect();

        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setSize(width, height, false);

        camera.aspect = width / height;
        camera.updateProjectionMatrix();
    };

    const observer = new ResizeObserver(resize);
    observer.observe(canvas.parentElement ?? canvas);

    resize();

    /* -------------------------------------------------------------- */
    /* Frame                                                           */
    /* -------------------------------------------------------------- */

    let raf = 0;
    let running = false;
    let inView = true;
    let elapsed = 0;

    /**
     * Resolve the current state into the values the renderer needs.
     *
     * @returns {{activeCluster: number, previewCluster: number, deep: boolean, clusters: Array}}
     */
    const readState = () => {
        const state = getState();
        const clusters = state.clusters ?? [];

        const indexOf = (key) =>
            key === null || key === undefined
                ? -1
                : clusters.findIndex((cluster) => cluster.key === key);

        const active = indexOf(state.active);
        const preview = indexOf(state.preview);

        return {
            activeCluster: active >= 0 ? active : null,
            previewCluster: preview >= 0 ? preview : null,
            deep: state.mode === 'system_2',
            clusters,
        };
    };

    const draw = (delta) => {
        const { activeCluster, previewCluster, deep } = readState();

        const signature = `${activeCluster}:${previewCluster}:${deep}`;

        if (signature !== lastSignature) {
            lastSignature = signature;
            refreshClusterColours(activeCluster, previewCluster, deep);
        }

        // The per-particle clock: one uniform per cloud drives every
        // neuron's own fire rate and phase in the shader.
        const seconds = elapsed / 1000;

        for (const cloud of [synapseCloud, stars, signalCloud]) {
            cloud.material.uniforms.uTime.value = seconds;
        }

        // Drives the scan bar climbing the cortex. Shared by both lobes.
        cortexScanUniforms.uScanTime.value = seconds;

        // The cortex breathes under the cloud — each lobe slowly swelling
        // and dimming its own emissive, offset, so the hemispheres never
        // pulse in unison. The base comes from the palette pass (a light
        // tissue glows less); the phase lives on the mesh.
        for (const lobe of lobeMeshes) {
            lobe.material.emissiveIntensity =
                emissiveBase * (0.62 + 0.34 * Math.sin(seconds * 0.9 + lobe.userData.breathPhase));
        }

        // Sway the key and rim lights with the pointer/tilt, so the steel
        // sheen slides across the folds as the scene answers the cursor.
        keyLight.position.x = 2.5 + targetTiltY * 1.4;
        keyLight.position.y = 3 + targetTiltX * -1.2;
        rimLight.position.x = -2.6 - targetTiltY * 1.1;

        // The idle sway: the brain rocks gently rather than turning on a
        // turntable. A full revolution would swing the cluster anchors
        // right across the frame and turn every connector line into a
        // scribble; a slow sway keeps the front of the brain — and the
        // clusters that matter — in view.
        brain.rotation.y = Math.sin(elapsed * IDLE_SWAY_SPEED) * IDLE_SWAY;

        // The pointer leans the model a little further, and the visitor's
        // own turn is layered on top of the sway.
        brain.rotation.x += (targetTiltX - brain.rotation.x) * 0.04;
        brain.rotation.z += (targetTiltY - brain.rotation.z) * 0.04;

        pivot.rotation.y = yaw;
        pivot.rotation.x = pitch;

        // A slow float, on the *seconds* clock. Driving this off `elapsed`
        // directly (milliseconds) advanced the phase by ~10 rad per frame,
        // which aliased into a violent per-frame shake — the model buzzed in
        // place instead of drifting.
        brain.position.y = Math.sin(seconds * 0.6) * 0.035;

        // The near-field dust drifts up and wraps, so the volume around the
        // model never reads as a static backdrop.
        const dustCoordinates = dust.geometry.attributes.position;

        for (let i = 0; i < dustCount; i++) {
            const y = dustCoordinates.getY(i) + delta * 0.0004 * dustDrift[i];

            dustCoordinates.setY(i, y > 1.8 ? -1.8 : y);
        }

        dustCoordinates.needsUpdate = true;

        // Both driven off the seconds clock: `elapsed * 0.05` was 50 rad/s
        // and `sin(elapsed * 0.22)` aliased into a jitter — the dials would
        // have spun and shivered rather than scanned. `delta` is still
        // milliseconds here, so the factor is what sets the sweep rate: it
        // lands the rings at about a revolution every twenty seconds, a pace
        // that reads as an instrument keeping time rather than a spinner.
        for (const ring of orbitalRings) {
            ring.rotation.z += ring.userData.speed * delta * 0.005;
        }

        orbitalGroup.rotation.y = seconds * 0.05;
        orbitalGroup.rotation.x = Math.sin(seconds * 0.22) * 0.1;

        // Keep the signal population alive; the mode decides the rhythm.
        const target = deep ? 14 : 34;

        while (signals.length < target) {
            spawnSignal(deep, activeCluster);
        }

        const signalColours = signalCloud.geometry.attributes.aColor;
        const signalAlphas = signalCloud.geometry.attributes.aAlpha;
        const signalSizes = signalCloud.geometry.attributes.aSize;
        const signalCoordinates = signalCloud.geometry.attributes.position;

        for (let i = signals.length - 1; i >= 0; i--) {
            const signal = signals[i];

            // One edge per ~0.2 s in System 1: at 0.016 a pulse crossed an
            // edge in about three frames, which read as a flicker travelling
            // nowhere rather than as a signal running a line.
            signal.at += signal.speed * delta * 0.004;

            while (signal.at >= 1 && signal.chain.length > 2) {
                signal.at -= 1;
                signal.chain.shift();
            }

            if (signal.at >= 1) {
                signals.splice(i, 1);
                continue;
            }

            const a = synapses[signal.chain[0]];
            const b = synapses[signal.chain[1]];

            signalCoordinates.setXYZ(
                i,
                a.x + (b.x - a.x) * signal.at,
                a.y + (b.y - a.y) * signal.at,
                a.z + (b.z - a.z) * signal.at,
            );

            const colour = signal.deep ? palette.chain : palette.accent;

            signalColours.setXYZ(i, colour[0] / 255, colour[1] / 255, colour[2] / 255);
            signalAlphas.setX(i, signal.deep ? 1 : 0.8);
            signalSizes.setX(i, signal.deep ? 4.2 : 2.8);
        }

        // Park the unused pulses far away rather than leaving them at the
        // origin, where they would read as a bright knot.
        for (let i = signals.length; i < signalCount; i++) {
            signalCoordinates.setXYZ(i, 0, -999, 0);
            signalAlphas.setX(i, 0);
        }

        signalColours.needsUpdate = true;
        signalAlphas.needsUpdate = true;
        signalSizes.needsUpdate = true;
        signalCoordinates.needsUpdate = true;

        renderer.render(scene, camera);

        // Report where the hubs landed, so the HTML labels can be pinned to
        // the geometry instead of floating at hand-tuned percentages.
        if (onProject) {
            onProject(projectHubs());
        }
    };

    const frame = (time) => {
        const delta = Math.min(time - elapsed, 64);
        elapsed = time;

        draw(delta);
        raf = requestAnimationFrame(frame);
    };

    /**
     * Whether the visitor has stopped the scene. A paused scene still
     * renders — it just stops moving.
     */
    let paused = false;

    const start = () => {
        if (running || reduced || paused || !inView || document.hidden) return;

        running = true;
        elapsed = performance.now();
        raf = requestAnimationFrame(frame);
    };

    const stop = () => {
        running = false;
        cancelAnimationFrame(raf);
    };

    /**
     * Stop or resume the motion.
     *
     * Pausing is not a nicety: WCAG 2.2.2 asks that auto-updating content
     * presented in parallel with other content can be stopped. The band
     * offers this switch, and the operating system's own reduced-motion
     * setting starts the scene paused.
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

    const visibility = new IntersectionObserver(
        ([entry]) => {
            inView = entry.isIntersecting;

            if (inView) start();
            else stop();
        },
        { threshold: 0.01 },
    );

    visibility.observe(canvas);

    const onVisibilityChange = () => {
        if (document.hidden) stop();
        else start();
    };

    document.addEventListener('visibilitychange', onVisibilityChange);

    const onContextLost = (event) => {
        event.preventDefault();
        stop();
    };

    const onContextRestored = () => {
        if (!reduced) start();
    };

    canvas.addEventListener('webglcontextlost', onContextLost);
    canvas.addEventListener('webglcontextrestored', onContextRestored);

    if (reduced) {
        // One honest still frame: the structure, none of the motion.
        elapsed = 0;
        draw(0);
    } else {
        start();
    }

    /* -------------------------------------------------------------- */
    /* Hub projection                                                  */
    /* -------------------------------------------------------------- */

    const projected = new THREE.Vector3();

    /**
     * Project each cluster hub to a CSS pixel position inside the canvas,
     * so the category labels can be pinned to the geometry instead of
     * floating at hand-tuned percentages.
     *
     * @returns {Record<string, {x: number, y: number, depth: number, visible: boolean}>}
     */
    const projectHubs = () => {
        const { clusters } = readState();
        const rect = canvasRect;
        const result = {};

        // The whole graph is refreshed, not just the brain: the visitor's
        // own turn lives on the pivot above it, and the hub positions are
        // reported in world space.
        scene.updateMatrixWorld();
        for (let i = 0; i < hubs.length; i++) {
            const cluster = clusters[i];

            if (cluster === undefined) continue;

            projected.set(hubs[i].x, hubs[i].y, hubs[i].z);
            projected.applyMatrix4(brain.matrixWorld);
            projected.project(camera);

            result[cluster.key] = {
                x: (projected.x * 0.5 + 0.5) * rect.width,
                y: (-projected.y * 0.5 + 0.5) * rect.height,
                depth: projected.z,
                visible: projected.z < 1,
            };
        }

        return result;
    };

    /* -------------------------------------------------------------- */
    /* Teardown                                                        */
    /* -------------------------------------------------------------- */

    const dispose = () => {
        stop();

        observer.disconnect();
        visibility.disconnect();
        theme.disconnect();

        canvas.removeEventListener('pointermove', onPointerMove);
        canvas.removeEventListener('pointerleave', onPointerLeave);
        canvas.removeEventListener('pointerdown', onPointerDown);
        canvas.removeEventListener('pointerup', onPointerUp);
        canvas.removeEventListener('pointercancel', onPointerUp);
        canvas.removeEventListener('webglcontextlost', onContextLost);
        canvas.removeEventListener('webglcontextrestored', onContextRestored);
        document.removeEventListener('visibilitychange', onVisibilityChange);

        probe.remove();

        scene.traverse((object) => {
            if (object.geometry) object.geometry.dispose();

            if (object.material) {
                const materials = Array.isArray(object.material)
                    ? object.material
                    : [object.material];

                for (const material of materials) material.dispose();
            }
        });

        renderer.dispose();
    };

    return {
        dispose,
        redraw: () => draw(0),
        resize,
        projectHubs,
        supportsWebgl: true,
        /**
         * Turn the model — by drag, or by the arrow keys on the scene.
         *
         * @param {number} yaw
         * @param {number} pitch
         */
        rotate,
        /**
         * Stop or resume the motion.
         *
         * @param {boolean} value
         */
        setPaused,
    };
}
