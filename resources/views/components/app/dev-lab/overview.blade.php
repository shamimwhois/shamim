@props([
    'data',
])

@php
    /**
     * Developer Lab overview — the panel that names the module.
     *
     * Says three things in one glance: which module this is, what state it is in,
     * and what it is for. The chip is the loudest element after the title because
     * it is the one a reader has to get right before reading anything else: a lab
     * that runs code in the browser and a lab that is still a plan are different
     * promises, and a neutral chip between them costs a reader their time.
     *
     * @var array{title: string, description: string, icon: string, status: string, statusTone?: string} $data
     */
    $chip = match ($data['statusTone'] ?? 'warning') {
        'success' => 'sui-chip-success',
        'neutral' => 'sui-chip-neutral',
        default => 'sui-chip-warning',
    };
@endphp

<x-app.ui.section :padded="false">
    <div class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start">
        <div class="flex size-12 shrink-0 items-center justify-center rounded-md border border-sui-accent/20 bg-sui-accent-soft text-sui-accent-ink">
            <flux:icon :name="$data['icon']" class="size-6" />
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold tracking-tight text-ink-900">
                    {{ $data['title'] }}
                </h2>
                <span class="{{ $chip }}">
                    {{ $data['status'] }}
                </span>
            </div>

            <p class="mt-2 text-sm leading-normal text-ink-500">
                {{ $data['description'] }}
            </p>
        </div>
    </div>
</x-app.ui.section>
