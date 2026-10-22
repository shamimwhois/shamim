@props([
    'product',
    'showCount' => true,
    'size' => 'sm',
])

@php
    /**
     * Product rating, derived from approved reviews.
     *
     * Deliberately silent when there are no reviews: five empty stars read as a
     * one-star score at a glance, and a storefront that implies a rating it has
     * not earned is the thing this component exists to avoid. The count is part
     * of the label, not decoration — "4.5" from two reviews is a different claim
     * from "4.5" from two hundred.
     */
    $rating = $product->averageRating();
    $count = $product->reviewCount();
    $filled = $rating === null ? 0 : (int) round($rating);
    $starSize = $size === 'lg' ? 'size-4' : 'size-3.5';
@endphp

@if ($rating === null)
    <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
        {{ __('No reviews yet') }}
    </span>
@else
    <span
        class="inline-flex items-center gap-1.5"
        role="img"
        aria-label="{{ trans_choice('Rated :rating out of 5 from :count review|Rated :rating out of 5 from :count reviews', $count, ['rating' => number_format($rating, 1), 'count' => $count]) }}"
    >
        <span class="flex items-center gap-0.5" aria-hidden="true">
            @for ($star = 1; $star <= 5; $star++)
                <flux:icon
                    name="star"
                    :variant="$star <= $filled ? 'solid' : 'outline'"
                    :class="$starSize.' '.($star <= $filled ? 'text-signal-500' : 'text-ink-300 dark:text-ink-700')"
                />
            @endfor
        </span>

        <span class="text-xs font-semibold tabular-nums text-ink-700 dark:text-ink-200">
            {{ number_format($rating, 1) }}
        </span>

        @if ($showCount)
            <span class="text-2xs tabular-nums text-ink-500 dark:text-ink-400">({{ $count }})</span>
        @endif
    </span>
@endif
