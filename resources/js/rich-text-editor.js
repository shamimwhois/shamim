/**
 * Rich text editor behaviour.
 *
 * Registered as an Alpine data provider so the Blade component stays markup.
 * The alternative — a 400-line `x-data="…"` attribute in the template — is
 * unreadable, unindentable, and impossible to unit test.
 *
 * ## The three invariants this file protects
 *
 * 1. **The author's cursor never moves on its own.** Every AI action that
 *    rewrites text restores the selection afterwards, or places the cursor at
 *    the end of what it inserted. Nothing silently jumps to position 0.
 *
 * 2. **The textarea is the source of truth.** Corrections are applied by
 *    splicing `[start, end)` offsets into the current string, never by string
 *    replacement, so a phrase appearing twice is only fixed where the
 *    suggestion pointed.
 *
 * 3. **A failing AI call leaves the document untouched.** Every network path
 *    sets a status message and returns. None of them mutate `text` on the way
 *    out.
 */

/**
 * Markdown formatting operations.
 *
 * Pure string transforms: they take the current text plus a selection and
 * return the new text plus the new selection. Keeping them pure makes them
 * testable and, more importantly, makes "the cursor moved somewhere it should
 * not have" a bug in one place rather than in eight.
 */
const MarkdownOps = {
    /**
     * Wrap the selection in a prefix/suffix pair.
     */
    wrap(text, start, end, prefix, suffix = prefix, placeholder = '') {
        const selected = text.slice(start, end) || placeholder;
        const before = text.slice(0, start);
        const after = text.slice(end);

        return {
            text: `${before}${prefix}${selected}${suffix}${after}`,
            // Select the inner text so a second click unwraps naturally and
            // typing replaces the placeholder.
            start: start + prefix.length,
            end: start + prefix.length + selected.length,
        };
    },

    /**
     * Prefix each line of the selection.
     */
    prefixLines(text, start, end, prefix, { numbered = false } = {}) {
        // Expand the selection to whole lines so a partial-line selection does
        // not produce a broken marker mid-word.
        const lineStart = text.lastIndexOf('\n', start - 1) + 1;
        const lineEndIndex = text.indexOf('\n', end);
        const lineEnd = lineEndIndex === -1 ? text.length : lineEndIndex;

        const block = text.slice(lineStart, lineEnd);
        const lines = block.split('\n');

        const prefixed = lines
            .map((line, index) => `${numbered ? `${index + 1}. ` : prefix}${line}`)
            .join('\n');

        return {
            text: text.slice(0, lineStart) + prefixed + text.slice(lineEnd),
            start: lineStart,
            end: lineStart + prefixed.length,
        };
    },

    /**
     * Insert a block of text on its own lines, at the cursor.
     */
    insertBlock(text, start, end, block) {
        const before = text.slice(0, start);
        const after = text.slice(end);

        // Ensure the block starts on a fresh line without adding a third
        // newline when the cursor is already at one.
        const needsLeading = before.length > 0 && !before.endsWith('\n');
        const payload = `${needsLeading ? '\n' : ''}${block}\n`;

        return {
            text: before + payload + after,
            start: before.length + payload.length,
            end: before.length + payload.length,
        };
    },
};

/**
 * Create the editor's Alpine state.
 *
 * @param {object} config
 * @param {string} config.model        Livewire property name to sync into.
 * @param {string} config.initialValue Starting Markdown.
 * @param {string} config.editorId     Element id of the textarea.
 * @param {boolean} config.imagesEnabled
 */
export function richTextEditor({ model, initialValue, editorId, imagesEnabled = true }) {
    return {
        /* ---------------------------------------------------------- state */

        model,
        editorId,
        imagesEnabled,

        /** The document. */
        text: initialValue ?? '',

        /** `write` | `split` | `preview` */
        mode: 'split',

        /** Derived, recomputed on input rather than on every keystroke render. */
        stats: { words: 0, characters: 0, minutes: 1 },

        /** What this site can currently do. Filled by `init()`. */
        capabilities: {
            configured: false,
            images: false,
            categories: [],
            tones: [],
            correction_types: [],
        },

        /** Independent busy flags — one per operation, so they cannot interfere. */
        busy: { correct: false, generate: false },
        reviewing: false,
        generating: false,

        /** `{ kind: 'info'|'error', message: string }` */
        status: { kind: 'info', message: '' },

        /** Live correction suggestions. */
        corrections: [],

        dialogs: { generate: false, image: false },

        form: {
            category: 'blog-post',
            tone: 'conversational',
            subject: '',
            source_text: '',
            word_count: 600,
            audience: '',
            keywords: '',
        },

        image: {
            idea: '',
            prompt: '',
            alt: '',
            result: null,
            preparing: false,
            generating: false,
        },

        /** Timer handle for the debounced Livewire sync. */
        syncTimer: null,

        /** The Livewire component, injected by `init()`. */
        wire: null,

        /* --------------------------------------------------- static config */

        modes: [
            { value: 'write', label: 'Write' },
            { value: 'split', label: 'Split' },
            { value: 'preview', label: 'Preview' },
        ],

        /**
         * The formatting toolbar.
         *
         * `glyph` is a monospace character, not an icon: Markdown is a text
         * format, so `**B**` communicates what the button does more directly
         * than a bold "B" icon that could mean any rich-text editor.
         */
        formatTools: [
            { action: 'bold', glyph: 'B', label: 'Bold', hint: 'Ctrl+B' },
            { action: 'italic', glyph: 'I', label: 'Italic', hint: 'Ctrl+I' },
            { action: 'heading', glyph: 'H', label: 'Heading', hint: 'Ctrl+Alt+2' },
            { action: 'link', glyph: '↗', label: 'Link', hint: 'Ctrl+K' },
            { action: 'code', glyph: '<>', label: 'Inline code', hint: 'Ctrl+E' },
            { action: 'bullet', glyph: '•', label: 'Bullet list', hint: '' },
            { action: 'number', glyph: '1.', label: 'Numbered list', hint: '' },
            { action: 'quote', glyph: '❝', label: 'Quote', hint: '' },
        ],

        insertTools: [
            { action: 'codeblock', glyph: '{ }', label: 'Code block' },
            { action: 'rule', glyph: '—', label: 'Divider' },
            { action: 'image', glyph: '▣', label: 'Insert image' },
        ],

        /* ------------------------------------------------------------ boot */

        /**
         * Wire up Livewire, load capabilities, and paint the initial preview.
         */
        async init(wire) {
            this.wire = wire;

            this.syncStats();
            this.renderPreview();

            try {
                const caps = await window.EditorAi.capabilities();

                this.capabilities = {
                    configured: caps.configured ?? false,
                    images: caps.images ?? false,
                    categories: caps.categories ?? [],
                    tones: caps.tones ?? [],
                    correction_types: caps.correction_types ?? [],
                };

                // Preselect the first category and let it set the tone and
                // length defaults, so the dialog is usable without a click.
                const first = this.capabilities.categories[0];

                if (first) {
                    this.selectCategory(first);
                }

                // A configured site with no images just hides that button; the
                // rest of the AI toolbar still works.
                if (! this.capabilities.images) {
                    this.imagesEnabled = false;
                }
            } catch {
                // Capabilities failing is not fatal — the editor works as a
                // plain Markdown editor, which is the correct degraded state.
                this.capabilities.configured = false;
            }
        },

        /* --------------------------------------------------- text plumbing */

        /**
         * Get the textarea element.
         */
        el() {
            return this.$refs.input;
        },

        /**
         * Replace the document and place the selection.
         *
         * The single mutation point. Everything that edits text goes through
         * here, so selection restoration and syncing only exist once.
         */
        apply({ text, start, end }) {
            this.text = text;

            this.$nextTick(() => {
                const el = this.el();

                if (! el) {
                    return;
                }

                el.focus();
                el.setSelectionRange(start, end);

                // Keep the caret in view when a long document is edited near
                // the end — without this the browser scrolls to the top.
                const lineCount = text.slice(0, start).split('\n').length;
                const lineHeight = parseFloat(getComputedStyle(el).lineHeight) || 20;
                const target = (lineCount - 1) * lineHeight;

                if (target < el.scrollTop || target > el.scrollTop + el.clientHeight - lineHeight * 2) {
                    el.scrollTop = Math.max(0, target - el.clientHeight / 2);
                }
            });

            this.syncStats();
            this.renderPreview();
            this.queueSync();
        },

        /**
         * Apply a keyboard shortcut or toolbar action.
         */
        applyFormat(action) {
            const el = this.el();

            if (! el) {
                return;
            }

            const start = el.selectionStart;
            const end = el.selectionEnd;
            const text = this.text;

            const result = (() => {
                switch (action) {
                    case 'bold':
                        return MarkdownOps.wrap(text, start, end, '**', '**', 'bold text');
                    case 'italic':
                        return MarkdownOps.wrap(text, start, end, '_', '_', 'italic text');
                    case 'code':
                        return MarkdownOps.wrap(text, start, end, '`', '`', 'code');
                    case 'heading':
                        return MarkdownOps.prefixLines(text, start, end, '## ');
                    case 'bullet':
                        return MarkdownOps.prefixLines(text, start, end, '- ');
                    case 'number':
                        return MarkdownOps.prefixLines(text, start, end, '', { numbered: true });
                    case 'quote':
                        return MarkdownOps.prefixLines(text, start, end, '> ');
                    case 'link': {
                        const selected = text.slice(start, end);
                        const label = selected || 'link text';
                        const snippet = `[${label}](https://)`;

                        return {
                            text: text.slice(0, start) + snippet + text.slice(end),
                            // Put the caret inside the URL brackets so the
                            // author can paste immediately.
                            start: start + label.length + 3,
                            end: start + snippet.length - 1,
                        };
                    }
                    case 'codeblock':
                        return MarkdownOps.insertBlock(text, start, end, '```php\n\n```');
                    case 'rule':
                        return MarkdownOps.insertBlock(text, start, end, '---');
                    default:
                        return { text, start, end };
                }
            })();

            this.apply(result);
        },

        /**
         * Keyboard shortcuts that a writer expects.
         *
         * Deliberately conservative: only bindings that are conventional in
         * every editor, and never one that shadows a browser or OS shortcut.
         */
        handleKeydown(event) {
            const mod = event.metaKey || event.ctrlKey;

            if (! mod) {
                // Tab inserts two spaces rather than leaving the field.
                if (event.key === 'Tab') {
                    event.preventDefault();

                    const el = this.el();
                    const start = el.selectionStart;

                    this.apply({ text: `${this.text.slice(0, start)}  ${this.text.slice(el.selectionEnd)}`, start: start + 2, end: start + 2 });
                }

                return;
            }

            const bindings = {
                b: 'bold',
                i: 'italic',
                e: 'code',
                k: 'link',
            };

            const action = bindings[event.key.toLowerCase()];

            if (action) {
                event.preventDefault();
                this.applyFormat(action);
            }
        },

        setMode(mode) {
            this.mode = mode;
            this.$nextTick(() => this.renderPreview());
        },

        /**
         * Recompute word count and reading time.
         */
        syncStats() {
            const trimmed = this.text.trim();
            const words = trimmed === '' ? 0 : trimmed.split(/\s+/).length;

            this.stats = {
                words,
                characters: this.text.length,
                minutes: Math.max(1, Math.round(words / 220)),
            };
        },

        /**
         * Re-render the preview by asking the server-side renderer.
         *
         * Local Markdown rendering would be a second implementation of
         * {@see \App\Support\MarkdownRenderer} and would drift from it — the
         * preview would stop matching the published post. Requesting the
         * rendered HTML keeps the preview honest, and the debounce means it
         * costs one request per pause in typing, not one per keystroke.
         *
         * This goes through the shared client rather than a hand-rolled
         * `fetch`, so CSRF handling and the error contract live in one place.
         */
        async renderPreview() {
            const target = this.$refs.preview;

            if (! target) {
                return;
            }

            try {
                const payload = await window.EditorAi.preview(this.text);

                target.innerHTML = payload.html ?? '';
            } catch {
                // A failed preview is cosmetic. Show the raw source rather than
                // an error, so the pane is never blank or alarming.
                target.innerHTML = this.text
                    ? `<pre class="whitespace-pre-wrap font-mono text-xs text-ink-500">${this.escape(this.text)}</pre>`
                    : '';
            }
        },

        /**
         * Escape text for safe insertion as HTML.
         */
        escape(value) {
            const div = document.createElement('div');
            div.textContent = value;

            return div.innerHTML;
        },

        /**
         * Push the current value back into Livewire, debounced.
         *
         * Debounced more generously than the stat update: syncing on every
         * pause is fine, but syncing on every pause *plus* a server round trip
         * per sentence makes the editor feel heavy.
         */
        queueSync() {
            window.clearTimeout(this.syncTimer);

            this.syncTimer = window.setTimeout(() => {
                this.wire?.set(this.model, this.text);
            }, 900);
        },

        /* --------------------------------------------------- corrections */

        setStatus(kind, message) {
            this.status = { kind, message };

            if (kind !== 'error') {
                window.setTimeout(() => {
                    if (this.status.message === message) {
                        this.status = { kind: 'info', message: '' };
                    }
                }, 4000);
            }
        },

        /**
         * Ask the AI to review the text.
         */
        async reviewText() {
            if (this.busy.correct) {
                return;
            }

            this.busy.correct = true;
            this.reviewing = true;
            this.corrections = [];
            this.status = { kind: 'info', message: '' };

            try {
                const payload = await window.EditorAi.correct(this.text, []);

                this.corrections = payload.corrections ?? [];

                if (this.corrections.length === 0) {
                    this.setStatus('info', 'No corrections suggested — this reads well.');
                }
            } catch (error) {
                this.setStatus('error', error.message);
            } finally {
                this.busy.correct = false;
                this.reviewing = false;
            }
        },

        /**
         * Apply one correction by splicing its exact span.
         *
         * Offsets are re-validated here as well as server-side, because the
         * document may have changed since the review — the author can keep
         * typing while suggestions sit on screen. When the text at the span no
         * longer matches, the correction is discarded rather than applied
         * blindly. Silently corrupting a sentence is far worse than losing a
         * suggestion.
         */
        acceptCorrection(correction) {
            const current = this.text.slice(correction.start, correction.end);

            if (current !== correction.original) {
                this.setStatus('error', 'That suggestion no longer matches the text — the passage changed. Re-run the review.');

                return;
            }

            this.apply({
                text: this.text.slice(0, correction.start) + correction.replacement + this.text.slice(correction.end),
                start: correction.start + correction.replacement.length,
                end: correction.start + correction.replacement.length,
            });

            this.corrections = this.corrections.filter((c) => c.id !== correction.id);

            this.shiftOffsetsAfter(correction.start, correction.replacement.length - correction.original.length);
        },

        /**
         * Rebase the remaining suggestions after an edit changes the length of
         * the document before them.
         */
        shiftOffsetsAfter(from, delta) {
            if (delta === 0) {
                return;
            }

            this.corrections = this.corrections.map((correction) =>
                correction.start > from
                    ? { ...correction, start: correction.start + delta, end: correction.end + delta }
                    : correction,
            );
        },

        dismissCorrection(correction) {
            this.corrections = this.corrections.filter((c) => c.id !== correction.id);
        },

        /**
         * Apply every mechanical suggestion, highest offset first.
         *
         * Descending order matters: applying from the start would shift every
         * later offset and invalidate the rest of the batch.
         */
        acceptAllMechanical() {
            const mechanical = this.corrections.filter((c) => c.mechanical);

            if (mechanical.length === 0) {
                return;
            }

            const sorted = [...mechanical].sort((a, b) => b.start - a.start);

            let text = this.text;
            let applied = 0;

            for (const correction of sorted) {
                if (text.slice(correction.start, correction.end) !== correction.original) {
                    continue;
                }

                text = text.slice(0, correction.start) + correction.replacement + text.slice(correction.end);
                applied++;
            }

            const appliedIds = new Set(mechanical.map((c) => c.id));

            this.corrections = this.corrections.filter((c) => !appliedIds.has(c.id));

            this.apply({ text, start: text.length, end: text.length });
            this.setStatus('info', `${applied} mechanical ${applied === 1 ? 'fix' : 'fixes'} applied.`);
        },

        dismissAllCorrections() {
            this.corrections = [];
        },

        /**
         * Map a correction type to its badge class.
         *
         * The six colours are a legend, not a status scale — every one of them
         * is defined once in `resources/css/app.css` under the correction-type
         * legend, so the review panel cannot invent a tint of its own.
         */
        typeBadgeClass(type) {
            const map = {
                spelling: 'sui-type-spelling',
                grammar: 'sui-type-grammar',
                punctuation: 'sui-type-punctuation',
                style: 'sui-type-style',
                clarity: 'sui-type-clarity',
                tone: 'sui-type-tone',
            };

            return map[type] ?? 'sui-chip-neutral';
        },

        typeLabel(type) {
            const found = this.capabilities.correction_types.find((t) => t.value === type);

            return found?.label ?? type;
        },

        /* --------------------------------------------------- generation */

        get selectedCategory() {
            return this.capabilities.categories.find((c) => c.value === this.form.category) ?? null;
        },

        get canGenerate() {
            if (! this.selectedCategory) {
                return false;
            }

            // A rewrite needs something to rewrite; everything else needs a
            // subject. Mirrors the server-side rule so the button is disabled
            // rather than failing a round trip.
            return this.selectedCategory.requires_source
                ? this.form.source_text.trim().length > 0
                : this.form.subject.trim().length > 0;
        },

        selectCategory(category) {
            this.form.category = category.value;
            this.form.tone = category.tone;
            this.form.word_count = category.word_count;
        },

        openGenerateDialog() {
            this.dialogs.generate = true;
            this.status = { kind: 'info', message: '' };
        },

        /**
         * Generate a draft.
         *
         * The returned body is inserted at the cursor when the document is
         * empty or the author asked for a rewrite; otherwise it is appended, so
         * an existing draft is never silently replaced.
         */
        async generate() {
            if (this.generating || ! this.canGenerate) {
                return;
            }

            this.generating = true;

            try {
                const payload = await window.EditorAi.generate({
                    category: this.form.category,
                    tone: this.form.tone,
                    subject: this.form.subject,
                    word_count: this.form.word_count,
                    source_text: this.form.source_text,
                    audience: this.form.audience,
                    keywords: this.form.keywords,
                });

                const draft = payload.draft ?? {};
                const body = (draft.body ?? '').trim();

                if (body === '') {
                    this.setStatus('error', 'The model returned nothing usable. Try rephrasing the subject.');

                    return;
                }

                const isRewrite = this.selectedCategory?.requires_source;

                if (isRewrite || this.text.trim() === '') {
                    this.apply({ text: body, start: body.length, end: body.length });
                } else {
                    const joined = `${this.text.replace(/\s+$/, '')}\n\n${body}`;

                    this.apply({ text: joined, start: joined.length, end: joined.length });
                }

                this.dialogs.generate = false;
                this.setStatus('info', 'Draft added. Review it before publishing.');
            } catch (error) {
                this.setStatus('error', error.message);
            } finally {
                this.generating = false;
            }
        },

        /* -------------------------------------------------------- images */

        openImageDialog() {
            this.dialogs.image = true;
            this.image.result = null;
            this.status = { kind: 'info', message: '' };
        },

        /**
         * Refine the rough idea into an image prompt.
         *
         * Deliberately a separate step from generation: refining costs a text
         * call and generating costs an image call, which is far more expensive.
         * Letting the author see and correct the prompt first means a
         * misunderstanding is caught before the expensive half runs.
         */
        async prepareImagePrompt() {
            if (this.image.preparing) {
                return;
            }

            this.image.preparing = true;

            try {
                // Send the opening of the post as context so the image suits
                // the piece, not just the idea in isolation.
                const context = this.text.slice(0, 1200);

                const payload = await window.EditorAi.imagePrompt(this.image.idea, context);

                this.image.prompt = payload.prompt ?? '';
                this.image.alt = payload.alt_text ?? this.image.idea;
            } catch (error) {
                this.setStatus('error', error.message);
            } finally {
                this.image.preparing = false;
            }
        },

        async generateImage() {
            if (this.image.generating || ! this.image.prompt) {
                return;
            }

            this.image.generating = true;

            try {
                const payload = await window.EditorAi.image(this.image.prompt, this.image.alt);

                this.image.result = payload.image ?? null;
                this.image.markdown = payload.markdown ?? '';
            } catch (error) {
                this.setStatus('error', error.message);
            } finally {
                this.image.generating = false;
            }
        },

        /**
         * Insert the generated image into the document.
         */
        insertImage() {
            if (! this.image.result) {
                return;
            }

            const alt = this.image.alt || '';
            const snippet = `![${alt}](${this.image.result.url})`;

            const el = this.el();
            const start = el ? el.selectionStart : this.text.length;
            const end = el ? el.selectionEnd : this.text.length;

            const { text, start: next } = MarkdownOps.insertBlock(this.text, start, end, snippet);

            this.apply({ text, start: next, end: next });

            this.dialogs.image = false;
            this.image = { idea: '', prompt: '', alt: '', result: null, preparing: false, generating: false };
            this.setStatus('info', 'Image inserted.');
        },
    };
}

window.richTextEditor = richTextEditor;

export default richTextEditor;
