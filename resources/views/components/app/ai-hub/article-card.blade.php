@props([
    'article',
])

@php
    /**
     * Hub article card.
     *
     * The unit of discovery on every article shelf: the landing page's recent
     * band, the articles index, the topic page, and the "keep reading" rail all
     * draw from this one component, so a card means the same thing on each of
     * them.
     *
     * A card answers what a reader weighs before clicking: what it is, how long
     * it takes, how deep it goes, and where it sits. The whole card is the link —
     * a second "read" anchor to the same destination would just be a second tab
     * stop — so the footer carries the read time and an arrow instead.
     *
     * The excerpt is `AiArticle::excerpt()`, which falls back to the opening of
     * the plain-text body. A hand-written summary is nicer, but an empty teaser
     * is worse than a real one, and that is the common failure of a nullable
     * summary column.
     */
    $topic = $article->primaryTopic();
@endphp

<article {{ $attributes->merge(['class' => 'group relative flex h-full flex-col overflow-hidden rounded-xl border border-ink-200 bg-white transition duration-200 ease-soft hover:border-signal-400 hover:shadow-soft-2 dark:border-ink-800 dark:bg-ink-950 dark:hover:border-signal-600']) }}>
    @if ($article->is_featured)
        <span class="absolute right-3 top-3 z-10 rounded-full border border-signal-300 bg-signal-50 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-widest text-signal-700 dark:border-signal-800 dark:bg-signal-950 dark:text-signal-300">
            {{ __('Featured') }}
        </span>
    @endif

    <a href="{{ route('ai-hub.article', ['article' => $article->slug]) }}" wire:navigate class="focus-ring flex flex-1 flex-col p-5">
        <div class="flex max-w-[85%] items-center gap-2">
            <span class="grid size-8 shrink-0 place-items-center rounded-md border border-ink-200 bg-ink-50 text-ink-500 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-400">
                <flux:icon :name="$article->format->icon()" class="size-4" />
            </span>

            <span class="min-w-0 truncate font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                {{ $article->format->label() }}
            </span>
        </div>

        <h3 class="mt-4 text-base font-semibold leading-snug tracking-tight text-ink-900 dark:text-ink-50">
            {{ $article->title }}
        </h3>

        <p class="mt-2 line-clamp-2 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
            {{ $article->excerpt((int) config('ai_hub.article.excerpt_length')) }}
        </p>

        <div class="mt-auto pt-5">
            <div class="flex flex-wrap items-center gap-2">
                <x-app.ai-hub.pill>{{ $article->difficulty->label() }}</x-app.ai-hub.pill>

                @if ($topic)
                    <x-app.ai-hub.pill :icon="$topic->icon ?? 'tag'">{{ $topic->name }}</x-app.ai-hub.pill>
                @endif
            </div>

            <div class="mt-4 flex items-center justify-between gap-3 border-t border-ink-200 pt-4 dark:border-ink-800">
                <span class="inline-flex min-w-0 items-center gap-1.5 text-2xs text-ink-400 dark:text-ink-500">
                    <flux:icon name="clock" class="size-3.5 shrink-0" />
                    <span class="tabular-nums">{{ $article->readingTimeLabel() }}</span>
                    @if ($article->published_at)
                        <span aria-hidden="true" class="text-ink-300 dark:text-ink-700">&middot;</span>
                        <span class="truncate">{{ $article->published_at->translatedFormat('M j, Y') }}</span>
                    @endif
                </span>

                <span class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-ink-500 transition-colors group-hover:text-signal-700 dark:text-ink-400 dark:group-hover:text-signal-400">
                    {{ __('Read') }}
                    <span aria-hidden="true" class="transition-transform duration-150 group-hover:translate-x-0.5">&rarr;</span>
                </span>
            </div>
        </div>
    </a>
</article>
