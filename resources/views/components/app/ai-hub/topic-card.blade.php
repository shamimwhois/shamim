@props([
    'topic',
])

@php
    /**
     * Hub topic card.
     *
     * A topic is a doorway rather than a document, so the card sells the shelf
     * it opens: what the subject is, how much is filed under it, and which
     * sub-topics a reader can drill into without leaving the index.
     *
     * The article figure is read from `articles_count`, which every caller must
     * load. It is deliberately not counted here — a card that ran its own query
     * would be an N+1 on a grid, and the count would come from the un-scoped
     * relation, so a topic could advertise drafts. `AiHubContent::topicTree()`
     * counts through the `published()` scope for exactly that reason.
     */
    $children = $topic->children->where('is_active', true);
@endphp

<a
    href="{{ route('ai-hub.topic', ['topic' => $topic->slug]) }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'focus-ring group flex h-full flex-col rounded-xl border border-ink-200 bg-white p-5 transition duration-200 ease-soft hover:border-signal-400 hover:shadow-soft-2 dark:border-ink-800 dark:bg-ink-950 dark:hover:border-signal-600']) }}
>
    <div class="flex items-start gap-3">
        <span class="grid size-10 shrink-0 place-items-center rounded-md border border-ink-200 bg-ink-50 text-ink-500 transition-colors group-hover:border-signal-300 group-hover:text-signal-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-400 dark:group-hover:text-signal-400">
            <flux:icon :name="$topic->icon ?? 'tag'" class="size-5" />
        </span>

        <div class="min-w-0">
            <h3 class="text-base font-semibold leading-snug tracking-tight text-ink-900 dark:text-ink-50">
                {{ $topic->name }}
            </h3>

            <p class="mt-1 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                {{ trans_choice(':count article|:count articles', $topic->articles_count) }}
            </p>
        </div>
    </div>

    @if (filled($topic->description))
        <p class="mt-4 line-clamp-3 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
            {{ $topic->description }}
        </p>
    @endif

    @if ($children->isNotEmpty())
        <ul class="mt-4 flex flex-wrap gap-1.5" role="list">
            @foreach ($children->take(3) as $child)
                <li>
                    <x-app.ai-hub.pill>{{ $child->name }}</x-app.ai-hub.pill>
                </li>
            @endforeach

            @if ($children->count() > 3)
                <li>
                    <x-app.ai-hub.pill tone="neutral">+{{ $children->count() - 3 }}</x-app.ai-hub.pill>
                </li>
            @endif
        </ul>
    @endif

    <span class="mt-auto inline-flex items-center gap-1 pt-5 text-xs font-medium text-ink-500 transition-colors group-hover:text-signal-700 dark:text-ink-400 dark:group-hover:text-signal-400">
        {{ __('Browse topic') }}
        <span aria-hidden="true" class="transition-transform duration-150 group-hover:translate-x-0.5">&rarr;</span>
    </span>
</a>
