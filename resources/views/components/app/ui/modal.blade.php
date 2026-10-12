@props([
    'name',
    'title',
    'description' => null,
    'icon' => null,
    'tone' => 'default',
    'maxWidth' => 'lg',
    'showOnError' => true,
])

@php
    /**
     * Modal shell.
     *
     * Every modal in the application renders through this component. The point
     * is not to wrap a Flux component for its own sake — it is that the
     * *decisions* a modal has to make should be made once:
     *
     *   - whether to reopen when validation failed (the `:show` binding)
     *   - what an accessible name is (`aria-labelledby` on the heading)
     *   - how a destructive dialog is signalled (the `tone` prop)
     *   - consistent width, padding and focus behaviour
     *
     * Before this existed, those four decisions were re-made — and variously
     * re-made *wrong* — across eight modal files. Copy-pasted markup does not
     * drift loudly; it drifts one modal at a time, and the odd one out is
     * invisible until someone is locked out of a dialog they cannot close.
     *
     * The three named slots map to the three shapes a modal actually takes:
     *   the default slot is the body, `actions` is the footer button row, and
     *   any content between them is just markup.
     *
     * `tone="danger"` is the deliberate signal for a destructive action. It
     * only changes the header's icon treatment; the button that performs the
     * action still has to declare `variant="danger"` itself, because the
     * destructive control is often not inside this component at all.
     */
    $tone = in_array($tone, ['default', 'danger'], true) ? $tone : 'default';
    $headingId = 'modal-'.$name.'-heading';

    $widths = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
    ];
@endphp

<flux:modal
    :name="$name"
    :show="$showOnError ? $errors->isNotEmpty() : false"
    focusable
    {{ $attributes->merge(['class' => $widths[$maxWidth] ?? $widths['lg']]) }}
>
    <div class="space-y-6">
        <div class="flex items-start gap-4">
            @if ($icon)
                <div @class([
                    'grid size-10 shrink-0 place-items-center rounded-lg border',
                    'border-red-200 bg-red-50 text-red-600 dark:border-red-500/40 dark:bg-red-950/40 dark:text-red-400' => $tone === 'danger',
                    'border-ink-200 bg-ink-50 text-ink-500 dark:border-ink-700 dark:bg-ink-800 dark:text-ink-300' => $tone !== 'danger',
                ])>
                    <flux:icon :name="$icon" class="size-5" />
                </div>
            @endif

            <div class="min-w-0 flex-1">
                <flux:heading :id="$headingId" size="lg">
                    {{ $title }}
                </flux:heading>

                @if ($description)
                    <flux:subheading class="mt-1.5">{{ $description }}</flux:subheading>
                @endif
            </div>
        </div>

        {{-- Body slot: form fields, explanatory copy, anything modal-specific. --}}
        {{ $slot }}
    </div>
</flux:modal>
