@php
    /**
     * The theme toggle.
     *
     * The single control for the application's two themes — dark (the
     * default) and light. One component, three surfaces: the workspace
     * navbar, the public site header and the auth screens, which is why it
     * is a component and not markup copied three times.
     *
     * ## How it switches
     *
     * State belongs to Flux's appearance store, not to this button: the
     * click flips `$flux.dark`, and the store's own effect applies the
     * `.dark` class on <html> and persists the choice to
     * `localStorage['flux.appearance']` — the same key the head's
     * appearance scripts read on every page load. The button never writes
     * the class itself, so there is exactly one writer.
     *
     * `window.DevFolioTheme` (defined inline by `partials/theme-boot`) does
     * the two things that are not Flux's job: the short colour cross-fade
     * and the `theme-color` meta that colours the mobile browser chrome.
     *
     * ## The icons
     *
     * The button always shows the theme you would switch *to* — a sun while
     * dark is applied, a moon while light is — and its accessible name says
     * the same. `x-cloak` keeps the hidden icon from flashing before Alpine
     * initialises; the `[x-cloak]` rule lives in the base layer of
     * app.css.
     */
@endphp

<button
    type="button"
    x-data="{ dark: document.documentElement.classList.contains('dark') }"
    @click="
        dark = ! dark;
        $flux.dark = dark;
        window.DevFolioTheme.transition();
        window.DevFolioTheme.sync();
    "
    {{ $attributes->merge([
        'class' => 'sui-focus grid size-9 shrink-0 place-items-center rounded-md text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-ink',
    ]) }}
    ::title="dark ? '{{ __('Switch to light theme') }}' : '{{ __('Switch to dark theme') }}'"
    ::aria-label="dark ? '{{ __('Switch to light theme') }}' : '{{ __('Switch to dark theme') }}'"
    data-test="theme-toggle"
>
    <flux:icon name="sun" class="size-5" x-show="dark" aria-hidden="true" />
    <flux:icon name="moon" class="size-5" x-show="! dark" x-cloak aria-hidden="true" />
</button>
