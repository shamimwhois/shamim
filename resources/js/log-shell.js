/**
 * The log's client behaviour.
 *
 * Three Alpine components, registered once for every surface that wears the log
 * shell:
 *
 * - `logShell` — the frame: the search palette, `j`/`k` row focus, `c` for the
 *   composer, `?` for the shortcut sheet, and the `g`-then-a-letter navigation
 *   grammar. Row actions that need the server (`s`, `r`, `o`) are dispatched as
 *   `log:*` window events rather than called here, so the frame never needs to
 *   know whether the page behind it has a star to give.
 * - `commitComposer` — the inline composer's counter, staged node, preview and
 *   per-account draft stash in `localStorage`.
 * - `userMenu` — the account dropdown. It owns no markup of its own beyond the
 *   panel: the rail's chip and the phone's header avatar are both plain links
 *   that dispatch `open-user-menu`, so the menu still works as a link to the
 *   profile with JavaScript off.
 *
 * Both are progressive enhancement: with JavaScript off, the palette is a link
 * to search, the composer is a plain form, and every row action is a button.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('logShell', (options = {}) => ({
        focused: -1,
        pending: '',
        pendingTimer: null,
        searchOpen: false,
        search: '',
        results: [],
        searching: false,
        activeResult: 0,

        init() {
            this.searchOpen = Boolean(options.searchOpen);
            this.restoreNav();
        },

        /**
         * The rail's collapse is a preference, not a page state: a reader who
         * wants the icons picks them once. `init()` runs on every `wire:navigate`
         * swap too, because the shell is re-rendered with the page — so the
         * stored value is reapplied rather than the markup's `expanded` default,
         * or the rail would spring back open on every link.
         */
        restoreNav() {
            const stored = window.localStorage.getItem('dn.nav');

            if (stored === 'collapsed' || stored === 'expanded') {
                this.$el.dataset.nav = stored;
            }
        },

        toggleNav() {
            const next = this.$el.dataset.nav === 'expanded' ? 'collapsed' : 'expanded';
            this.$el.dataset.nav = next;
            window.localStorage.setItem('dn.nav', next);
        },

        rows() {
            return Array.from(this.$el.querySelectorAll('[data-commit]'));
        },

        focusRow(index) {
            const rows = this.rows();

            if (rows.length === 0) {
                return;
            }

            this.focused = Math.max(0, Math.min(index, rows.length - 1));
            rows.forEach((row, i) => row.classList.toggle('is-focused', i === this.focused));
            rows[this.focused].scrollIntoView({ block: 'nearest' });
        },

        focusedId() {
            return this.rows()[this.focused]?.dataset.commit ?? null;
        },

        emit(name) {
            const id = this.focusedId();

            if (id) {
                window.dispatchEvent(new CustomEvent(name, { detail: { id: Number(id) } }));
            }
        },

        openSearch() {
            this.searchOpen = true;
            this.$nextTick(() => this.$refs.paletteInput?.focus());
        },

        /** The palette finds people; the page finds everything else. */
        get searchEverythingUrl() {
            const query = this.search.trim();

            return `${options.everythingUrl ?? '/search'}${query === '' ? '' : `?q=${encodeURIComponent(query)}`}`;
        },

        closeSearch() {
            this.searchOpen = false;
            this.search = '';
            this.results = [];
        },

        async runSearch() {
            const query = this.search.trim();
            this.searching = true;

            try {
                const response = await fetch(
                    `${options.searchUrl}?q=${encodeURIComponent(query)}`,
                    { headers: { Accept: 'application/json' } },
                );

                this.results = response.ok ? await response.json() : [];
            } catch {
                this.results = [];
            } finally {
                this.searching = false;
                this.activeResult = 0;
            }
        },

        moveResult(step) {
            if (this.results.length === 0) {
                return;
            }

            this.activeResult = (this.activeResult + step + this.results.length) % this.results.length;
        },

        openResult(index = this.activeResult) {
            const result = this.results[index];

            if (result) {
                window.location.assign(`${options.profileUrlBase}/${result.handle}`);
            }
        },

        onKey(event) {
            const target = event.target;
            const typing = target instanceof HTMLElement
                && (target.tagName === 'INPUT'
                    || target.tagName === 'TEXTAREA'
                    || target.isContentEditable);

            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.searchOpen ? this.closeSearch() : this.openSearch();

                return;
            }

            if (this.searchOpen) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    this.closeSearch();
                } else if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.moveResult(1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    this.moveResult(-1);
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    this.openResult();
                }

                return;
            }

            if (typing) {
                return;
            }

            if (this.pending === 'g') {
                const to = options.destinations?.[event.key.toLowerCase()];
                this.pending = '';

                if (to) {
                    event.preventDefault();
                    window.location.assign(to);
                }

                return;
            }

            switch (event.key) {
                case 'j':
                    event.preventDefault();
                    this.focusRow(this.focused + 1);
                    break;
                case 'k':
                    event.preventDefault();
                    this.focusRow(this.focused - 1);
                    break;
                case 'c':
                    event.preventDefault();
                    this.$el.querySelector('[data-composer-input]')?.focus();
                    break;
                case 'g':
                    event.preventDefault();
                    this.pending = 'g';
                    clearTimeout(this.pendingTimer);
                    this.pendingTimer = setTimeout(() => (this.pending = ''), 1200);
                    break;
                case '?':
                    event.preventDefault();
                    window.dispatchEvent(new CustomEvent('open-shortcuts'));
                    break;
                case 's':
                    event.preventDefault();
                    this.emit('log:star');
                    break;
                case 'r':
                    event.preventDefault();
                    this.emit('log:reply');
                    break;
                case 'o':
                    event.preventDefault();
                    this.emit('log:thread');
                    break;
            }
        },
    }));

    window.Alpine.data('commitComposer', (options) => ({
        max: options.max,
        lane: options.lane,
        restoredLabel: options.restoredLabel,
        draftText: '',
        count: 0,
        remaining: options.max,
        filled: false,
        previewing: false,
        restored: false,
        link: '',
        key: `dn.stash.${options.userId}`,

        init() {
            const stashed = window.localStorage.getItem(this.key) ?? '';

            if (stashed !== '') {
                this.$refs.input.value = stashed;
                this.measure(stashed);
                this.restored = true;
            }
        },

        measure(value) {
            this.draftText = value;
            this.count = value.length;
            this.remaining = Math.max(0, this.max - value.length);
            this.filled = value.trim().length > 0;
            this.link = (value.match(/https?:\/\/[^\s]+/) ?? [''])[0];
        },

        onInput(event) {
            const value = event.target.value;

            this.measure(value);
            this.restored = false;

            if (value.trim() === '') {
                window.localStorage.removeItem(this.key);
            } else {
                window.localStorage.setItem(this.key, value);
            }
        },

        /*
         * Take what the clipboard is holding.
         *
         * A paste is the one attach path that costs the reader nothing: the
         * bytes never touch the filesystem picker. The panel's upload state is
         * a Livewire array property, so a clipboard image has to arrive the
         * same way a picked file does — `$wire.uploadMultiple` performs that
         * transfer and `updatedUploads` stages it exactly as if the picker had
         * been used.
         *
         * Non-image clipboard payloads (a string, plain text) are deliberately
         * dropped: the disk and the validator both accept images only, so
         * forwarding them would buy a round trip and an error message.
         */
        async paste() {
            if (! navigator.clipboard?.read) {
                this.openPicker();

                return;
            }

            let items = [];

            try {
                items = await navigator.clipboard.read();
            } catch {
                // Permission denied, or the document is not focused. Fall back
                // to the picker rather than doing nothing.
                this.openPicker();

                return;
            }

            const files = [];

            for (const item of items) {
                const type = item.types.find((candidate) => candidate.startsWith('image/'));

                if (type === undefined) {
                    continue;
                }

                const blob = await item.getType(type);
                const extension = (type.split('/')[1] ?? 'png').replace('jpeg', 'jpg');

                files.push(new File([blob], `pasted-${Date.now()}.${extension}`, { type }));
            }

            this.stage(files);
        },

        /*
         * A paste into the body — the path a screenshot actually takes.
         *
         * `clipboardData.files` covers the screenshot case in every browser
         * that matters; when it is empty the paste is text and belongs to the
         * textarea, so the event is left alone and the reader's words land
         * where they expect.
         */
        onPaste(event) {
            const files = Array.from(event.clipboardData?.files ?? [])
                .filter((file) => file.type.startsWith('image/'));

            if (files.length === 0) {
                return;
            }

            event.preventDefault();
            this.stage(files);
        },

        /*
         * Hand images to the component's upload property.
         *
         * One route for both clipboard paths, and the same route the picker
         * takes: `uploads` is an array property, so `uploadMultiple` is what
         * transfers it, and `updatedUploads` is the component hook that stages
         * what arrived. The explicit `call` is what the wire protocol does for
         * a plain `wire:model` change — without it the files would sit in the
         * property and never be validated or stored.
         *
         * The argument order is `(name, files, finish, error, progress)`. The
         * staging call used to sit in the *fifth* position — where `progress`
         * lives — so `updatedUploads` ran on every progress tick of the
         * transfer, dozens of requests where one was meant. It survived
         * review because the hook already nulled `uploads` by the time each
         * later call ran, so every extra call found nothing to stage and the
         * waste was invisible in behaviour; only the round trips were real,
         * and the error path had no callback at all. The component's own
         * clearing is not a licence to leave a callback in the wrong slot
         * (it now clears in a `finally`, on every path), so this stays the
         * correct position.
         *
         * A failed transfer reports through the component's own `uploads`
         * error rather than an event of its own, because that is the one
         * channel the panel already renders (`data-test="composer-media-error"`)
         * and a second way to say "that didn't attach" is a second thing to
         * keep in step.
         */
        stage(files) {
            if (files.length === 0) {
                return;
            }

            this.$wire.uploadMultiple(
                'uploads',
                files,
                () => this.$wire.call('updatedUploads'),
                () => this.$wire.call('reportAttachFailure'),
            );
        },

        /*
         * Open the picker the panel already owns.
         *
         * There is one real `<input type=file>` on the row; every attach button
         * is a label away from it. `accept` is narrowed per button so the
         * dialog opens already filtered, but the field itself stays
         * `wire:model`led to the component — the picker is the only place the
         * bytes enter, and the validator remains the thing that decides.
         */
        openPicker(accept = null) {
            const picker = this.$el.querySelector('[data-composer-picker]');

            if (picker === null) {
                return;
            }

            if (accept !== null) {
                picker.setAttribute('accept', accept);
            }

            picker.click();
        },

        togglePreview() {
            this.previewing = !this.previewing;
        },

        drop(clearInput = false) {
            window.localStorage.removeItem(this.key);
            this.restored = false;

            if (clearInput) {
                this.$refs.input.value = '';
                this.$refs.input.dispatchEvent(new Event('input', { bubbles: true }));
                this.measure('');
                this.$wire?.set?.('draft', '');
            }
        },

        clear() {
            this.drop(true);
            this.previewing = false;
        },
    }));

    /*
     * The account menu.
     *
     * Anchored, not nested: the panel is rendered at the end of the shell and
     * placed with `position: fixed`, because `.dn-nav` is `display: none` below
     * 1024px and an absolutely positioned child of a hidden subtree is never
     * painted — a phone would have a menu it could not open. So `place()`
     * measures whichever trigger asked for it: the rail's chip sits at the foot
     * of the screen and opens upward, the phone's header avatar sits at the top
     * and opens downward.
     */
    window.Alpine.data('userMenu', () => ({
        open: false,
        left: 0,
        edge: 0,
        up: true,
        trigger: null,

        /** Mirrors `.dn-user-menu { width }`; the clamp needs the real number. */
        width: 252,
        gap: 8,

        init() {
            this.$watch('open', (open) => {
                window.dispatchEvent(new CustomEvent('user-menu-state', { detail: { open } }));
            });
        },

        toggle(trigger) {
            if (this.open) {
                this.close();

                return;
            }

            this.trigger = trigger ?? null;
            this.place();
            this.open = true;

            this.$nextTick(() => {
                this.$refs.panel
                    ?.querySelector('[role="menuitem"]')
                    ?.focus({ preventScroll: true });
            });
        },

        close(restoreFocus = false) {
            const trigger = this.trigger;

            this.open = false;
            this.trigger = null;

            if (restoreFocus) {
                trigger?.focus?.({ preventScroll: true });
            }
        },

        /** Re-anchor after a resize or a scroll, without closing the menu. */
        reposition() {
            if (this.open) {
                this.place();
            }
        },

        place() {
            const trigger = this.trigger;
            const rect = trigger?.getBoundingClientRect?.() ?? null;
            if (rect === null || (rect.width === 0 && rect.height === 0)) {
                /* Nothing to hang from: pin to the corner the chip lives in. */
                this.left = this.gap;
                this.edge = this.gap;
                this.up = true;

                return;
            }

            /*
             * The phone's trigger is a 32px avatar floating inside a 56px
             * header, so hanging the panel off the avatar's own bottom would
             * drop it four pixels inside the header and cover the rule under it.
             * Clear the header instead.
             */
            const header = trigger.closest?.('.dn-mobile-header') ?? null;
            const floor = header === null
                ? rect.bottom
                : Math.max(rect.bottom, header.getBoundingClientRect().bottom);

            const furthest = Math.max(this.gap, window.innerWidth - this.width - this.gap);

            this.left = Math.round(Math.min(Math.max(this.gap, rect.left), furthest));
            this.up = rect.top > window.innerHeight * 0.55;
            this.edge = Math.round(
                this.up
                    ? window.innerHeight - rect.top + this.gap
                    : floor + this.gap,
            );
        },
    }));

    /*
     * The next page of the log.
     *
     * An observer, not a scroll handler: the sentinel only exists while there
     * is more to load, so this costs nothing once the log is exhausted and it
     * never has to ask how tall the page is. The button inside the sentinel
     * stays the real control — this only presses it early.
     */
    window.Alpine.data('feedMore', () => ({
        last: 0,

        init() {
            const observer = new IntersectionObserver(
                (entries) => {
                    if (!entries.some((entry) => entry.isIntersecting)) {
                        return;
                    }

                    /*
                     * Livewire re-renders the sentinel in place, so the same
                     * observer fires again on the next frame. The gap is what
                     * stops one flick of the wheel from pulling three pages in
                     * three round trips.
                     */
                    const now = Date.now();

                    if (now - this.last < 700) {
                        return;
                    }

                    this.last = now;
                    this.$wire.loadMore();
                },
                { rootMargin: '600px 0px' },
            );

            observer.observe(this.$el);
            this.$el._feedMore = observer;
        },

        destroy() {
            this.$el._feedMore?.disconnect();
        },
    }));
});
