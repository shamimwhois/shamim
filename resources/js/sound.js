/**
 * Interaction sound — a tiny synthesised click engine.
 *
 * Deliberately dependency-free and asset-free: every sound is an oscillator
 * envelope generated on demand, so there is nothing to download, nothing to
 * preload, and nothing to get out of sync with the UI.
 *
 * Design constraints:
 *  - Sounds are VERY quiet by default (peak gain ≈ 0.04). A click that makes
 *    someone reach for the volume control has failed, regardless of how
 *    "satisfying" it sounded in the demo.
 *  - `prefers-reduced-motion: reduce` silences the engine entirely. Motion
 *    sensitivity and audio feedback travel together in this codebase's
 *    accessibility rules.
 *  - The preference persists in localStorage under `devfolio.ui-sound`, and a
 *    `[data-sound-toggle]` control anywhere in the document flips it.
 *  - Everything is delegated at the document level and idempotent, so
 *    Livewire navigations never stack duplicate listeners.
 *
 * Autoplay policy: an AudioContext created outside a user gesture starts
 * suspended. The engine only creates/resumes the context *inside* a play()
 * call, which is always triggered by a real interaction.
 */

const STORAGE_KEY = 'devfolio.ui-sound';

let audioContext = null;

/**
 * Whether the engine may make sound right now.
 */
export function soundEnabled() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return false;
    }

    try {
        return localStorage.getItem(STORAGE_KEY) !== 'off';
    } catch {
        return true;
    }
}

/**
 * A ready AudioContext, or null when sound is off, unsupported, or blocked.
 */
function audio() {
    if (! soundEnabled()) {
        return null;
    }

    const AudioCtor = window.AudioContext || window.webkitAudioContext;

    if (! AudioCtor) {
        return null;
    }

    if (audioContext === null) {
        audioContext = new AudioCtor();
    }

    if (audioContext.state === 'suspended') {
        audioContext.resume().catch(() => {});
    }

    return audioContext.state === 'running' ? audioContext : null;
}

/**
 * Whether the engine is already unlocked by a real interaction.
 *
 * The context is created and resumed inside a play() call, and the
 * browser only honours that from a user gesture. A caller that is not
 * itself a gesture — an effect driven by a timer, say — must not be the
 * one to create it: the sound would never play, and the console would
 * fill with autoplay warnings. This asks without creating.
 */
export function soundUnlocked() {
    return soundEnabled() && audioContext !== null && audioContext.state === 'running';
}

/**
 * One enveloped oscillator blip.
 */
function blip({ frequency = 1800, endFrequency = null, duration = 0.03, gain = 0.035, type = 'triangle', delay = 0 }) {
    const context = audio();

    if (! context) {
        return;
    }

    const start = context.currentTime + delay;
    const oscillator = context.createOscillator();
    const envelope = context.createGain();

    oscillator.type = type;
    oscillator.frequency.setValueAtTime(frequency, start);

    if (endFrequency) {
        oscillator.frequency.exponentialRampToValueAtTime(endFrequency, start + duration);
    }

    envelope.gain.setValueAtTime(gain, start);
    envelope.gain.exponentialRampToValueAtTime(0.0001, start + duration);

    oscillator.connect(envelope).connect(context.destination);
    oscillator.start(start);
    oscillator.stop(start + duration + 0.02);
}

/**
 * The named sounds. Kept in one table so their relative loudness can be
 * balanced in one place instead of at every call site.
 */
const sounds = {
    // A dry, short tick — the default control sound.
    tick: () => blip({ frequency: 1900, duration: 0.025, gain: 0.03 }),

    // A lower, softer blip for links — same family, different pitch.
    tap: () => blip({ frequency: 1400, duration: 0.022, gain: 0.026 }),

    // Modal arrival: a short downward sweep, like a pressure seal.
    open: () => blip({ frequency: 340, endFrequency: 180, duration: 0.09, gain: 0.05, type: 'sine' }),

    // Modal dismissal: the same sweep, reversed.
    close: () => blip({ frequency: 200, endFrequency: 330, duration: 0.07, gain: 0.04, type: 'sine' }),

    // Form focus: barely-there, higher than the tick.
    focus: () => blip({ frequency: 1250, duration: 0.015, gain: 0.018 }),

    // Two ticks a fifth apart — a small "confirmed".
    confirm: () => {
        blip({ frequency: 1600, duration: 0.02, gain: 0.028 });
        blip({ frequency: 2100, duration: 0.025, gain: 0.028, delay: 0.055 });
    },

    // The hero's scramble-decode, as sound: a short burst of noise-like
    // detuned sawtooths over a falling sweep. It is the one sound here
    // that is meant to read as *glitch* rather than as a UI
    // confirmation, so it is the only one built from more than two
    // oscillators.
    //
    // The trick is the detune: two sawtooths a few Hz apart beat
    // against each other, and that beating is what a listener hears as
    // "static" rather than as "chord". The falling sweep is the signal
    // losing coherence; the short high square on top is the one bit
    // that survives the noise.
    glitch: () => {
        blip({ frequency: 320, endFrequency: 90, duration: 0.13, gain: 0.026, type: 'sawtooth' });
        blip({ frequency: 327, endFrequency: 94, duration: 0.13, gain: 0.022, type: 'sawtooth' });
        blip({ frequency: 1850, endFrequency: 1200, duration: 0.05, gain: 0.012, type: 'square', delay: 0.01 });
    },

    // The decode locking in: the noise resolving into signal. A quiet
    // rising sine — the exact inverse of the glitch's falling sweep, so
    // the pair reads as break-then-repair. It stays near the floor of
    // the loudness range: it fires on a loop, and a loop is where a
    // sound starts to grate.
    lock: () => {
        blip({ frequency: 900, endFrequency: 1800, duration: 0.05, gain: 0.016, type: 'sine' });
    },
};

/**
 * Play a named sound. Unknown names fail silently — sound must never be the
 * reason a console shows an error.
 */
export function playSound(name) {
    try {
        sounds[name]?.();
    } catch {
        // An audio failure is never worth breaking the interaction over.
    }
}

/**
 * Play a sound that belongs to the page's ambience rather than to a
 * click — the decode line's glitch, for instance.
 *
 * The engine can only make sound once a user gesture has unlocked the
 * audio context, and the browser will not honour a context created
 * outside one. So an effect driven by a timer waits for that first
 * gesture: until it comes, the effect is simply silent. That is the
 * browser's rule, and honouring it beats the alternative — a context
 * created too early that never plays and leaves an autoplay warning
 * behind.
 */
export function playAmbient(name) {
    if (! soundUnlocked()) {
        return;
    }

    playSound(name);
}

/**
 * Flip the persisted preference and return the new state.
 */
function toggleSound() {
    const next = soundEnabled() ? 'off' : 'on';

    try {
        localStorage.setItem(STORAGE_KEY, next);
    } catch {
        // Private-mode storage: the toggle still applies for this page.
    }

    if (next === 'on') {
        playSound('confirm');
    }

    return next === 'on';
}

/**
 * Reflect the current state onto every toggle control.
 */
function syncToggles() {
    const on = soundEnabled();

    document.querySelectorAll('[data-sound-toggle]').forEach((toggle) => {
        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');

        const label = toggle.querySelector('[data-sound-label]');

        if (label) {
            label.textContent = on
                ? (toggle.dataset.soundLabelOn ?? 'Sound on')
                : (toggle.dataset.soundLabelOff ?? 'Sound off');
        }
    });
}

/**
 * Wire the delegated listeners once per document.
 */
export function initInteractionSound() {
    if (window.__devfolioSoundWired) {
        syncToggles();
        return;
    }

    window.__devfolioSoundWired = true;

    // Capture phase: the tick must fire even when the click causes a
    // navigation or a Livewire swap that stops propagation.
    document.addEventListener('click', (event) => {
        const control = event.target.closest('[data-sound-toggle]');

        if (control) {
            toggleSound();
            syncToggles();

            return;
        }

        if (event.target.closest('[data-sound="off"]')) {
            return;
        }

        const target = event.target.closest('button:not([disabled]), [role="button"]:not([aria-disabled="true"]), a[href], summary');

        if (target) {
            playSound(target.matches('a[href]') ? 'tap' : 'tick');
        }
    }, true);

    // Flux modals announce themselves on the document.
    document.addEventListener('modal-show', () => playSound('open'));
    document.addEventListener('modal-close', () => playSound('close'));

    // Keyboard focus into a field gets the quietest sound of the set. Gated
    // on :focus-visible so mouse clicks into a field stay silent.
    document.addEventListener('focusin', (event) => {
        const field = event.target;

        if (! field.matches?.('input, select, textarea')) {
            return;
        }

        if (field.matches(':focus-visible')) {
            playSound('focus');
        }
    });

    syncToggles();
}
