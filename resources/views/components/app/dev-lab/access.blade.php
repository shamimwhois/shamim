@props([
    'data',
])

@php
    /**
     * Developer Lab access — what the reader in front of the page can do.
     *
     * The one panel on this page whose copy is chosen at render time rather than
     * written down once: a guest, an account that may open the sandbox and an
     * account that may not are promised three different things, and the page says so
     * from the same component. The section itself decides nothing — it renders
     * whatever the page hands it, so the audience rule stays in one place.
     *
     * @var array{heading: string, description: string, icon: string, tone: string, action?: array{label: string, href: string, icon: string}|null, secondary?: array{label: string, href: string, icon: string}|null} $data
     */
@endphp

<x-app.ui.section
    :title="$data['heading']"
    :icon="$data['icon']"
    :tone="$data['tone']"
>
    <p class="text-sm leading-normal text-ink-600">
        {{ $data['description'] }}
    </p>

    @if ($data['action'] ?? null)
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <flux:button
                variant="primary"
                size="sm"
                :href="$data['action']['href']"
                :icon="$data['action']['icon']"
            >
                {{ $data['action']['label'] }}
            </flux:button>

            @if ($data['secondary'] ?? null)
                <flux:button
                    variant="ghost"
                    size="sm"
                    :href="$data['secondary']['href']"
                    :icon="$data['secondary']['icon']"
                >
                    {{ $data['secondary']['label'] }}
                </flux:button>
            @endif
        </div>
    @endif
</x-app.ui.section>
