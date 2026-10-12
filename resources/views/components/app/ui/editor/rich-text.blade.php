@props([
    'model' => 'body',
    'value' => '',
    'label' => null,
    'placeholder' => null,
    'minHeight' => '28rem',
    'imagesEnabled' => null,
])

@php
    /**
     * The rich text editor.
     *
     * ## Why Markdown and not a contenteditable surface
     *
     * The post body is stored as Markdown (`body_markdown`) and rendered
     * through {@see \App\Support\MarkdownRenderer}. A WYSIWYG surface would
     * have to emit HTML, which means a sanitiser, a new column, and a rewrite
     * of the public render path — for a feature the data model does not ask
     * for. Editing Markdown directly also keeps the preview honest: the author
     * sees exactly what the renderer will produce, not an approximation that
     * drifts from it.
     *
     * ## Why Alpine rather than Livewire state
     *
     * Every AI action must leave the cursor where it was. A Livewire round trip
     * re-renders the DOM, which loses the textarea's selection and scroll
     * position — the single most irritating thing a writing tool can do. The
     * component therefore owns its own state client-side and talks to the
     * server over plain JSON, syncing the finished value back up through
     * `$wire.set()` only when an action completes.
     *
     * ## State
     *
     * All of it lives in the single `x-data` object below. It is long because
     * the editor genuinely has a lot of state (toolbar mode, four independent
     * busy flags, a correction list, a generation dialog, an image dialog), and
     * splitting it across nested components would mean passing the textarea
     * ref around. It is grouped and commented rather than fragmented.
     */
    $imagesEnabled = $imagesEnabled ?? config('ai.editor.images_enabled', true);
    $editorId = 'editor-'.Illuminate\Support\Str::random(8);
    $categories = App\Enums\ContentCategory::cases();
@endphp

<div
    x-data="richTextEditor({
        model: @js($model),
        initialValue: @js($value),
        editorId: @js($editorId),
        imagesEnabled: @js((bool) $imagesEnabled),
    })"
    x-init="init($wire)"
    class="rich-editor"
    data-editor
>
    {{-- ============================================================ --
     | Toolbar
     | Sticky on scroll so formatting stays reachable in a long draft.
     --}}
    <div class="sticky top-0 z-20 rounded-t-xl border border-b-0 border-sui-hairline bg-sui-well/95 backdrop-blur-sm">
        {{-- Row 1: writing tools --}}
        <div class="flex flex-wrap items-center gap-0.5 border-b border-sui-hairline px-2 py-1.5">
            {{-- Mode switch --}}
            <div class="flex items-center rounded-md border border-sui-hairline bg-sui-surface p-0.5" role="group" aria-label="{{ __('Editor mode') }}">
                <template x-for="option in modes" :key="option.value">
                    <button
                        type="button"
                        @click="setMode(option.value)"
                        :aria-pressed="mode === option.value"
                        class="sui-focus rounded-sm px-2 py-1 text-2xs font-semibold transition duration-150 ease-soft"
                        :class="mode === option.value
                            ? 'bg-sui-accent text-white shadow-soft-1'
                            : 'text-ink-500 hover:bg-sui-hover hover:text-sui-ink'"
                        x-text="option.label"
                    ></button>
                </template>
            </div>

            <span class="mx-1 hidden h-5 w-px bg-sui-hairline sm:block" aria-hidden="true"></span>

            {{-- Formatting --}}
            <template x-for="tool in formatTools" :key="tool.action">
                <button
                    type="button"
                    @click="applyFormat(tool.action)"
                    :title="tool.label + ' — ' + tool.hint"
                    :aria-label="tool.label"
                    class="sui-focus grid size-8 place-items-center rounded-sm text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-accent-ink"
                >
                    <span class="text-xs font-semibold" x-text="tool.glyph"></span>
                </button>
            </template>

            <span class="mx-1 hidden h-5 w-px bg-sui-hairline sm:block" aria-hidden="true"></span>

            {{-- Insert --}}
            <template x-for="tool in insertTools" :key="tool.action">
                <button
                    type="button"
                    @click="tool.action === 'image' ? openImageDialog() : applyFormat(tool.action)"
                    :title="tool.label"
                    :aria-label="tool.label"
                    class="sui-focus grid size-8 place-items-center rounded-sm text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-accent-ink"
                >
                    <span class="text-xs font-semibold" x-text="tool.glyph"></span>
                </button>
            </template>

            {{-- Statistics, right-aligned --}}
            <div class="ml-auto flex items-center gap-3 pr-1 text-2xs tabular-nums text-ink-400">
                <span x-text="stats.words.toLocaleString() + ' words'"></span>
                <span class="hidden sm:inline" x-text="stats.minutes + ' min read'"></span>
            </div>
        </div>

        {{-- Row 2: AI tools --}}
        <div
            x-show="capabilities.configured"
            x-cloak
            class="flex flex-wrap items-center gap-1 px-2 py-1.5"
        >
            <span class="mr-1 flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-ink-400">
                <flux:icon name="sparkles" class="size-3.5" />
                {{ __('AI') }}
            </span>

            <button
                type="button"
                @click="reviewText()"
                :disabled="busy.correct || ! stats.words"
                class="sui-focus inline-flex items-center gap-1.5 rounded-md border border-sui-hairline bg-sui-surface px-2.5 py-1.5 text-xs font-medium text-ink-600 transition duration-150 ease-soft hover:border-sui-accent/40 hover:text-sui-accent-ink disabled:cursor-not-allowed disabled:opacity-40"
            >
                <flux:icon name="check-circle" class="size-3.5" x-show="! busy.correct" />
                <flux:icon name="arrow-path" class="size-3.5 animate-spin" x-show="busy.correct" x-cloak />
                <span x-text="busy.correct ? @js(__('Reviewing…')) : @js(__('Review'))"></span>
            </button>

            <button
                type="button"
                @click="openGenerateDialog()"
                :disabled="busy.generate"
                class="sui-focus inline-flex items-center gap-1.5 rounded-md border border-sui-hairline bg-sui-surface px-2.5 py-1.5 text-xs font-medium text-ink-600 transition duration-150 ease-soft hover:border-sui-accent/40 hover:text-sui-accent-ink disabled:cursor-not-allowed disabled:opacity-40"
            >
                <flux:icon name="sparkles" class="size-3.5" />
                {{ __('Write') }}
            </button>

            <button
                type="button"
                @click="openImageDialog()"
                x-show="imagesEnabled && capabilities.images"
                x-cloak
                class="sui-focus inline-flex items-center gap-1.5 rounded-md border border-sui-hairline bg-sui-surface px-2.5 py-1.5 text-xs font-medium text-ink-600 transition duration-150 ease-soft hover:border-sui-accent/40 hover:text-sui-accent-ink disabled:cursor-not-allowed disabled:opacity-40"
            >
                <flux:icon name="photo" class="size-3.5" />
                {{ __('Image') }}
            </button>

            {{-- Inline status --}}
            <span
                x-show="status.message"
                x-cloak
                x-transition.opacity
                class="ml-1 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs"
                :class="status.kind === 'error'
                    ? 'border border-sui-danger/25 bg-sui-danger-soft text-sui-danger'
                    : 'border border-sui-success/25 bg-sui-success-soft text-sui-success'"
            >
                <flux:icon name="check-circle" class="size-3.5" x-show="status.kind !== 'error'" />
                <flux:icon name="exclamation-triangle" class="size-3.5" x-show="status.kind === 'error'" x-cloak />
                <span x-text="status.message"></span>
            </span>
        </div>
    </div>

    {{-- ============================================================ --
     | Editing surface
     --}}
    <div class="grid border border-sui-hairline bg-sui-surface" :class="mode === 'split' ? 'lg:grid-cols-2' : ''">
        {{-- Source --}}
        <div x-show="mode !== 'preview'" class="min-w-0">
            @if ($label)
                <label for="{{ $editorId }}" class="sr-only">{{ $label }}</label>
            @endif

            <textarea
                id="{{ $editorId }}"
                x-ref="input"
                x-model="text"
                @input.debounce.800ms="syncStats(); queueSync()"
                @keydown="handleKeydown($event)"
                spellcheck="true"
                placeholder="{{ $placeholder ?? __('Start writing…') }}"
                class="w-full resize-y border-0 bg-transparent px-4 py-3 text-sm leading-normal text-sui-ink placeholder:text-ink-400 focus:outline-none focus:ring-0"
                style="min-height: {{ $minHeight }}"
                :class="mode === 'split' ? 'lg:border-r lg:border-sui-hairline' : ''"
            ></textarea>
        </div>

        {{-- Preview --}}
        <div x-show="mode !== 'write'" class="min-w-0" :class="mode === 'preview' ? 'col-span-full' : ''">
            <p class="border-b border-sui-hairline px-4 py-2 text-2xs font-semibold uppercase tracking-wide text-ink-400 lg:border-b-0 lg:border-l">
                {{ __('Preview') }}
            </p>
            <div
                x-ref="preview"
                class="prose-editorial px-4 py-3 text-sm"
                style="min-height: {{ $minHeight }}"
            ></div>
        </div>
    </div>

    {{-- ============================================================ --
     | Corrections panel
     --}}
    <div
        x-show="corrections.length > 0 || reviewing"
        x-cloak
        x-transition
        class="mt-3 overflow-hidden rounded-xl border border-sui-warning/25 bg-sui-warning-soft"
    >
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-sui-warning/20 px-4 py-2.5">
            <div class="flex items-center gap-2">
                <flux:icon name="sparkles" class="size-4 text-sui-warning" />
                <h3 class="text-sm font-semibold tracking-tight text-ink-900">
                    {{ __('Suggestions') }}
                </h3>
                <span
                    class="sui-chip border border-sui-warning/25 bg-sui-surface text-sui-warning-ink"
                    x-text="corrections.length"
                ></span>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    @click="acceptAllMechanical()"
                    :disabled="! corrections.some(c => c.mechanical)"
                    class="sui-focus rounded-md border border-sui-hairline bg-sui-surface px-2.5 py-1 text-xs font-medium text-ink-600 transition duration-150 ease-soft hover:border-sui-accent/40 hover:text-sui-accent-ink disabled:cursor-not-allowed disabled:opacity-40"
                >
                    {{ __('Fix mechanical') }}
                </button>
                <button
                    type="button"
                    @click="dismissAllCorrections()"
                    class="sui-focus sui-action !px-2 !py-1 !text-xs"
                >
                    {{ __('Dismiss all') }}
                </button>
            </div>
        </div>

        {{-- Loading --}}
        <div x-show="reviewing" class="flex items-center gap-2.5 px-4 py-3 text-sm text-ink-500">
            <flux:icon name="arrow-path" class="size-4 animate-spin" />
            {{ __('Reading your text…') }}
        </div>

        {{-- List --}}
        <ul x-show="! reviewing" class="max-h-96 divide-y divide-sui-warning/15 overflow-y-auto">
            <template x-for="correction in corrections" :key="correction.id">
                <li class="px-4 py-3">
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-0.5 shrink-0 uppercase tracking-wide"
                            :class="typeBadgeClass(correction.type)"
                            x-text="typeLabel(correction.type)"
                        ></span>

                        <div class="min-w-0 flex-1">
                            {{-- Diff --}}
                            <p class="text-sm leading-normal">
                                <span class="rounded-xs border border-sui-danger/20 bg-sui-danger-soft px-1 text-sui-danger line-through decoration-sui-danger/40" x-text="correction.original"></span>
                                <span class="mx-1.5 text-ink-400" aria-hidden="true">&rarr;</span>
                                <span class="rounded-xs border border-sui-success/20 bg-sui-success-soft px-1 font-medium text-sui-success" x-text="correction.replacement"></span>
                            </p>

                            <p
                                x-show="correction.explanation"
                                class="mt-1 text-xs leading-normal text-ink-500"
                                x-text="correction.explanation"
                            ></p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                @click="acceptCorrection(correction)"
                                :aria-label="@js(__('Apply')) + ': ' + correction.replacement"
                                class="sui-focus grid size-7 place-items-center rounded-sm bg-sui-accent text-white transition duration-150 ease-soft hover:bg-sui-accent-hover"
                            >
                                <flux:icon name="check" class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                @click="dismissCorrection(correction)"
                                :aria-label="@js(__('Dismiss')) + ': ' + correction.replacement"
                                class="sui-focus grid size-7 place-items-center rounded-sm border border-sui-hairline text-ink-500 transition duration-150 ease-soft hover:bg-sui-surface hover:text-sui-accent-ink"
                            >
                                <flux:icon name="x-mark" class="size-3.5" />
                            </button>
                        </div>
                    </div>
                </li>
            </template>
        </ul>
    </div>

    {{-- ============================================================ --
     | Generate dialog
     --}}
    <template x-teleport="body">
        <div
            x-show="dialogs.generate"
            x-cloak
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="editor-generate-title"
            @keydown.escape.window="dialogs.generate = false"
        >
            <div
                class="fixed inset-0 bg-ink-950/50 backdrop-blur-sm"
                x-show="dialogs.generate"
                x-transition.opacity
                @click="dialogs.generate = false"
                aria-hidden="true"
            ></div>

            <div
                x-show="dialogs.generate"
                x-transition
                class="relative my-auto w-full max-w-2xl overflow-hidden rounded-xl border border-sui-hairline bg-sui-surface shadow-soft-3"
            >
                <div class="flex items-start justify-between gap-4 border-b border-sui-hairline px-5 py-4">
                    <div>
                        <h2 id="editor-generate-title" class="flex items-center gap-2 text-sm font-semibold tracking-tight text-ink-900">
                            <flux:icon name="sparkles" class="size-4 text-sui-accent" />
                            {{ __('Write with AI') }}
                        </h2>
                        <p class="mt-1 text-xs leading-normal text-ink-500">
                            {{ __('Pick a format, describe what you need, and edit the draft from there.') }}
                        </p>
                    </div>

                    <button
                        type="button"
                        @click="dialogs.generate = false"
                        class="sui-focus sui-action !px-2 !py-2"
                        aria-label="{{ __('Close') }}"
                    >
                        <flux:icon name="x-mark" class="size-4" />
                    </button>
                </div>

                <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
                    {{-- Category --}}
                    <fieldset>
                        <legend class="text-xs font-medium text-ink-600">{{ __('What are you writing?') }}</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            {{--
                                Rendered server-side from the enum rather than
                                looped over `capabilities.categories` in Alpine.
                                The list is static, so shipping it through JSON
                                bought nothing — and it forced a dynamic
                                `<flux:icon ::name>` binding, which Blaze cannot
                                resolve to a concrete icon view and instead
                                re-enters this template forever. A static icon
                                per category avoids that entirely.
                            --}}
                            @foreach ($categories as $category)
                                <button
                                    type="button"
                                    @click="selectCategory({
                                        value: @js($category->value),
                                        label: @js($category->label()),
                                        requires_source: @js($category->requiresSourceText()),
                                    })"
                                    :aria-pressed="form.category === @js($category->value)"
                                    class="sui-focus flex items-start gap-2.5 rounded-lg border p-2.5 text-left transition duration-150 ease-soft"
                                    :class="form.category === @js($category->value)
                                        ? '!border-sui-accent/40 bg-sui-accent-soft'
                                        : 'border-sui-hairline hover:border-sui-border hover:bg-sui-hover'"
                                >
                                    <span
                                        class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-md border"
                                        :class="form.category === @js($category->value)
                                            ? 'border-sui-accent/30 bg-sui-surface text-sui-accent-ink'
                                            : 'border-sui-hairline bg-sui-well text-ink-400'"
                                    >
                                        <flux:icon :name="$category->icon()" class="size-3.5" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-xs font-semibold text-ink-900">
                                            {{ $category->label() }}
                                        </span>
                                        <span class="mt-0.5 block text-2xs leading-normal text-ink-500">
                                            {{ $category->description() }}
                                        </span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </fieldset>

                    {{-- Subject --}}
                    <div class="mt-4">
                        <label for="editor-subject" class="sui-control-label">
                            <span x-text="selectedCategory?.requires_source ? @js(__('Text to rewrite')) : @js(__('Subject'))"></span>
                        </label>
                        <template x-if="! selectedCategory?.requires_source">
                            <input
                                id="editor-subject"
                                type="text"
                                x-model="form.subject"
                                maxlength="500"
                                placeholder="{{ __('e.g. Why queue workers need idempotency') }}"
                                class="sui-control mt-1.5 w-full"
                            />
                        </template>
                        <template x-if="selectedCategory?.requires_source">
                            <textarea
                                x-model="form.source_text"
                                rows="5"
                                maxlength="20000"
                                placeholder="{{ __('Paste the text you want reworked…') }}"
                                class="sui-control mt-1.5 w-full resize-y text-xs leading-normal"
                            ></textarea>
                        </template>
                    </div>

                    {{-- Tone + length --}}
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="editor-tone" class="sui-control-label">{{ __('Tone') }}</label>
                            <select
                                id="editor-tone"
                                x-model="form.tone"
                                class="sui-control mt-1.5 w-full"
                            >
                                <template x-for="tone in capabilities.tones" :key="tone.value">
                                    <option :value="tone.value" x-text="tone.label"></option>
                                </template>
                            </select>
                        </div>

                        <div>
                            <label for="editor-words" class="sui-control-label">
                                {{ __('Target length') }}
                                <span class="font-normal text-ink-400" x-show="selectedCategory" x-text="'(' + (selectedCategory?.min_words ?? 0) + '–' + (selectedCategory?.max_words ?? 0) + ' ' + @js(__('words')) + ')'"></span>
                            </label>
                            <input
                                id="editor-words"
                                type="number"
                                x-model.number="form.word_count"
                                :min="selectedCategory?.min_words ?? 100"
                                :max="selectedCategory?.max_words ?? 2000"
                                class="sui-control mt-1.5 w-full"
                            />
                        </div>
                    </div>

                    {{-- Advanced --}}
                    <details class="mt-4 rounded-md border border-sui-hairline">
                        <summary class="sui-focus cursor-pointer px-3 py-2 text-xs font-medium text-ink-600">
                            {{ __('Who is it for? (optional)') }}
                        </summary>
                        <div class="space-y-3 border-t border-sui-hairline px-3 py-3">
                            <div>
                                <label for="editor-audience" class="sui-control-label !text-xs">{{ __('Audience') }}</label>
                                <input
                                    id="editor-audience"
                                    type="text"
                                    x-model="form.audience"
                                    maxlength="200"
                                    placeholder="{{ __('e.g. backend developers evaluating a queue system') }}"
                                    class="sui-control mt-1 w-full !py-1.5 text-xs"
                                />
                            </div>
                            <div>
                                <label for="editor-keywords" class="sui-control-label !text-xs">{{ __('Keywords to include') }}</label>
                                <input
                                    id="editor-keywords"
                                    type="text"
                                    x-model="form.keywords"
                                    maxlength="300"
                                    placeholder="{{ __('e.g. idempotency, at-least-once delivery') }}"
                                    class="sui-control mt-1 w-full !py-1.5 text-xs"
                                />
                            </div>
                        </div>
                    </details>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-sui-hairline px-5 py-3">
                    <p class="text-2xs leading-normal text-ink-400">
                        {{ __('AI drafts are a starting point — always review before publishing.') }}
                    </p>
                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            @click="dialogs.generate = false"
                            class="sui-focus sui-action !px-3 !py-1.5 !text-xs"
                        >
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="button"
                            @click="generate()"
                            :disabled="generating || ! canGenerate"
                            class="sui-focus inline-flex items-center gap-1.5 rounded-md bg-sui-accent px-3 py-1.5 text-xs font-semibold text-white shadow-soft-1 transition duration-150 ease-soft hover:bg-sui-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <flux:icon name="arrow-path" class="size-3.5 animate-spin" x-show="generating" x-cloak />
                            <flux:icon name="sparkles" class="size-3.5" x-show="! generating" />
                            <span x-text="generating ? @js(__('Writing…')) : @js(__('Generate'))"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- ============================================================ --
     | Image dialog
     --}}
    <template x-teleport="body">
        <div
            x-show="dialogs.image"
            x-cloak
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="editor-image-title"
            @keydown.escape.window="dialogs.image = false"
        >
            <div class="fixed inset-0 bg-ink-950/50 backdrop-blur-sm" x-show="dialogs.image" x-transition.opacity @click="dialogs.image = false" aria-hidden="true"></div>

            <div x-show="dialogs.image" x-transition class="relative my-auto w-full max-w-xl overflow-hidden rounded-xl border border-sui-hairline bg-sui-surface shadow-soft-3">
                <div class="flex items-start justify-between gap-4 border-b border-sui-hairline px-5 py-4">
                    <div>
                        <h2 id="editor-image-title" class="flex items-center gap-2 text-sm font-semibold tracking-tight text-ink-900">
                            <flux:icon name="photo" class="size-4 text-sui-accent" />
                            {{ __('Generate an image') }}
                        </h2>
                        <p class="mt-1 text-xs leading-normal text-ink-500">
                            {{ __('Describe what you want. The prompt is refined before the image is generated.') }}
                        </p>
                    </div>
                    <button type="button" @click="dialogs.image = false" class="sui-focus sui-action !px-2 !py-2" aria-label="{{ __('Close') }}">
                        <flux:icon name="x-mark" class="size-4" />
                    </button>
                </div>

                <div class="max-h-[70vh] space-y-4 overflow-y-auto px-5 py-4">
                    <div>
                        <label for="editor-image-idea" class="sui-control-label">{{ __('Your idea') }}</label>
                        <textarea
                            id="editor-image-idea"
                            x-model="image.idea"
                            rows="3"
                            maxlength="1000"
                            placeholder="{{ __('e.g. a diagram of a queue with workers pulling jobs off it') }}"
                            class="sui-control mt-1.5 w-full resize-y"
                        ></textarea>
                    </div>

                    {{-- Refined prompt, once prepared --}}
                    <div x-show="image.prompt" x-cloak x-transition>
                        <label for="editor-image-prompt" class="sui-control-label">
                            {{ __('Refined prompt') }}
                            <span class="font-normal text-ink-400">{{ __('— edit if the model misunderstood') }}</span>
                        </label>
                        <textarea
                            id="editor-image-prompt"
                            x-model="image.prompt"
                            rows="4"
                            maxlength="2000"
                            class="sui-control mt-1.5 w-full resize-y !bg-sui-accent-softer text-xs leading-normal"
                        ></textarea>

                        <div class="mt-2">
                            <label for="editor-image-alt" class="sui-control-label !text-xs">{{ __('Alt text (for screen readers)') }}</label>
                            <input
                                id="editor-image-alt"
                                type="text"
                                x-model="image.alt"
                                maxlength="250"
                                class="sui-control mt-1 w-full !py-1.5 text-xs"
                            />
                        </div>
                    </div>

                    {{-- Result --}}
                    <div x-show="image.result" x-cloak x-transition class="overflow-hidden rounded-md border border-sui-hairline">
                        <img :src="image.result?.url" :alt="image.alt" class="w-full bg-sui-well" />
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-sui-hairline px-5 py-3">
                    <p class="text-2xs leading-normal text-ink-400">
                        {{ __('Generated images are saved to the public disk.') }}
                    </p>
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" @click="dialogs.image = false" class="sui-focus sui-action !px-3 !py-1.5 !text-xs">
                            {{ __('Cancel') }}
                        </button>

                        <button
                            type="button"
                            @click="prepareImagePrompt()"
                            :disabled="image.preparing || image.idea.length < 3"
                            x-show="! image.result"
                            class="sui-focus inline-flex items-center gap-1.5 rounded-md border border-sui-hairline bg-sui-surface px-3 py-1.5 text-xs font-medium text-ink-600 transition duration-150 ease-soft hover:border-sui-accent/40 hover:text-sui-accent-ink disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <flux:icon name="arrow-path" class="size-3.5 animate-spin" x-show="image.preparing" x-cloak />
                            <flux:icon name="sparkles" class="size-3.5" x-show="! image.preparing" />
                            <span x-text="image.prompt ? @js(__('Refine again')) : @js(__('Refine prompt'))"></span>
                        </button>

                        <button
                            type="button"
                            @click="generateImage()"
                            :disabled="image.generating || ! image.prompt"
                            x-show="! image.result"
                            class="sui-focus inline-flex items-center gap-1.5 rounded-md bg-sui-accent px-3 py-1.5 text-xs font-semibold text-white shadow-soft-1 transition duration-150 ease-soft hover:bg-sui-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <flux:icon name="arrow-path" class="size-3.5 animate-spin" x-show="image.generating" x-cloak />
                            <flux:icon name="photo" class="size-3.5" x-show="! image.generating" />
                            <span x-text="image.generating ? @js(__('Generating…')) : @js(__('Generate'))"></span>
                        </button>

                        <button
                            type="button"
                            @click="insertImage()"
                            x-show="image.result"
                            x-cloak
                            class="sui-focus inline-flex items-center gap-1.5 rounded-md bg-sui-accent px-3 py-1.5 text-xs font-semibold text-white shadow-soft-1 transition duration-150 ease-soft hover:bg-sui-accent-hover"
                        >
                            <flux:icon name="check" class="size-3.5" />
                            {{ __('Insert into post') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- Hidden mirror so Livewire validation errors still render. --}}
    <div x-show="false" aria-hidden="true">
        <input type="text" x-model="text" />
    </div>

    @error($model)
        <p class="mt-2 text-xs leading-normal text-sui-danger">{{ $message }}</p>
    @enderror
</div>
