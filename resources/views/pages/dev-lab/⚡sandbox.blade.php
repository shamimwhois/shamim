<?php

use App\Actions\DevLab\DeleteLabWorkspace;
use App\Actions\DevLab\RecordLabExecution;
use App\Actions\DevLab\SaveLabSnippet;
use App\Actions\DevLab\SaveLabWorkspace;
use App\Enums\AccountPermission;
use App\Enums\LabLanguage;
use App\Models\LabWorkspace;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The sandbox: three panes, one frame, and the boundary between them.
 *
 * This screen holds the whole of the Developer Lab's execution story, which is why
 * the message protocol, the shim and the composition of the document live in
 * `resources/js/lab-sandbox.js` rather than being spread across the module. There
 * is exactly one place in this application where user-supplied code is made
 * executable, and it is this frame.
 *
 * ## Where the authority sits
 *
 * The browser runs the code. The server decides whether a run is *recorded*. That
 * split is the reason a Run button is not a security hole: the frame is sandboxed
 * with `allow-scripts` and, deliberately, **without** `allow-same-origin`, so the
 * document it holds has an opaque origin and cannot read this site's cookies,
 * storage, or DOM, nor call a Livewire endpoint with a visitor's session attached.
 * The browser enforces that, which is the only place it could be enforced from.
 *
 * What the frame can still do is exhaust the tab it lives in. `while (true)` hangs
 * the visitor's own page. Three things bound that rather than one grand fix: the
 * preview never rebuilds on a keystroke, only on an explicit Run; reassigning the
 * frame's document destroys the looping one, so a second Run or a Stop replaces it;
 * and {@see RecordLabExecution} refuses past the day's allowance *server-side*, so
 * the button cannot be hammered by a hand-crafted request either. There is no CPU
 * or memory quota here, and the panel says so.
 *
 * ## Ownership
 *
 * A workspace is opened by numeric id in the query string and scoped to the
 * signed-in account in every lookup, so `?workspace=41` belonging to somebody else
 * reads as "not yours" rather than as their code. The `boot()` guard is the real
 * control for mutations: Livewire posts to `/livewire/update`, not to
 * `/dev-lab/sandbox`, so the route group's `permission:dev-lab:use` never runs for
 * a Save or a Run.
 *
 * ## One source of truth for the visible pane
 *
 * `active` is a server property. Narrow screens need it in markup (`x-show` through
 * `$wire`) *and* in the database of decisions the component makes (`makeSnippet`
 * used to guess from it, which is why the pane is now passed explicitly), and an
 * answer that lives in two places drifts. The cost is one round trip per tab click
 * on a phone, which is cheaper than a desynchronised editor.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Sandbox')] class extends Component
{
    /**
     * The workspace being edited, by id. Empty means unsaved code in a fresh buffer.
     */
    #[Url(as: 'workspace', except: '')]
    public string $workspace = '';

    /**
     * Which pane a narrow screen is showing. Ignored once the grid fits them all.
     */
    #[Url(as: 'pane', except: 'html')]
    public string $active = 'html';

    #[Validate('required|string|max:' . LabWorkspace::NAME_MAX)]
    public string $name = '';

    public string $html = '';

    public string $css = '';

    public string $js = '';

    public function boot(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(AccountPermission::UseDeveloperLab),
            403,
            __('You may not open the Developer Lab.')
        );
    }

    public function mount(): void
    {
        $model = $this->current();

        if ($model !== null) {
            $this->loadInto($model);
        } else {
            $this->name = __('Untitled experiment');

            if ($this->workspace !== '') {
                // The id was in the URL but did not resolve to a workspace of ours:
                // a deleted buffer, or somebody else's. Start clean and let the next
                // Save rewrite the query string, rather than 404ing a page that is
                // perfectly usable empty.
                $this->workspace = '';
            }
        }

        app(Seo::class)->set([
            'title' => 'Sandbox',
            'description' => 'Write HTML, CSS and JavaScript and run them in a sandboxed frame.',
            'noindex' => true,
        ]);
    }

    /**
     * Persist the three panes under a name, creating the workspace on first Save.
     */
    public function save(SaveLabWorkspace $save): void
    {
        $this->validate();

        try {
            $model = $save->handle(auth()->user(), [
                'name' => $this->name,
                'html' => $this->html,
                'css' => $this->css,
                'js' => $this->js,
            ], $this->current());
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->workspace = (string) $model->id;

        Flux::toast(variant: 'success', text: __('Workspace saved.'));
    }

    /**
     * Ask the server whether this run is allowed, then let the frame rebuild.
     *
     * The order matters and is not about tidiness. The refusal has to come from the
     * server, because the limit is a server-side rule, and the frame must not be
     * rebuilt when the answer was no: a Run that shows output while telling you that
     * you are out of runs teaches the reader that the message is decoration.
     */
    public function run(RecordLabExecution $record): void
    {
        if (! $this->hasCode()) {
            Flux::toast(variant: 'danger', text: __('There is nothing to run yet. Write something in a pane first.'));

            return;
        }

        try {
            $record->handle(auth()->user(), LabLanguage::Html, $this->name);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $model = $this->current();

        if ($model !== null) {
            $model->forceFill(['last_run_at' => Carbon::now()])->save();
        }

        $this->dispatch('lab-run');
    }

    /**
     * Turn one pane into a snippet, private until somebody publishes it.
     *
     * The pane arrives as an argument rather than being read off `active`. A snippet
     * filed under the wrong language is a permanent lie in a library, and the click
     * that made the request already knows which pane was meant.
     */
    public function makeSnippet(string $pane, SaveLabSnippet $save): void
    {
        $language = $this->languageFor($pane);
        $code = $this->paneValue($language);

        if (trim($code) === '') {
            Flux::toast(variant: 'danger', text: __('That pane is empty, so there is nothing to save as a snippet.'));

            return;
        }

        try {
            $snippet = $save->handle(auth()->user(), [
                'language' => $language,
                'title' => $this->name !== '' ? $this->name : $language->label() . ' snippet',
                'code' => $code,
            ]);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->redirectRoute('dev-lab.snippets.edit', ['snippet' => $snippet->slug], navigate: true);
    }

    /**
     * Discard the current workspace and open a clean buffer.
     *
     * Deleting the row is the honest meaning of this button: a workspace you have
     * thrown away should not be sitting in the database waiting to be found by the
     * next person who clicks the id in the URL. When the buffer was never saved there
     * is nothing to delete, and the panes are simply emptied.
     */
    public function discard(DeleteLabWorkspace $delete): void
    {
        $model = $this->current();

        if ($model !== null) {
            $delete->handle($model);
        }

        $this->reset('workspace', 'html', 'css', 'js');
        $this->name = __('Untitled experiment');

        $this->dispatch('lab-stop');

        Flux::toast(variant: 'success', text: __('Workspace discarded.'));
    }

    /**
     * Show one pane on a narrow screen.
     *
     * Accepts the preview and the console as well as the three editors, because on a
     * phone those are panes too, and a control that switches some of the panels while
     * leaving others to the CSS is a control nobody can predict.
     */
    public function show(string $pane): void
    {
        $this->active = in_array($pane, $this->paneKeys(), true) ? $pane : 'html';
    }

    /**
     * The workspace the query string points at, this account's only.
     */
    #[Computed]
    public function record(): ?LabWorkspace
    {
        return $this->current();
    }

    /**
     * Runs still allowed today, or null when the day is not capped.
     */
    #[Computed]
    public function remaining(): ?int
    {
        return app(RecordLabExecution::class)->remainingToday(auth()->user());
    }

    /**
     * The three editors: label, model, language and an example worth typing over.
     *
     * The placeholders live here rather than in a `match()` inside an HTML attribute,
     * because a JS example containing quotes has to survive both PHP string escaping
     * and attribute escaping, and it does not.
     *
     * @return array<int, array{key: string, label: string, language: LabLanguage, placeholder: string}>
     */
    public function panes(): array
    {
        return [
            [
                'key' => 'html',
                'label' => __('HTML'),
                'language' => LabLanguage::Html,
                'placeholder' => '<h1>Hello</h1>',
            ],
            [
                'key' => 'css',
                'label' => __('CSS'),
                'language' => LabLanguage::Css,
                'placeholder' => 'body { font-family: system-ui; padding: 2rem; }',
            ],
            [
                'key' => 'js',
                'label' => __('JavaScript'),
                'language' => LabLanguage::Javascript,
                'placeholder' => "console.log('ready')",
            ],
        ];
    }

    /**
     * The panels a narrow screen can switch to, in the order the tabs show them.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function views(): array
    {
        return [
            ['key' => 'preview', 'label' => __('Preview')],
            ['key' => 'console', 'label' => __('Console')],
        ];
    }

    /**
     * Every pane key, editors and panels together.
     *
     * @return array<int, string>
     */
    public function paneKeys(): array
    {
        return ['html', 'css', 'js', 'preview', 'console'];
    }

    /**
     * Read the workspace the current query string names, if this account owns it.
     */
    protected function current(): ?LabWorkspace
    {
        if ($this->workspace === '') {
            return null;
        }

        return LabWorkspace::query()
            ->whereKey((int) $this->workspace)
            ->where('user_id', auth()->id())
            ->first();
    }

    /**
     * Copy a stored workspace into the live properties.
     *
     * Named for what it does because `fill()` is Livewire's own form-filling API, and
     * overriding it here would quietly change what `$this->fill()` means anywhere the
     * framework calls it.
     */
    protected function loadInto(LabWorkspace $model): void
    {
        $this->name = $model->name;
        $this->html = (string) $model->html;
        $this->css = (string) $model->css;
        $this->js = (string) $model->js;
    }

    /**
     * Determine whether any pane holds something the frame would execute.
     */
    protected function hasCode(): bool
    {
        return trim($this->html) !== '' || trim($this->css) !== '' || trim($this->js) !== '';
    }

    /**
     * The language a pane key names. Anything that is not an editor is HTML.
     */
    protected function languageFor(string $pane): LabLanguage
    {
        return collect($this->panes())
            ->firstWhere('key', $pane)['language'] ?? LabLanguage::Html;
    }

    /**
     * The code of the pane the given language names.
     */
    protected function paneValue(LabLanguage $language): string
    {
        return match ($language) {
            LabLanguage::Html => $this->html,
            LabLanguage::Css => $this->css,
            LabLanguage::Javascript => $this->js,
            LabLanguage::Php => '',
        };
    }
}; ?>

<div
    x-data="labSandbox({ maxLineChars: {{ (int) config('dev_lab.max_log_line_chars') }} })"
    x-on:lab-run.window="run()"
    x-on:lab-stop.window="stop()"
>
    {{-- =============================================================== --
     | The bar: name, the day's allowance, and the three verbs
     -- =============================================================== --}}
    <div class="border-b border-sui-hairline bg-sui-raised">
        <div class="mx-auto flex w-full max-w-[110rem] flex-wrap items-end gap-3 px-5 py-4 sm:px-6 lg:px-10">
            <div class="min-w-0 flex-1">
                <label for="workspace-name" class="font-mono text-2xs uppercase tracking-widest text-ink-500">
                    {{ __('Workspace') }}
                </label>
                <input
                    id="workspace-name"
                    type="text"
                    wire:model.live.debounce.600ms="name"
                    class="sui-control mt-1.5 w-full max-w-md rounded-xl bg-white px-3 py-2 text-sm font-semibold tracking-tight text-ink-900"
                    placeholder="{{ __('Name this experiment') }}"
                />
                @error('name')
                    <p class="mt-1 text-2xs text-sui-danger">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <p class="mr-1 font-mono text-2xs uppercase tracking-widest text-ink-400" aria-live="polite">
                    @if ($this->remaining !== null)
                        {{ trans_choice(':count run left today|:count runs left today', $this->remaining, ['count' => $this->remaining]) }}
                    @else
                        {{ __('Unlimited runs') }}
                    @endif
                </p>

                <flux:button wire:click="run" variant="primary" size="sm" icon="play">
                    {{ __('Run') }}
                </flux:button>

                <flux:button wire:click="save" size="sm" icon="circle-stack">
                    {{ __('Save') }}
                </flux:button>

                <flux:button x-on:click="stop()" x-show="dirty" x-cloak size="sm" icon="stop">
                    {{ __('Stop') }}
                </flux:button>
            </div>
        </div>

        @if ($this->record !== null)
            <div class="mx-auto flex w-full max-w-[110rem] flex-wrap items-center gap-3 px-5 pb-3 sm:px-6 lg:px-10">
                <p class="text-2xs text-ink-500">
                    {{ __('Saved :ago', ['ago' => $this->record->updated_at->diffForHumans()]) }}
                    @if ($this->record->last_run_at !== null)
                        &middot; {{ __('run :ago', ['ago' => $this->record->last_run_at->diffForHumans()]) }}
                    @endif
                </p>

                <div x-data="{ confirming: false }" class="ml-auto">
                    <button
                        type="button"
                        x-show="! confirming"
                        x-on:click="confirming = true"
                        class="sui-focus font-mono text-2xs uppercase tracking-widest text-ink-500 transition-colors hover:text-sui-danger"
                    >
                        {{ __('Discard') }}
                    </button>

                    <div x-show="confirming" x-cloak class="flex items-center gap-2">
                        <span class="text-2xs text-sui-danger">{{ __('Delete this workspace for good?') }}</span>
                        <button
                            type="button"
                            wire:click="discard"
                            class="sui-focus rounded-full border border-sui-danger/30 px-2.5 py-1 font-mono text-2xs uppercase tracking-widest text-sui-danger transition-colors hover:bg-sui-danger/10"
                        >
                            {{ __('Yes, delete') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="confirming = false"
                            class="sui-focus rounded-full px-2.5 py-1 font-mono text-2xs uppercase tracking-widest text-ink-500 transition-colors hover:bg-sui-hover"
                        >
                            {{ __('Keep') }}
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- =============================================================== --
     | Pane chooser: narrow screens only, and one server-side answer
     -- =============================================================== --}}
    <div class="border-b border-sui-hairline px-5 py-3 lg:hidden">
        <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('Choose what to show') }}">
            @foreach ($this->panes() as $pane)
                <button
                    type="button"
                    role="tab"
                    wire:key="pane-tab-{{ $pane['key'] }}"
                    aria-selected="{{ $active === $pane['key'] ? 'true' : 'false' }}"
                    wire:click="show('{{ $pane['key'] }}')"
                    @class([
                        'sui-focus rounded-full border px-3 py-1 text-xs font-medium transition duration-150 ease-soft',
                        'border-sui-accent/30 bg-sui-accent-soft text-sui-accent-ink' => $active === $pane['key'],
                        'border-sui-hairline text-ink-500 hover:bg-sui-hover hover:text-ink-700' => $active !== $pane['key'],
                    ])
                >
                    {{ $pane['label'] }}
                </button>
            @endforeach

            @foreach ($this->views() as $view)
                <button
                    type="button"
                    role="tab"
                    wire:key="view-tab-{{ $view['key'] }}"
                    aria-selected="{{ $active === $view['key'] ? 'true' : 'false' }}"
                    wire:click="show('{{ $view['key'] }}')"
                    @class([
                        'sui-focus rounded-full border px-3 py-1 text-xs font-medium transition duration-150 ease-soft',
                        'border-sui-accent/30 bg-sui-accent-soft text-sui-accent-ink' => $active === $view['key'],
                        'border-sui-hairline text-ink-500 hover:bg-sui-hover hover:text-ink-700' => $active !== $view['key'],
                    ])
                >
                    {{ $view['label'] }}
                    <span x-text="lines.length ? '(' + lines.length + ')' : ''" class="tabular-nums"></span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- =============================================================== --
     | The editor and the frame
     -- =============================================================== --}}
    <div class="mx-auto w-full max-w-[110rem] px-5 py-5 sm:px-6 lg:px-10">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,32rem)]">
            {{-- ======================================================= --
             | The three editors
             -- ======================================================= --}}
            <div class="space-y-5">
                @foreach ($this->panes() as $pane)
                    <div
                        wire:key="pane-{{ $pane['key'] }}"
                        x-show="wide || $wire.active === '{{ $pane['key'] }}'"
                    >
                        <x-app.dev-lab.code-pane
                            :label="$pane['label']"
                            model="{{ $pane['key'] }}"
                            :max="(int) config('dev_lab.max_code_bytes')"
                            :hint="$pane['language']->runExplanation()"
                            :placeholder="$pane['placeholder']"
                            rows="14"
                        />

                        <div class="mt-2">
                            <flux:button
                                wire:click="makeSnippet('{{ $pane['key'] }}')"
                                size="xs"
                                variant="ghost"
                                icon="code-bracket"
                            >
                                {{ __('Save the :pane pane as a snippet', ['pane' => $pane['label']]) }}
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- ======================================================= --
             | Preview and console. The iframe and the transcript are the
             | two places the browser owns the DOM, so each is ignored by
             | the morph rather than the whole column, which would freeze
             | the notices above them at their first render.
             -- ======================================================= --}}
            <div class="space-y-5">
                <x-app.ui.section
                    :title="__('Live preview')"
                    description="The frame is sandboxed and has an opaque origin."
                    icon="window"
                    :padded="false"
                    x-show="wide || $wire.active === 'preview'"
                >
                    <x-slot:actions>
                        <span class="font-mono text-2xs uppercase tracking-widest text-ink-400">
                            {{ __('allow-scripts only') }}
                        </span>
                    </x-slot:actions>

                    <div class="bg-sui-hover p-4">
                        @if ($this->remaining === 0)
                            <p class="mb-3 rounded-lg border border-sui-attention-border bg-sui-attention-wash px-3 py-2 text-xs leading-normal text-sui-attention-ink">
                                {{ __('You have used every run allowed today, so the frame still shows the last document. The allowance resets at midnight, and nothing you saved is affected.') }}
                            </p>
                        @endif

                        <iframe
                            x-ref="preview"
                            wire:ignore
                            :srcdoc="frame"
                            sandbox="allow-scripts"
                            title="{{ __('Live preview of your code') }}"
                            class="h-[26rem] w-full rounded-lg border border-sui-hairline bg-white"
                        ></iframe>

                        <p class="mt-3 text-2xs leading-normal text-ink-500">
                            {{ __('It cannot read this site’s cookies, storage, or page, and cannot call the server with your session. It can still loop forever, so Stop or another Run replaces it. There is no CPU limit.') }}
                        </p>
                    </div>
                </x-app.ui.section>

                <x-app.ui.section
                    :title="__('Console')"
                    :description="__('console.log, console.warn and uncaught errors arrive here.')"
                    icon="command-line"
                    :padded="false"
                    x-show="wide || $wire.active === 'console'"
                >
                    <x-slot:actions>
                        <button
                            type="button"
                            x-on:click="$copy(transcript())"
                            class="sui-focus font-mono text-2xs uppercase tracking-widest text-ink-400 transition-colors hover:text-sui-accent-ink"
                        >
                            {{ __('Copy') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="clear()"
                            class="sui-focus font-mono text-2xs uppercase tracking-widest text-ink-400 transition-colors hover:text-sui-accent-ink"
                        >
                            {{ __('Clear') }}
                        </button>
                    </x-slot:actions>

                    <div wire:ignore class="max-h-72 overflow-y-auto bg-ink-950 px-4 py-3">
                        <p x-show="lines.length === 0" class="font-mono text-2xs leading-relaxed text-ink-500">
                            {{ __('Nothing has run yet. Press Run and the frame’s output appears here. alert(), confirm() and prompt() are blocked, and say so in this panel when called.') }}
                        </p>

                        <ul class="space-y-1.5">
                            <template x-for="(line, index) in lines" :key="index">
                                <li class="font-mono text-2xs leading-relaxed">
                                    <span class="text-ink-600" x-text="line.at"></span>
                                    <span
                                        :class="line.level === 'error' ? 'text-sui-danger' : (line.level === 'warn' ? 'text-sui-attention-ink' : 'text-ink-100')"
                                        x-text="line.text"
                                    ></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </x-app.ui.section>
            </div>
        </div>
    </div>
</div>
