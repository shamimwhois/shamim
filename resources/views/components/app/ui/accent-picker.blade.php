@php
    /**
     * The accent colour picker.
     *
     * The companion to the theme toggle: where that flips dark and light,
     * this re-tunes the `signal` family — the site's single accent hue — to
     * one of six hues, mirroring the reference site's "choose accent color"
     * control that sits next to its own theme toggle.
     *
     * ## How it switches
     *
     * One attribute, `data-accent` on <html>. The per-hue variable scopes
     * live at the end of app.css; `window.DevFolioTheme.accent` (defined
     * inline by `partials/theme-boot`) persists the choice to
     * `localStorage['devfolio.accent']` and stamps the attribute before the
     * first paint on every later page load. The default green-teal is the
     * *absence* of the attribute, so it is never stored — exactly how the
     * dark default avoids fighting the OS preference.
     *
     * ## The panel
     *
     * A single round swatch per hue in a small floating card, opened by a
     * palette button that matches the theme toggle's size and hover. The
     * active swatch carries a ring; every button announces itself with
     * `aria-pressed` so screen readers can hear which hue is applied.
     *
     * The header renders this component twice — desktop nav and mobile
     * panel — so every instance takes a unique panel `id` via `panelId`;
     * duplicating a DOM id here would break `aria-controls`.
     */
    $panelId = $panelId ?? 'accent-picker'.uniqid();
    $accents = [
        ['name' => 'emerald', 'swatch' => '#10b981', 'label' => 'Emerald (default)'],
        ['name' => 'indigo', 'swatch' => '#2b4acb', 'label' => 'Indigo'],
        ['name' => 'cyan', 'swatch' => '#06b6d4', 'label' => 'Cyan'],
        ['name' => 'amber', 'swatch' => '#f59e0b', 'label' => 'Amber'],
        ['name' => 'rose', 'swatch' => '#e11d48', 'label' => 'Rose'],
        ['name' => 'violet', 'swatch' => '#7c3aed', 'label' => 'Violet'],
    ];
@endphp

<div
    x-data="{ open: false, accent: document.documentElement.getAttribute('data-accent') || 'emerald' }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative shrink-0"
>
    <button
        type="button"
        @click="open = ! open"
        :aria-expanded="open"
        aria-haspopup="true"
        aria-controls="{{ $panelId }}"
        class="sui-focus grid size-9 shrink-0 place-items-center rounded-md text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-ink"
        title="{{ __('Choose accent color') }}"
        aria-label="{{ __('Choose accent color') }}"
        data-test="accent-picker-trigger"
    >
        <flux:icon name="paint-brush" class="size-5" aria-hidden="true" />
    </button>

    <div
        id="{{ $panelId }}"
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-1"
        class="absolute right-0 top-11 z-50 w-60 rounded-xl border border-ink-200 bg-white p-3 shadow-lift dark:border-ink-800 dark:bg-ink-900"
        role="group"
        aria-label="Accent color"
        data-test="accent-picker-panel"
    >
        <p class="px-0.5 pb-2 text-xs font-medium tracking-wide text-ink-500 uppercase dark:text-ink-400">
            {{ __('Accent color') }}
        </p>

        <div class="grid grid-cols-6 gap-2">
            @foreach ($accents as $accent)
                <button
                    type="button"
                    @click="accent = '{{ $accent['name'] }}'; window.DevFolioTheme.accent('{{ $accent['name'] }}')"
                    :aria-pressed="accent === '{{ $accent['name'] }}'"
                    title="{{ $accent['label'] }}"
                    aria-label="{{ __('Apply :label accent', ['label' => $accent['label']]) }}"
                    class="sui-focus grid size-7 place-items-center rounded-full transition duration-150 ease-soft"
                    data-accent-option="{{ $accent['name'] }}"
                    data-test="accent-option-{{ $accent['name'] }}"
                >
                    <span
                        class="block size-5 rounded-full border border-ink-900/10 transition dark:border-ink-50/20"
                        :class="accent === '{{ $accent['name'] }}' ? 'ring-2 ring-offset-2 ring-offset-white ring-ink-600 dark:ring-offset-ink-900 dark:ring-ink-300' : ''"
                        style="background-color: {{ $accent['swatch'] }};
                               {{ $accent['name'] === 'amber' ? 'border-color: rgb(0 0 0 / 0.2);' : '' }}"
                        aria-hidden="true"
                    ></span>
                </button>
            @endforeach
        </div>
    </div>
</div>
