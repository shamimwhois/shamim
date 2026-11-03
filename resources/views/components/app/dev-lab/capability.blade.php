@props([
    'label',
    'icon',
    'items' => [],
    'done' => true,
])

@php
    /**
     * One capability group inside the Developer Lab page.
     *
     * A named cluster of features — "Write", say, with the three things it covers
     * underneath it. The marker is the whole point of the component: a tick means
     * the item is true today and a dash means it is a statement about the roadmap.
     * A page of ticks that includes a feature nobody can find is worse than a page
     * that admits what is missing, so the two are never mixed inside one group —
     * `done` is per cluster, and a cluster is honest about itself.
     */
    $marker = $done
        ? ['icon' => 'check', 'class' => 'text-sui-accent-ink']
        : ['icon' => 'minus', 'class' => 'text-ink-400'];
@endphp

<div>
    <div class="flex items-center gap-2">
        <span class="grid size-6 shrink-0 place-items-center rounded-md border border-sui-hairline bg-sui-well text-ink-400">
            <flux:icon :name="$icon" class="size-3.5" />
        </span>
        <h3 class="text-sm font-semibold tracking-tight text-ink-900">
            {{ $label }}
        </h3>
        @if (! $done)
            <span class="sui-chip-neutral">{{ __('Not yet') }}</span>
        @endif
    </div>

    <ul class="mt-2.5 space-y-2 pl-8">
        @foreach ($items as $item)
            <li class="flex items-start gap-2.5 text-sm leading-normal text-ink-600">
                <span aria-hidden="true" class="mt-0.5 shrink-0 {{ $marker['class'] }}">
                    <flux:icon :name="$marker['icon']" class="size-4" />
                </span>
                <span>{{ $item }}</span>
            </li>
        @endforeach
    </ul>
</div>
