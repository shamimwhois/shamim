@props([
    'title' => null,
    'description' => null,
    'icon' => null,
    'tone' => 'default',
    'padded' => true,
])

@php
    /**
     * A titled region within a page.
     *
     * The unit of visual grouping below the page header: a raised surface with
     * an optional header row (icon, title, description, right-hand actions) and
     * a body. Standardising it is what makes twenty different screens read as
     * one application.
     *
     * `tone="attention"` is a soft accent wash for a region that needs action —
     * used sparingly, because a surface that shouts everywhere shouts nowhere.
     *
     * Depth comes from the `sui-card` utility: white fill, 16px radius, a
     * hairline boundary and one level-1 soft shadow. Never a heavier border —
     * the hairline plus the shadow already clears the 3:1 non-text bar, and a
     * second boundary would make the page look like a form.
     */
    $toneClasses = match ($tone) {
        'attention' => 'border-sui-attention-border bg-sui-attention-wash/60 dark:border-sui-attention-border/25 dark:bg-sui-attention-wash/20',
        default => 'sui-card',
    };

    $hasHeader = $title !== null || isset($actions) || isset($icon);
@endphp

<section {{ $attributes->merge(['class' => 'overflow-hidden rounded-xl border '.$toneClasses]) }}>
    @if ($hasHeader)
        <header @class([
            'flex flex-wrap items-start justify-between gap-4 border-b px-5 py-4',
            'border-sui-attention-border/70 dark:border-sui-attention-border/20' => $tone === 'attention',
            'border-sui-hairline' => $tone !== 'attention',
        ])>
            <div class="flex min-w-0 items-start gap-3">
                @if ($icon)
                    <span @class([
                        'grid size-8 shrink-0 place-items-center rounded-md border',
                        'border-sui-attention-border bg-sui-attention-wash/60 text-sui-attention dark:border-sui-attention-border/40 dark:bg-sui-attention-wash/40 dark:text-sui-attention' => $tone === 'attention',
                        'border-sui-hairline bg-sui-well text-ink-400' => $tone !== 'attention',
                    ])>
                        <flux:icon :name="$icon" class="size-4" />
                    </span>
                @endif

                <div class="min-w-0">
                    @if ($title)
                        <h2 class="text-sm font-semibold tracking-tight text-ink-900">
                            {{ $title }}
                        </h2>
                    @endif

                    @if ($description)
                        <p class="mt-0.5 text-xs leading-normal text-ink-500">
                            {{ $description }}
                        </p>
                    @endif
                </div>
            </div>

            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </header>
    @endif

    <div @class(['p-5' => $padded, 'p-0' => ! $padded])>
        {{ $slot }}
    </div>
</section>
