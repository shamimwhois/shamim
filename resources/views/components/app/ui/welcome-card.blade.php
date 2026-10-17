@props([
    'eyebrow' => null,
    'title',
    'description' => null,
    'icon' => 'sparkles',
    'level' => 2,
])

@php
    /**
     * The gradient welcome card.
     *
     * The one surface in the system allowed to carry a gradient, and the
     * reason the gradient palette exists: white on the hero's darkest stop
     * (#141e30) measures 14.9:1, so nothing here has to compromise. The
     * secondary text sits at 70% white, which still measures well past AA
     * on both stops.
     *
     * The decoration is deliberately restrained — one soft circle in the
     * far corner, nothing animated, nothing that competes with the title.
     * A hero that shouts is a hero nobody reads.
     *
     * The actions slot carries the CT-style white button the caller
     * composes; the card states where they sit and how they align, not
     * what they look like.
     *
     * `level` exists because a page has exactly one `h1`: the card opens a
     * page, so the caller passes 1 there and leaves the default of 2 when
     * the greeting sits inside a page that already has its own heading.
     */
@endphp

<section class="sui-hero relative overflow-hidden p-6 sm:p-8">
    {{-- One soft light-pool in the corner. Decorative, and the only
         ornament the gradient gets. --}}
    <div
        aria-hidden="true"
        class="pointer-events-none absolute -right-16 -top-24 size-64 rounded-full bg-white/5 blur-2xl"
    ></div>

    <div class="relative flex flex-wrap items-start justify-between gap-6">
        <div class="min-w-0">
            @if ($eyebrow)
                <p class="text-2xs font-semibold uppercase tracking-wide text-white/70">
                    {{ $eyebrow }}
                </p>
            @endif

            <{{ 'h'.$level }} class="mt-2 text-2xl font-semibold tracking-tight text-white sm:text-3xl">
                {{ $title }}
            </{{ 'h'.$level }}>

            @if ($description)
                <p class="mt-2 max-w-xl text-sm leading-normal text-white/70">
                    {{ $description }}
                </p>
            @endif

            @if ($actions ?? null)
                <div class="mt-5 flex flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endif
        </div>

        <div class="sui-gradient-primary grid size-14 shrink-0 place-items-center rounded-xl text-white shadow-soft-1 ring-1 ring-white/20">
            <flux:icon :name="$icon" class="size-7" />
        </div>
    </div>
</section>
