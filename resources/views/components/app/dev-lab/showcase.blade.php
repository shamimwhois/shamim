@props([
    'data',
])

@php
    /**
     * Developer Lab showcase — the landing panel that runs the site's own example.
     *
     * The point of this panel is that a visitor learns what the sandbox does without
     * an account, an editor, or a promise: three panes held in `config('dev_lab.showcase')`,
     * composed into a frame, with the console beside it. The code is the repository's,
     * not a stranger's and not the visitor's, which is the only reason it is on a public
     * page at all.
     *
     * Two rules it inherits from the module rather than invents:
     *
     * - The frame is `sandbox="allow-scripts"` and nothing else. The panel is not a
     *   second, weaker copy of the boundary because the author of the code happens to
     *   be this application.
     * - Nothing runs on page load. The button is what decides, and `dirty` — the
     *   factory's own flag — is what remembers, so Stop returns the reader to the offer
     *   instead of leaving an empty frame behind.
     *
     * The Alpine factory is the one the sandbox screen mounts, configured with `panes`
     * and driven only through `runStored()`. `run()` is never called here: it reads
     * `$wire.html`, `$wire.css` and `$wire.js`, and this page has no such properties
     * because it has no editor to read them from.
     *
     * @var array{panes: array{html: string, css: string, js: string}, title?: string, description?: string} $data
     */
    $panes = $data['panes'];
    $byteLimit = (int) config('dev_lab.max_code_bytes');

    // Built with `Js::from` rather than the `@js` directive, because this attribute
    // belongs to a Blade component tag and Blade does not compile directives inside
    // one: `@js($panes)` reaches the browser as the literal text it is, which Alpine
    // then tries to parse as JavaScript. Interpolating the `HtmlString` is the same
    // escaping on a path the component tag leaves alone.
    $alpine = Illuminate\Support\Js::from([
        'maxLineChars' => (int) config('dev_lab.max_log_line_chars'),
        'panes' => $panes,
    ]);
@endphp

<x-app.ui.section
    :title="$data['title'] ?? __('Try it on our code')"
    :description="$data['description'] ?? __('Three panes, one frame, and a console — the same arrangement the sandbox gives you, with the example already written.')"
    icon="window"
    :padded="false"
    data-test="dev-lab-showcase"
    x-data="labSandbox({{ $alpine }})"
>
    <x-slot:actions>
        <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
            {{ __('allow-scripts only') }}
        </span>
    </x-slot:actions>

    {{-- =============================================================== --
     | The offer: what you are about to run, and the code it is made of
     -- =============================================================== --}}
    <div x-show="! dirty" class="px-5 py-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <p class="max-w-xl text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                {{ __('This example is ours, not something a stranger published, and it still does nothing until you ask. Press run and the frame composes the three panes the way the sandbox composes yours.') }}
            </p>

            <flux:button x-on:click="runStored()" variant="primary" icon="play">
                {{ __('Run the example') }}
            </flux:button>
        </div>

        <p class="mt-4 text-xs leading-relaxed text-ink-500 dark:text-ink-400">
            {{ __('The frame has no origin of its own, so the code in it cannot read this site’s cookies, storage, or page, and cannot call the server with your session. It can loop forever, which is what Stop is for. `alert()` does not open — the console says so instead, which is why the silence is not a bug.') }}
        </p>

        <details class="mt-5 border-t border-ink-200 pt-4 dark:border-ink-800">
            <summary class="focus-ring cursor-pointer font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                {{ __('Read the three panes') }}
            </summary>

            <div class="mt-4 space-y-4">
                @foreach (['html' => __('HTML'), 'css' => __('CSS'), 'js' => __('JavaScript')] as $pane => $label)
                    <div>
                        <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                            {{ $label }}
                            <span class="normal-case">({{ number_format(strlen($panes[$pane] ?? '')) }} bytes)</span>
                        </p>

                        <div class="mt-1.5 max-h-48 overflow-auto rounded-lg bg-ink-950 px-4 py-3">
                            <pre class="font-mono text-2xs leading-relaxed text-ink-100"><code>{{ $panes[$pane] ?? '' }}</code></pre>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-4 text-2xs leading-relaxed text-ink-500 dark:text-ink-400">
                {{ __('Each pane of a real workspace is capped at :limit bytes, and this example fits well inside all three.', ['limit' => number_format($byteLimit)]) }}
            </p>
        </details>
    </div>

    {{-- =============================================================== --
     | The running frame. The iframe and the transcript are the two
     | places the browser owns the DOM, so each is ignored by the
     | morph rather than the panel around them.
     -- =============================================================== --}}
    <div x-show="dirty" x-cloak class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)]">
        <div>
            <div class="flex items-center justify-between gap-3 pb-2">
                <h3 class="font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                    {{ __('Preview') }}
                </h3>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        x-on:click="runStored()"
                        class="focus-ring rounded-full px-2 font-mono text-2xs uppercase tracking-widest text-ink-600 transition-colors hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-900"
                    >
                        {{ __('Run again') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="stop()"
                        class="focus-ring rounded-full px-2 font-mono text-2xs uppercase tracking-widest text-ink-600 transition-colors hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-900"
                    >
                        {{ __('Stop') }}
                    </button>
                </div>
            </div>

            <div class="rounded-lg bg-ink-100 p-3 dark:bg-ink-900">
                <iframe
                    x-ref="preview"
                    wire:ignore
                    :srcdoc="frame"
                    sandbox="allow-scripts"
                    title="{{ __('Sandboxed preview of the lab example') }}"
                    class="h-[22rem] w-full rounded-lg border border-ink-200 bg-white dark:border-ink-800"
                ></iframe>
            </div>

            <p class="mt-2 text-2xs leading-relaxed text-ink-500 dark:text-ink-400">
                {{ __('A loop belongs to the frame, not to this site: Run again or Stop replaces the document and the tab stays yours. There is no CPU limit, because a browser does not hand the page one to enforce.') }}
            </p>
        </div>

        <div>
            <div class="flex items-center justify-between gap-3 pb-2">
                <h3 class="font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                    {{ __('Console') }}
                    <span x-text="lines.length ? '(' + lines.length + ')' : ''" class="tabular-nums"></span>
                </h3>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        x-on:click="$copy(transcript())"
                        class="focus-ring rounded-full px-2 font-mono text-2xs uppercase tracking-widest text-ink-600 transition-colors hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-900"
                    >
                        {{ __('Copy') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="clear()"
                        class="focus-ring rounded-full px-2 font-mono text-2xs uppercase tracking-widest text-ink-600 transition-colors hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-ink-900"
                    >
                        {{ __('Clear') }}
                    </button>
                </div>
            </div>

            <div wire:ignore class="max-h-[24rem] overflow-y-auto rounded-lg bg-ink-950 px-4 py-3">
                <p x-show="lines.length === 0" class="font-mono text-2xs leading-relaxed text-ink-500">
                    {{ __('Nothing logged yet. console.log, console.warn and uncaught errors from the frame arrive here.') }}
                </p>

                <ul class="space-y-1.5">
                    <template x-for="(line, index) in lines" :key="index">
                        <li class="font-mono text-2xs leading-relaxed">
                            <span class="text-ink-600" x-text="line.at"></span>
                            <span
                                :class="line.level === 'error' ? 'text-rose-400' : (line.level === 'warn' ? 'text-amber-300' : 'text-ink-100')"
                                x-text="line.text"
                            ></span>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
</x-app.ui.section>
