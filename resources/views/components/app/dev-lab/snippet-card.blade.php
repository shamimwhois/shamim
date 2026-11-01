@props([
    'snippet',
    'href' => null,
    'compact' => false,
])

@php
    /**
     * Snippet card.
     *
     * One card for the two places the public sees a snippet: the gallery grid, where
     * the card is the whole list, and the landing's featured strip, where it is one row
     * among a short stack. `compact` is the difference in size, not a difference in
     * meaning — both answers to "what is this code, what is it written in, and who
     * kept it" have to be the same answer on both pages.
     *
     * The workspace and the admin console deliberately do not use this. Those rows are
     * management surfaces: they carry the visibility state, the view count and the
     * publish/unpublish controls, and reading them as cards would trade a list you can
     * act on for a grid you cannot. Like `x-app.ideas.card`, this component is a public
     * card, and only a public card.
     *
     * Nothing here is a lookup the model cannot answer. The language badge comes from
     * the enum, the line count from `lineCount()`, the teaser from `excerpt()`, so a
     * card cannot print a number the detail page contradicts.
     *
     * The ink tokens carry explicit `dark:` variants: the gallery and the landing both
     * render in the public shell, which has no `data-sui` scope, so a `sui-*` surface
     * token here would stay light no matter what the reader's theme is.
     */
    $href ??= route('dev-lab.snippets.show', ['snippet' => $snippet->slug]);
    $runnable = $snippet->language->isBrowserRunnable();
@endphp

@if ($compact)
    <article {{ $attributes->merge(['class' => 'rounded-lg border border-ink-200 bg-white p-4 transition duration-200 ease-soft hover:border-signal-400 dark:border-ink-800 dark:bg-ink-950']) }}>
        <div class="flex flex-wrap items-center gap-2">
            <a
                href="{{ $href }}"
                wire:navigate
                class="focus-ring rounded text-sm font-semibold tracking-tight text-ink-900 transition-colors hover:text-signal-700 dark:text-ink-50 dark:hover:text-signal-400"
            >
                {{ $snippet->title }}
            </a>

            <flux:badge :color="$snippet->language->badgeColor()" size="sm">
                {{ $snippet->language->label() }}
            </flux:badge>

            @unless ($runnable)
                <flux:badge color="gray" size="sm">{{ __('stored') }}</flux:badge>
            @endunless
        </div>

        <p class="mt-1.5 line-clamp-2 font-mono text-2xs leading-relaxed text-ink-500 dark:text-ink-400">
            {{ $snippet->excerpt(160) }}
        </p>

        <p class="mt-2 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
            {{ $snippet->user?->name ?? __('Someone') }}
        </p>
    </article>
@else
    <article {{ $attributes->merge(['class' => 'group relative flex h-full flex-col overflow-hidden rounded-xl border border-ink-200 bg-white transition duration-200 ease-soft hover:border-sui-accent/40 hover:shadow-soft-2 dark:border-ink-800 dark:bg-ink-950']) }}>
        <a
            href="{{ $href }}"
            wire:navigate
            class="focus-ring flex flex-1 flex-col p-5"
        >
            <h2 class="text-base font-semibold leading-snug tracking-tight text-ink-900 dark:text-ink-50">
                {{ $snippet->title }}
            </h2>

            <p class="mt-2 line-clamp-3 font-mono text-2xs leading-relaxed text-ink-500 dark:text-ink-400">
                {{ $snippet->excerpt() }}
            </p>

            <div class="mt-auto flex flex-wrap items-center gap-2 pt-5">
                <flux:badge :color="$snippet->language->badgeColor()" size="sm">
                    {{ $snippet->language->label() }}
                </flux:badge>

                <span class="text-2xs text-ink-500 dark:text-ink-400">
                    {{ trans_choice(':count line|:count lines', $snippet->lineCount(), ['count' => $snippet->lineCount()]) }}
                </span>

                @unless ($runnable)
                    <span class="inline-flex items-center gap-1.5 text-2xs text-ink-400 dark:text-ink-500">
                        <flux:icon name="lock-closed" class="size-3.5" />
                        {{ __('No run button') }}
                    </span>
                @endunless
            </div>
        </a>

        <div class="flex items-center gap-2 border-t border-ink-200 px-4 py-2 text-2xs text-ink-500 dark:border-ink-800 dark:text-ink-400">
            <span>{{ $snippet->user?->name ?? __('Someone') }}</span>
            <span aria-hidden="true">&middot;</span>
            <span>{{ $snippet->published_at?->translatedFormat('j M Y') }}</span>
        </div>
    </article>
@endif
