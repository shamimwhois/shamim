@props([
    'panes',
    'title' => null,
    'description' => null,
])

@php
    /**
     * The lab's showcase, in the log's grammar.
     *
     * This is the landing panel that runs the site's own example: three panes held
     * in `config('dev_lab.showcase')`, composed into a frame when a visitor presses
     * the button. It is the same Alpine factory the authenticated sandbox mounts,
     * configured with `panes` and driven only through `runStored()` — `run()` is
     * never called here, because it reads `$wire.html`, `$wire.css` and `$wire.js`,
     * and this page has no editor to read them from.
     *
     * Two rules it inherits from the module rather than invents:
     *
     * - The frame is `sandbox="allow-scripts"` and nothing else. This is not a
     *   second, weaker copy of the boundary because the author of the code happens
     *   to be this application.
     * - Nothing runs on page load. The button is what decides, and `dirty` — the
     *   factory's own flag — is what remembers, so Stop returns the reader to the
     *   offer instead of leaving an empty frame behind.
     *
     * The colours are the log's own (`--dn-*`), not the site's `ink-*` scale: the
     * log's dark theme paints `ink-900` as near-black on a near-black surface, so
     * a component borrowed wholesale from the public shell would print its own
     * headings in an unreadable colour.
     *
     * `Js::from` rather than the `@js` directive, because this attribute belongs
     * to a Blade component tag and Blade does not compile directives inside one.
     */
    $byteLimit = (int) config('dev_lab.max_code_bytes');

    $alpine = Illuminate\Support\Js::from([
        'maxLineChars' => (int) config('dev_lab.max_log_line_chars'),
        'panes' => $panes,
    ]);
@endphp

<section
    class="dn-section"
    aria-labelledby="dn-lab-showcase"
    data-test="dev-lab-showcase"
    x-data="labSandbox({{ $alpine }})"
>
    <div class="dn-section-head">
        <div class="dn-section-heading">
            <h2 id="dn-lab-showcase" class="dn-section-title">
                {{ $title ?? __('Try it on our code') }}
            </h2>

            <p class="dn-section-description">
                {{ $description ?? __('Three panes, one frame, and a console — the same arrangement the sandbox gives you, with the example already written.') }}
            </p>
        </div>

        <span class="dn-section-actions dn-mono dn-muted">{{ __('allow-scripts only') }}</span>
    </div>

    {{-- =============================================================== --
     | The offer: what you are about to run, and the code it is made of
     -- =============================================================== --}}
    <div x-show="! dirty" class="dn-lab-offer">
        <div class="dn-lab-offer-head">
            <p class="dn-lab-text">
                {{ __('This example is ours, not something a stranger published, and it still does nothing until you ask. Press run and the frame composes the three panes the way the sandbox composes yours.') }}
            </p>

            <button type="button" class="dn-btn" x-on:click="runStored()">
                {{ __('Run the example') }}
            </button>
        </div>

        <p class="dn-lab-note">
            {{ __('The frame has no origin of its own, so the code in it cannot read this site’s cookies, storage, or page, and cannot call the server with your session. It can loop forever, which is what Stop is for. `alert()` does not open — the console says so instead, which is why the silence is not a bug.') }}
        </p>

        <details class="dn-lab-details">
            <summary class="dn-lab-summary">{{ __('Read the three panes') }}</summary>

            <div class="dn-lab-panes">
                @foreach (['html' => __('HTML'), 'css' => __('CSS'), 'js' => __('JavaScript')] as $pane => $label)
                    <div>
                        <p class="dn-lab-pane-label">
                            {{ $label }}
                            <span class="dn-lab-pane-bytes">({{ number_format(strlen($panes[$pane] ?? '')) }} bytes)</span>
                        </p>

                        <pre class="dn-lab-code"><code>{{ $panes[$pane] ?? '' }}</code></pre>
                    </div>
                @endforeach
            </div>

            <p class="dn-lab-note">
                {{ __('Each pane of a real workspace is capped at :limit bytes, and this example fits well inside all three.', ['limit' => number_format($byteLimit)]) }}
            </p>
        </details>
    </div>

    {{-- =============================================================== --
     | The running frame. The iframe and the transcript are the two
     | places the browser owns the DOM, so each is ignored by the
     | morph rather than the panel around them.
     -- =============================================================== --}}
    <div x-show="dirty" x-cloak class="dn-lab-running">
        <div class="dn-lab-frame-column">
            <div class="dn-lab-pane-head">
                <span class="dn-lab-pane-label">{{ __('Preview') }}</span>

                <span class="dn-lab-pane-actions">
                    <button type="button" class="dn-lab-link" x-on:click="runStored()">{{ __('Run again') }}</button>
                    <button type="button" class="dn-lab-link" x-on:click="stop()">{{ __('Stop') }}</button>
                </span>
            </div>

            <div class="dn-lab-frame">
                <iframe
                    x-ref="preview"
                    wire:ignore
                    :srcdoc="frame"
                    sandbox="allow-scripts"
                    title="{{ __('Sandboxed preview of the lab example') }}"
                    class="dn-lab-iframe"
                ></iframe>
            </div>

            <p class="dn-lab-note">
                {{ __('A loop belongs to the frame, not to this site: Run again or Stop replaces the document and the tab stays yours. There is no CPU limit, because a browser does not hand the page one to enforce.') }}
            </p>
        </div>

        <div class="dn-lab-console-column">
            <div class="dn-lab-pane-head">
                <span class="dn-lab-pane-label">
                    {{ __('Console') }}
                    <span x-text="lines.length ? '(' + lines.length + ')' : ''" class="dn-mono"></span>
                </span>

                <span class="dn-lab-pane-actions">
                    <button type="button" class="dn-lab-link" x-on:click="$copy(transcript())">{{ __('Copy') }}</button>
                    <button type="button" class="dn-lab-link" x-on:click="clear()">{{ __('Clear') }}</button>
                </span>
            </div>

            <div wire:ignore class="dn-lab-console">
                <p x-show="lines.length === 0" class="dn-lab-note">
                    {{ __('Nothing logged yet. console.log, console.warn and uncaught errors from the frame arrive here.') }}
                </p>

                <ul class="dn-lab-console-list">
                    <template x-for="(line, index) in lines" :key="index">
                        <li class="dn-lab-console-line">
                            <span class="dn-lab-console-at" x-text="line.at"></span>
                            <span
                                :class="line.level === 'error' ? 'is-error' : (line.level === 'warn' ? 'is-warn' : 'is-log')"
                                x-text="line.text"
                            ></span>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
</section>
