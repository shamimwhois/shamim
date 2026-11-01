@props([
    'product',
])

@php
    /**
     * Marketplace product card — the Vibe96 skin.
     *
     * The catalogue's unit of discovery. One card answers the four questions a
     * buyer asks before clicking: what is it, what does it cost, is it any good,
     * and who made it. Everything else belongs on the product page.
     *
     * The anatomy is transcribed from vibe96.com's card: an uppercase
     * letter-spaced category eyebrow, a bold title that turns purple on hover,
     * a one-line description, and a price row closed by a hairline — the whole
     * card lifts four pixels with a purple-tinted shadow on hover.
     *
     * The whole card is the link. A second "view" link inside it would give the
     * same destination two tab stops, which is why the footer states the price
     * and an arrow rather than repeating the action.
     *
     * Ratings come from approved reviews only ({@see \App\Models\MarketplaceProduct}),
     * so a listing with no reviews says so instead of showing five grey stars
     * that read as a poor score.
     */
    $href = route('marketplace.show', ['product' => $product->slug]);
    $cover = $product->relationLoaded('media') ? $product->media->first() : null;
@endphp

<article {{ $attributes->merge(['class' => 'v96-card group']) }}>
    @if ($product->is_featured)
        <span class="v96-mono absolute right-3 top-3 z-10 rounded-full bg-(--v96-soft) px-2.5 py-1 text-2xs font-semibold uppercase tracking-[0.13em] text-(--v96-purple-soft)">
            {{ __('Featured') }}
        </span>
    @endif

    <a href="{{ $href }}" wire:navigate class="focus-ring flex flex-1 flex-col p-5">
        <div class="flex items-center gap-2">
            @if ($cover !== null)
                {{-- The same 8×8 tile the category icon occupies, so a catalogue of
                     listings with and without screenshots stays on one grid. The
                     signed link is minted per render; a card that lost its image is
                     a stale page, not a broken one. --}}
                <img
                    src="{{ $cover->url() }}"
                    alt=""
                    class="size-8 shrink-0 rounded-md border border-ink-200 object-cover dark:border-ink-800"
                    loading="lazy"
                    aria-hidden="true"
                />
            @else
                <span class="grid size-8 shrink-0 place-items-center rounded-md border border-ink-200 bg-ink-50 text-ink-500 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-400">
                    <flux:icon :name="$product->category?->icon ?? 'cube'" class="size-4" />
                </span>
            @endif

            <span class="v96-eyebrow min-w-0 truncate">
                {{ $product->category?->name ?? __('Uncategorised') }}
            </span>
        </div>

        <h3 class="v96-card-title mt-4 truncate">
            {{ $product->name }}
        </h3>

        <p class="v96-card-line mt-2 line-clamp-2">
            {{ $product->tagline }}
        </p>

        <div class="mt-auto pt-5">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <x-app.marketplace.rating :product="$product" />

                <span class="inline-flex items-center gap-1 text-2xs text-ink-400 dark:text-ink-500">
                    <flux:icon name="arrow-down-tray" class="size-3.5" />
                    <span class="tabular-nums">{{ $product->downloadLabel() }}</span>
                </span>
            </div>

            <div class="mt-4 flex items-center justify-between gap-3 border-t border-ink-200 pt-4 dark:border-ink-800">
                <span class="v96-price">
                    {{ $product->formattedPrice() }}
                </span>

                <span class="inline-flex items-center gap-1 text-xs font-semibold text-(--v96-body) transition-colors group-hover:text-(--v96-purple-lift)">
                    {{ __('Details') }}
                    <span aria-hidden="true" class="transition-transform duration-150 group-hover:translate-x-0.5">&rarr;</span>
                </span>
            </div>
        </div>
    </a>
</article>
