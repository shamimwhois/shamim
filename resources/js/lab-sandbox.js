/**
 * The Developer Lab sandbox.
 *
 * Registered as an Alpine data provider so the Blade screen stays markup, the same
 * way `richTextEditor` does it for the writing surface. The reason is the same and
 * stronger here: this file holds a security boundary written as a string, and a
 * string that long does not belong inside an HTML attribute.
 *
 * ## What runs, and where the wall is
 *
 * The three panes are composed into one HTML document and handed to an
 * `<iframe sandbox="allow-scripts">` through `srcdoc`. The attribute deliberately
 * omits `allow-same-origin`. Without it the document is given an *opaque origin*:
 * it is not this site, and not `null` in the sense of a shared nothing-origin
 * either; it is a fresh origin that can reach no other. Concretely, the code in the
 * frame cannot read this site's cookies, `localStorage`, `sessionStorage` or
 * indexedDB, cannot touch the parent DOM, and cannot call a Livewire endpoint with
 * a visitor's session attached. The browser enforces all of that, which is why it is
 * in the browser.
 *
 * What the frame CAN do is run as much JavaScript as the tab will hold. That is the
 * residual risk, and it is real: `while (true)` hangs the visitor's own tab. The
 * mitigations are behavioural rather than technical. Nothing rebuilds the preview on
 * keystroke, only an explicit Run does. Reassigning `srcdoc` destroys the previous
 * document, so a second Run replaces a looping frame with a clean one. And the
 * server refuses runs past the day's allowance, which is what `lab-executions`
 * exists for.
 *
 * There is no CPU or memory quota. That belongs to a containerised runner, which is
 * not part of this build, and the screen says so rather than implying otherwise.
 *
 * ## The console bridge
 *
 * Output travels from the frame to the panel by `postMessage`. The shim that sends
 * it is the only code this file injects, and the only code it injects. The CSRF
 * token is never copied in, and the parent fetches nothing on the frame's behalf.
 *
 * The parent accepts a message only when `event.source` is the preview frame's own
 * window and the payload is the shape the shim sends. `event.origin` is compared
 * against nothing on purpose: for an opaque origin it is the literal string "null",
 * so an origin check there would either reject everything or accept anything, and
 * pretending otherwise is cargo-cult security. Source identity is the real
 * authentication.
 *
 * Received text is truncated a second time on the way in, and rendered as text,
 * never as HTML. A snippet that logs `<img onerror=...>` shows those characters.
 */

/**
 * The code injected into every frame, before the author's own script runs.
 *
 * Written as a function so its source can be serialised with `toString()` and the
 * cap passed as a real argument rather than interpolated into the body.
 *
 * @param {number} cap Maximum characters per logged line.
 */
function labSandboxShim(cap) {
    var MAX = cap;

    function cut(value) {
        var text = String(value);

        return text.length > MAX ? text.slice(0, MAX) + ' [truncated]' : text;
    }

    function format(arg) {
        if (typeof arg === 'string') {
            return arg;
        }

        if (arg instanceof Error) {
            return arg.stack || arg.name + ': ' + arg.message;
        }

        if (typeof arg === 'function') {
            return '[Function ' + (arg.name || 'anonymous') + ']';
        }

        try {
            var json = JSON.stringify(arg);

            return json === undefined ? String(arg) : json;
        } catch (problem) {
            return '[unserialisable ' + Object.prototype.toString.call(arg) + ']';
        }
    }

    function send(level, args) {
        var parts = [];

        for (var i = 0; i < args.length; i++) {
            parts.push(format(args[i]));
        }

        parent.postMessage({ __lab: 1, level: level, text: cut(parts.join(' ')) }, '*');
    }

    ['log', 'info', 'debug'].forEach(function (method) {
        console[method] = function () {
            send('log', arguments);
        };
    });

    console.warn = function () {
        send('warn', arguments);
    };

    console.error = function () {
        send('error', arguments);
    };

    window.onerror = function (message, source, line, column, error) {
        send('error', [error ? error : message + ' (' + line + ':' + column + ')']);

        return false;
    };

    window.addEventListener('unhandledrejection', function (event) {
        send('error', ['Unhandled promise rejection: ' + format(event.reason)]);
    });

    // alert(), confirm() and prompt() are blocked by the sandbox because
    // `allow-modals` is absent. They fail silently, and silence in a playground
    // reads as "my code did nothing". So they are replaced with something that
    // says what happened, which is the only honest way to keep a promise the frame
    // cannot make.
    ['alert', 'confirm', 'prompt'].forEach(function (name) {
        window[name] = function (argument) {
            send('warn', [name + '() is blocked inside the sandbox frame. Use console.log instead.']);

            return name === 'confirm' ? false : name === 'prompt' ? null : undefined;
        };
    });
}

/**
 * Compose the three panes into a document.
 *
 * The order is the order the browser needs them in: the character set before
 * anything that could be misread, the styles before the markup that uses them, the
 * shim before the author's script so that the first `console.log` in the file is
 * already captured.
 *
 * `nonce` exists so two Runs of unchanged code still rebuild the frame. Assigning
 * the identical `srcdoc` string is a no-op, and a Run button that appears to do
 * nothing when the loop is the thing you are trying to escape is a bad button.
 */
function labComposeDocument(html, css, js, nonce, shimSource) {
    return [
        '<!doctype html>',
        '<html lang="en">',
        '<head>',
        '<meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<!-- run ', nonce, ' -->',
        '<style>', css, '</style>',
        '</head>',
        '<body>',
        html,
        '<script>', shimSource, '<\/script>',
        '<script>', js, '<\/script>',
        '</body>',
        '</html>',
    ].join('\n');
}

/**
 * The Alpine component behind the sandbox screen.
 */
export default function labSandbox(config) {
    const settings = config || {};

    return {
        /** One pane visible at a time on a narrow screen. */
        active: settings.active || 'html',

        /** The console panel. Capped so a chatty loop cannot eat the tab's memory. */
        lines: [],

        maxLines: settings.maxLines || 300,

        /**
         * Ceiling on one console line, enforced here in the parent as well as in
         * the shim.
         *
         * The shim is not the trust boundary: code in the frame can skip it and
         * post the message shape itself, so a line that never passed through
         * `console.log` would arrive uncapped. Clamping it here is what keeps a
         * hostile snippet from filling the panel with a megabyte of text.
         */
        maxLineChars: settings.maxLineChars || 4000,

        frame: '',

        /**
         * Panes handed to the component by the page instead of typed into an editor:
         * a published snippet's own code, offered behind an explicit click.
         */
        stored: settings.panes || { html: '', css: '', js: '' },

        nonce: 0,

        /** True once the frame holds something the browser will execute. */
        dirty: false,

        /**
         * True when the viewport is wide enough to show every pane at once.
         *
         * Exists so the mobile collapse is one boolean instead of a class binding
         * re-derived in three markup branches. A `hidden lg:block` fighting an
         * `x-show` is how a pane ends up invisible on desktop and stacked on
         * mobile, which is backwards.
         */
        wide: false,

        init() {
            const breakpoint = window.matchMedia('(min-width: 1024px)');

            this.wide = breakpoint.matches;
            this.onWideChange = (event) => {
                this.wide = event.matches;
            };
            breakpoint.addEventListener('change', this.onWideChange);

            this.onFrameMessage = this.receive.bind(this);
            window.addEventListener('message', this.onFrameMessage);
        },

        destroy() {
            window.removeEventListener('message', this.onFrameMessage);
        },

        /**
         * Build the document from the panes the server is holding, and hand it to
         * the frame.
         *
         * Called by the Run button only. Nothing here listens to keystrokes, which
         * is what makes a live preview safe to offer at all: re-rendering an iframe
         * on every character is a loop waiting to happen.
         *
         * The values come from `$wire` rather than from local copies, so the frame
         * runs exactly what would be saved, including edits typed since the last
         * debounce.
         */
        run() {
            const wire = this.$wire;

            this.runWith(
                (wire && wire.html) || '',
                (wire && wire.css) || '',
                (wire && wire.js) || '',
            );
        },

        /**
         * Run three strings that are already fixed: a published snippet somebody
         * else wrote, offered on its public page.
         *
         * Split from `run()` rather than parameterised into it because the two
         * callers have opposite sources of truth. Here the text came from the
         * database in the page's own render, and there is no editor to read from.
         */
        runWith(html, css, js) {
            this.nonce += 1;
            this.lines = [];

            // Wrapped in parentheses so the serialised declaration becomes an
            // expression and actually executes. Left bare, `(4000)` is a perfectly
            // legal statement that calls nothing, and the console stays empty.
            const shim = '(' + labSandboxShim.toString() + ')(' + Number(settings.maxLineChars || 4000) + ')';

            this.frame = labComposeDocument(html || '', css || '', js || '', this.nonce, shim);

            this.dirty = true;

            this.push('log', 'Run ' + this.nonce + ' at ' + new Date().toLocaleTimeString());
        },

        /**
         * Run the page's own copy of the code, with no editor involved.
         */
        runStored() {
            this.runWith(this.stored.html, this.stored.css, this.stored.js);
        },

        /**
         * Replace the frame with a blank document.
         *
         * The way out of a loop that is not "reload the page": one click destroys
         * the running document and leaves an empty one in its place.
         */
        stop() {
            this.frame = '';
            this.dirty = false;
            this.push('warn', ['Preview cleared.']);
        },

        receive(event) {
            const frame = this.$refs.preview;

            if (! frame || ! frame.contentWindow || event.source !== frame.contentWindow) {
                return;
            }

            const payload = event.data;

            if (! payload || payload.__lab !== 1 || typeof payload.text !== 'string') {
                return;
            }

            this.push(payload.level || 'log', payload.text.slice(0, this.maxLineChars));
        },

        push(level, text) {
            const line = { level, text: String(text), at: new Date().toLocaleTimeString() };

            this.lines.push(line);

            if (this.lines.length > this.maxLines) {
                this.lines.splice(0, this.lines.length - this.maxLines);
            }
        },

        clear() {
            this.lines = [];
        },

        /**
         * The text the copy button takes: the console panel as a plain transcript.
         */
        transcript() {
            return this.lines.map((line) => line.at + '  ' + line.text).join('\n');
        },
    };
}

window.labSandbox = labSandbox;
