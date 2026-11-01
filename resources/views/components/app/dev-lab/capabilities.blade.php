@props([
    'data',
])

@php
    /**
     * Developer Lab capabilities — the feature list, split by whether it is true.
     *
     * One panel, several groups. The groups arrive as data rather than as markup so
     * a capability can be added, renamed or moved from "today" to "not yet" by
     * editing the list the page builds, without touching any component. Order here
     * is the order a reader works in: write, then run, then keep.
     *
     * A group carries its own `done` flag, which is what lets the same panel show
     * what the lab does and refuse to pretend about what it does not. The two are
     * separated because a list of ticks with one lie in it reads as a list of lies.
     *
     * @var array{groups: list<array{label: string, icon: string, items: list<string>, done?: bool}>} $data
     */
@endphp

<x-app.ui.section :title="__('What the lab does')" icon="sparkles">
    <div class="space-y-6">
        @foreach ($data['groups'] as $group)
            <x-app.dev-lab.capability
                :label="$group['label']"
                :icon="$group['icon']"
                :items="$group['items']"
                :done="$group['done'] ?? true"
            />
        @endforeach
    </div>
</x-app.ui.section>
