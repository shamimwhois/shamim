@props([
    'review',
])

@php
    /**
     * One published product review.
     *
     * Public-site only. The moderation console renders its own row rather than
     * reusing this component, because the two surfaces need different things:
     * a buyer needs the rating and the words, a moderator needs the status and
     * two buttons. More importantly, the public site and the dashboard resolve
     * the `ink-*` scale in opposite directions in dark mode — the storefront
     * needs explicit `dark:` utilities, the dashboard gets them remapped by
     * `[data-sui]` — so a single class list cannot be right in both places.
     */
    $author = $review->author;
@endphp

<article {{ $attributes->merge(['class' => 'border-t border-ink-200 py-5 first:border-t-0 first:pt-0 dark:border-ink-800']) }}>
    <div class="flex min-w-0 items-center gap-3">
        <span class="grid size-9 shrink-0 place-items-center rounded-full border border-ink-200 bg-ink-50 text-xs font-semibold text-ink-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-300">
            {{ Str::of($author?->name ?? __('Reader'))->substr(0, 1)->upper() }}
        </span>

        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-ink-900 dark:text-ink-50">
                {{ $author?->name ?? __('Former member') }}
            </p>

            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="flex items-center gap-0.5" role="img" aria-label="{{ __(':rating out of 5', ['rating' => $review->rating]) }}">
                    @for ($star = 1; $star <= 5; $star++)
                        <flux:icon
                            name="star"
                            :variant="$star <= $review->rating ? 'solid' : 'outline'"
                            class="size-3 {{ $star <= $review->rating ? 'text-signal-500' : 'text-ink-300 dark:text-ink-700' }}"
                        />
                    @endfor
                </span>

                <span class="text-2xs text-ink-400 dark:text-ink-500">
                    {{ $review->created_at?->format('j M Y') }}
                </span>
            </div>
        </div>
    </div>

    @if ($review->title)
        <h4 class="mt-4 text-sm font-semibold text-ink-900 dark:text-ink-50">
            {{ $review->title }}
        </h4>
    @endif

    <p class="mt-2 text-sm leading-relaxed text-ink-600 dark:text-ink-300">
        {{ $review->body }}
    </p>
</article>
