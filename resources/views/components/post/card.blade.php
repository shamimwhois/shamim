@props([
    'post',
    'featured' => false,
    'headingLevel' => 'h3',
    'showExcerpt' => true,
])

@php
    /**
     * The blog post card — the single most repeated component on the site.
     *
     * Rendered in three contexts:
     *   - a grid on the blog index (default)
     *   - a wide "featured" slot on the landing page (`featured`)
     *   - a compact row in related-posts rails
     *
     * The whole card is one link target. Nested interactive elements (tags) are
     * rendered as separate links positioned above the card link via
     * `relative z-10`, so the tag remains independently clickable without
     * breaking the "card is a link" affordance.
     */
    $level = in_array($headingLevel, ['h2', 'h3', 'h4'], true) ? $headingLevel : 'h3';
    $category = $post->category;
@endphp

<article
    @class([
        'group relative flex flex-col overflow-hidden card-surface hover:shadow-raise hover:border-ink-300 dark:hover:border-ink-700',
        'sm:flex-row' => $featured,
    ])
    data-post-card
>
    {{-- Accent rail — draws in on hover; the only colour movement on the card. --}}
    <span
        aria-hidden="true"
        class="absolute inset-y-0 left-0 w-0.5 origin-top scale-y-0 bg-signal-500 transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:scale-y-100 group-focus-within:scale-y-100 dark:bg-signal-400"
    ></span>

    {{-- Category ribbon: monospace, uppercase, no background box. --}}
    @if ($category)
        <div class="flex items-center gap-2 px-5 pt-5 sm:px-6 sm:pt-6">
            <a
                href="{{ route('blog.index', ['category' => $category->slug]) }}"
                wire:navigate
                class="relative z-10 font-mono text-2xs font-medium uppercase tracking-widest text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400 dark:hover:text-signal-300"
            >
                {{ $category->name }}
            </a>

            @if ($post->is_featured)
                <span aria-hidden="true" class="text-ink-300 dark:text-ink-700">/</span>
                <span class="font-mono text-2xs font-medium uppercase tracking-widest text-amber-600 dark:text-amber-400">
                    Featured
                </span>
            @endif
        </div>
    @endif

    <div class="flex flex-1 flex-col p-5 sm:p-6">
        {{-- Title --}}
        <{{ $level }} @class([
            'font-semibold tracking-tight text-ink-900 dark:text-ink-50',
            'text-xl leading-snug' => ! $featured,
            'text-2xl leading-tight' => $featured,
        ])>
            <a
                href="{{ route('blog.show', $post) }}"
                wire:navigate
                class="after:absolute after:inset-0 after:content-[''] focus:outline-none"
            >
                {{ $post->title }}
            </a>
        </{{ $level }}>

        {{-- Excerpt --}}
        @if ($showExcerpt && filled($post->excerpt))
            <p @class([
                'mt-3 text-ink-500 dark:text-ink-400',
                'text-sm leading-relaxed line-clamp-3' => ! $featured,
                'text-base leading-relaxed line-clamp-3' => $featured,
            ])>
                {{ $post->excerpt }}
            </p>
        @endif

        <div class="flex-1"></div>

        {{-- Tags --}}
        @if ($post->relationLoaded('tags') && $post->tags->isNotEmpty())
            <ul class="mt-5 flex flex-wrap gap-1.5">
                @foreach ($post->tags->take(4) as $tag)
                    <li>
                        <a
                            href="{{ route('blog.index', ['tag' => $tag->slug]) }}"
                            wire:navigate
                            class="tag-pill relative z-10"
                        >
                            {{ $tag->name }}
                        </a>
                    </li>
                @endforeach

                @if ($post->tags->count() > 4)
                    <li class="tag-pill pointer-events-none">
                        +{{ $post->tags->count() - 4 }}
                    </li>
                @endif
            </ul>
        @endif

        {{-- Metadata rail --}}
        <div class="mt-5 flex items-center gap-3 border-t border-ink-100 pt-4 font-mono text-2xs text-ink-400 dark:border-ink-800 dark:text-ink-500">
            <time datetime="{{ $post->published_at?->toIso8601String() }}">
                {{ $post->published_at?->format('M j, Y') }}
            </time>
            <span aria-hidden="true">·</span>
            <span>{{ $post->readingTimeLabel() }}</span>

            @if (($post->view_count ?? 0) > 0)
                <span aria-hidden="true">·</span>
                <span>{{ number_format($post->view_count) }} views</span>
            @endif

            @if ($post->relationLoaded('comments'))
                <span aria-hidden="true">·</span>
                <span>{{ $post->comments->count() }} {{ Str::plural('comment', $post->comments->count()) }}</span>
            @endif
        </div>
    </div>
</article>
