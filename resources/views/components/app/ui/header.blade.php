@props([
    'title',
    'description' => null,
    'eyebrow' => null,
    'breadcrumbs' => [],
])

@php
    /**
     * Dashboard page header.
     *
     * Consistent across every dashboard surface: an optional breadcrumb trail, a
     * small-caps eyebrow, the title, a one-line description, and a right-hand
     * actions slot.
     *
     * Breadcrumbs are data rather than markup so every page states its position
     * the same way, and so the trail can only ever end at the current page —
     * the last entry is plain text, never a link to where you already are.
     *
     * @var array<int, array{label: string, href?: string}> $breadcrumbs
     */
@endphp

{{--
    Dashboard page header.

    Sits directly on the canvas rather than on its own white band — the
    cards are the raised surfaces, and a white header would compete with
    them for attention. Everything here is therefore type on the canvas,
    which is why the secondary colours are `ink-500` rather than `ink-400`:
    `#67748e` measures 4.46:1 on the raised canvas and `#5f6b7e` measures
    5.12:1. docs/SOFT-UI.md §3.
--}}
<header {{ $attributes->merge(['class' => 'border-b border-sui-hairline']) }}>
    <div class="mx-auto w-full max-w-7xl px-5 py-6 sm:px-6 lg:px-10 lg:py-8">
        @if ($breadcrumbs !== [])
            <nav aria-label="{{ __('Breadcrumb') }}" class="mb-4">
                <ol class="flex flex-wrap items-center gap-1.5 text-2xs text-ink-500">
                    <li>
                        <a
                            href="{{ auth()->check() ? route(auth()->user()->dashboardRoute()) : route('home') }}"
                            wire:navigate
                            class="transition duration-150 ease-soft hover:text-sui-accent-ink"
                        >
                            {{ __('Home') }}
                        </a>
                    </li>

                    @foreach ($breadcrumbs as $crumb)
                        <li aria-hidden="true" class="text-ink-300">/</li>
                        <li>
                            @if (isset($crumb['href']) && ! $loop->last)
                                <a
                                    href="{{ $crumb['href'] }}"
                                    wire:navigate
                                    class="transition duration-150 ease-soft hover:text-sui-accent-ink"
                                >
                                    {{ $crumb['label'] }}
                                </a>
                            @else
                                <span @class([
                                    'text-ink-600' => $loop->last,
                                ]) aria-current="page">{{ $crumb['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif

        <div class="flex flex-wrap items-start justify-between gap-6">
            <div class="min-w-0">
                @if ($eyebrow)
                    <p class="sui-label">
                        {{ $eyebrow }}
                    </p>
                @endif

                <h1 class="mt-2 text-2xl font-semibold tracking-tightest text-ink-900">
                    {{ $title }}
                </h1>

                @if ($description)
                    <p class="mt-2 max-w-2xl text-sm leading-normal text-ink-600">
                        {{ $description }}
                    </p>
                @endif
            </div>

            @if ($actions ?? null)
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endif
        </div>
    </div>
</header>
