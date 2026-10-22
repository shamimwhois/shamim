@props([
    'title',
    'description',
    'icon' => 'sparkles',
    'features' => [],
    'spec' => null,
    'eyebrow' => null,
    'breadcrumbs' => null,
    'alreadyWorks' => null,
])

{{--
    Module placeholder.

    Every not-yet-built product module that needs only four sentences renders
    through this component, so the placeholder pages cannot drift into different
    designs. It states three things honestly: what the module is, what is
    already built underneath it, and that the module itself is not available
    yet. A module that has outgrown those four sentences — Developer Lab today —
    builds its own panels instead.

    The eyebrow, breadcrumb and "what already works" wording differ per surface
    — a public landing page has no guard to describe, an admin console has no
    workspace entry — so those three are overridable. Their defaults are the
    workspace case, which is what most of the pages are.
--}}
@php
    $eyebrow ??= __('Module');
    $breadcrumbs ??= [['label' => __('Workspaces')], ['label' => $title]];
    $alreadyWorks ??= __('The account roles, permissions and navigation for this module are already in place. The route and its guard resolve today — only the module itself is unbuilt.');
@endphp

<div>
    <x-app.ui.header
        :title="$title"
        :description="$description"
        :eyebrow="$eyebrow"
        :breadcrumbs="$breadcrumbs"
    />

    <x-app.ui.body max="prose">
        <x-app.ui.section :padded="false">
            <div class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-md border border-sui-accent/20 bg-sui-accent-soft text-sui-accent-ink">
                    <flux:icon :name="$icon" class="size-6" />
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold tracking-tight text-ink-900">
                            {{ $title }}
                        </h2>
                        <span class="sui-chip-warning">
                            {{ __('Not yet available') }}
                        </span>
                    </div>

                    <p class="mt-2 text-sm leading-normal text-ink-500">
                        {{ $description }}
                    </p>

                    @if ($features !== [])
                        <div class="mt-6">
                            <p class="sui-label">
                                {{ __('Planned') }}
                            </p>
                            <ul class="mt-3 space-y-2.5">
                                @foreach ($features as $feature)
                                    <li class="flex items-start gap-2.5 text-sm text-ink-600">
                                        <flux:icon name="check" class="mt-0.5 size-4 shrink-0 text-sui-success" />
                                        <span>{{ $feature }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </x-app.ui.section>

        <x-app.ui.section :title="__('What already works')" icon="check-circle" :padded="true">
            <p class="text-sm leading-normal text-ink-600">
                {{ $alreadyWorks }}
            </p>

            @if ($spec)
                <p class="mt-3 sui-help">
                    {{ __('Specification') }}:
                    <span class="text-ink-600">{{ $spec }}</span>
                </p>
            @endif
        </x-app.ui.section>
    </x-app.ui.body>
</div>
