@props([
    'data',
])

@php
    /**
     * Developer Lab status — the boundary between this module and a runner.
     *
     * The honesty panel of the page, and the reason it has one. A lab that executes
     * code server-side and a lab that executes it in the visitor's own tab make
     * different promises about CPU, about abuse, and about what a malicious snippet
     * can reach, so the page states which of the two this is rather than letting
     * "run your code in the browser" mean whichever the reader assumed.
     *
     * @var array{title?: string, icon?: string, tone?: string, description: string, spec: string, points?: list<string>} $data
     */
@endphp

<x-app.ui.section
    :title="$data['title'] ?? __('What is true today')"
    :icon="$data['icon'] ?? 'check-circle'"
    :tone="$data['tone'] ?? 'default'"
>
    <p class="text-sm leading-normal text-ink-600">
        {{ $data['description'] }}
    </p>

    @if (! empty($data['points']))
        <ul class="mt-3 space-y-2">
            @foreach ($data['points'] as $point)
                <li class="flex items-start gap-2.5 text-sm leading-normal text-ink-600">
                    <span aria-hidden="true" class="mt-0.5 shrink-0 text-ink-400">
                        <flux:icon name="minus" class="size-4" />
                    </span>
                    <span>{{ $point }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="sui-help mt-3">
        {{ __('Specification') }}:
        <span class="text-ink-600">{{ $data['spec'] }}</span>
    </p>
</x-app.ui.section>
