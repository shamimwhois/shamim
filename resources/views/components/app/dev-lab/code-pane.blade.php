@props([
    'label',
    'model',
    'rows' => 18,
    'placeholder' => null,
    'hint' => null,
    'max' => null,
    'id' => null,
])

@php
    $id ??= 'pane-'.str($model)->kebab();
    $max ??= (int) config('dev_lab.max_code_bytes');
@endphp

{{--
    One labelled code textarea, with a byte counter and a Tab key that indents
    instead of escaping.

    This is the honest version of "a code editor" for a build that took a
    dependency ban as one of its founding decisions. Real highlighting inside a
    textarea is not possible: the element renders its own text and cannot host a
    span. The overlay technique that fakes it is several hundred lines of
    position-matching you have to maintain forever, and it breaks the moment the
    font metrics and the scroll offset disagree. What actually helps somebody
    typing code in a browser is a monospace face, visible whitespace on Tab, a
    caret that stays put, and a number that tells them how close they are to the
    ceiling the server will refuse. Those four things cost nothing.

    The counter reads the element's own value rather than the Livewire property. A
    debounced model means the property lags the keystroke by design, and a byte
    count that arrived a second after you typed it is a byte count nobody trusts.

    `model` is interpolated into `wire:model`, which is the one place this
    component is not pure markup. It is a component-private string written by the
    screen that includes it, never a value read from a request.
--}}
<div
    x-data="{
        bytes: 0,
        max: {{ (int) $max }},
        measure() { this.bytes = this.$refs.pane.value.length },
        indent(event) {
            const pane = this.$refs.pane;
            const start = pane.selectionStart;
            const end = pane.selectionEnd;

            pane.setRangeText('  ', start, end, 'end');

            // setRangeText does not fire an input event, so Livewire would never
            // hear about the keystroke. The dispatch is what keeps the save honest.
            pane.dispatchEvent(new Event('input', { bubbles: true }));
            event.preventDefault();
        },
    }"
    x-init="measure()"
    class="min-w-0"
>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <label for="{{ $id }}" class="font-mono text-2xs uppercase tracking-widest text-ink-500">
            {{ $label }}
        </label>

        <p
            class="font-mono text-2xs tabular-nums"
            :class="bytes > max * 0.85 ? 'text-sui-danger' : 'text-ink-400'"
            aria-live="polite"
        >
            <span x-text="bytes.toLocaleString()"></span>
            /
            <span>{{ number_format((int) $max) }}</span>
            {{ __('bytes') }}
        </p>
    </div>

    <textarea
        id="{{ $id }}"
        x-ref="pane"
        rows="{{ $rows }}"
        wire:model.live.debounce.600ms="{{ $model }}"
        x-on:input="measure()"
        x-on:keydown.tab.prevent="indent($event)"
        placeholder="{{ $placeholder }}"
        spellcheck="false"
        autocapitalize="off"
        autocomplete="off"
        @if ($hint !== null) aria-describedby="{{ $id }}-hint" @endif
        class="sui-control mt-2 w-full resize-y rounded-xl bg-sui-raised px-3 py-2.5 font-mono text-xs leading-relaxed text-sui-ink"
    ></textarea>

    @if ($hint !== null)
        <p id="{{ $id }}-hint" class="mt-1.5 text-2xs leading-normal text-ink-400">
            {{ $hint }}
        </p>
    @endif
</div>
