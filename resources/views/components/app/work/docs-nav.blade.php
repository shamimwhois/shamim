@props([
    'project',
    'docs' => [],
    'current' => null,
])

@php
    /**
     * Documentation index for one project.
     *
     * Rendered twice on a document page: inside a disclosure on narrow screens
     * and as the sticky rail beside the article on wide ones. `current` is the
     * document slug, or null on the project overview, which is why "Overview" is
     * part of this list rather than a separate control.
     *
     * @var \App\Data\Project $project
     * @var array<int, array{slug: string, title: string, summary: string, reading_time: int, markdown: string, source: string}> $docs
     * @var string|null $current
     */
    $linkClass = 'group -ml-px flex items-start gap-3 border-l-2 py-2 pl-4 pr-2 transition-colors';
    $idleClass = 'border-transparent text-ink-500 hover:border-signal-400 hover:text-ink-900 dark:text-ink-400 dark:hover:text-ink-100';
    $activeClass = 'border-signal-500 text-signal-700 dark:border-signal-400 dark:text-signal-300';
@endphp

<nav {{ $attributes->merge(['class' => '']) }} aria-label="{{ __('Project documentation') }}">
    <p class="px-2 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
        {{ __('Documentation') }}
        <span class="tabular-nums normal-case tracking-normal">({{ count($docs) }})</span>
    </p>

    <ul class="mt-4 border-l border-ink-200 dark:border-ink-800">
        <li>
            <a
                href="{{ route('work.show', ['project' => $project->slug]) }}"
                wire:navigate
                @if ($current === null) aria-current="page" @endif
                @class([$linkClass, $current === null ? $activeClass : $idleClass])
            >
                <span class="font-mono text-2xs tabular-nums leading-5 text-ink-400 dark:text-ink-500">00</span>
                <span class="text-sm font-medium leading-5">{{ __('Overview') }}</span>
            </a>
        </li>

        @foreach ($docs as $doc)
            <li>
                <a
                    href="{{ route('work.show', ['project' => $project->slug, 'doc' => $doc['slug']]) }}"
                    @if ($current === $doc['slug']) aria-current="page" @endif
                    @class([$linkClass, $current === $doc['slug'] ? $activeClass : $idleClass])
                >
                    <span class="font-mono text-2xs tabular-nums leading-5 text-ink-400 dark:text-ink-500">
                        {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium leading-5">{{ $doc['title'] }}</span>
                        <span class="mt-0.5 block text-2xs text-ink-400 dark:text-ink-500">
                            {{ trans_choice(':count min read', $doc['reading_time'], ['count' => $doc['reading_time']]) }}
                        </span>
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
