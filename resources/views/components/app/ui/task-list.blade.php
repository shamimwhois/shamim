@props([
    'title',
    'description' => null,
    'tasks' => [],
])

@php
    /**
     * The task list card.
     *
     * "What needs doing", derived from persisted state rather than typed in
     * by hand: the caller assembles the rows from its own data (comments
     * waiting, drafts mid-flight), and this component owns nothing but the
     * row. That split is what keeps the list honest — a task list that can
     * claim work that does not exist is worse than no task list.
     *
     * Each row carries a pastel square and one line of context. There is no
     * checkbox: every row is a link to the place the work happens, because
     * a checkbox that only the database can tick is a control that lies.
     *
     * ## Task shape
     *
     *   [
     *     'label'  => string,   what needs doing
     *     'meta'   => string,   the one-line context (count, age, category)
     *     'href'   => string,   where the work happens
     *     'icon'   => string,   a heroicon name
     *     'tone'   => string,   one of the six pastel tones, or 'attention'
     *   ]
     */
    $pastels = ['primary', 'secondary', 'info', 'success', 'warning', 'danger'];
@endphp

<section class="sui-card" aria-label="{{ $title }}">
    <div class="flex flex-wrap items-start justify-between gap-4 p-5 pb-3 sm:p-6 sm:pb-3">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-ink-900">{{ $title }}</h3>

            @if ($description)
                <p class="mt-1 text-xs leading-normal text-ink-500">{{ $description }}</p>
            @endif
        </div>

        @if ($actions ?? null)
            <div class="flex shrink-0 items-center gap-2">
                {{ $actions }}
            </div>
        @endif
    </div>

    @if ($tasks === [])
        <div class="sui-empty">
            <div class="mx-auto grid size-10 place-items-center rounded-md border border-emerald-200 bg-sui-success-soft text-sui-success">
                <flux:icon name="check" class="size-5" />
            </div>
            <p class="mt-3 text-sm font-medium text-ink-700">{{ __('Nothing waiting.') }}</p>
            <p class="mt-1 text-xs leading-normal text-ink-500">{{ __('Every task on this list is done.') }}</p>
        </div>
    @else
        <ul class="divide-y divide-sui-hairline border-t border-sui-hairline">
            @foreach ($tasks as $task)
                @php
                    $tone = in_array($task['tone'] ?? '', $pastels, true) ? $task['tone'] : 'primary';
                @endphp

                <li>
                    <a
                        href="{{ $task['href'] }}"
                        wire:navigate
                        class="sui-focus sui-row-lg flex items-center gap-3.5 transition duration-150 ease-soft hover:bg-sui-hover"
                    >
                        <span @class([
                            'grid size-10 shrink-0 place-items-center rounded-md shadow-soft-1',
                            'bg-sui-attention-wash text-sui-attention' => ($task['tone'] ?? null) === 'attention',
                            "bg-sui-pastel-{$tone}-wash text-sui-pastel-{$tone}-ink" => ($task['tone'] ?? null) !== 'attention',
                        ])>
                            <flux:icon :name="$task['icon']" class="size-5" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-ink-900">
                                {{ $task['label'] }}
                            </span>

                            @if (! empty($task['meta']))
                                <span class="mt-0.5 block truncate text-xs text-ink-500">
                                    {{ $task['meta'] }}
                                </span>
                            @endif
                        </span>

                        <flux:icon name="chevron-right" class="size-4 shrink-0 text-ink-400" />
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
