@props([
    'title',
    'description',
    'icon' => 'sparkles',
    'status' => 'planned',
    'features' => [],
    'spec' => null,
])

{{--
    Module placeholder.

    Every not-yet-built product module renders through this component, so the
    four placeholder pages cannot drift into four different designs. It states
    three things honestly: what the module is, what is already built underneath
    it, and that the module itself is not available yet.
--}}
<div class="mx-auto w-full max-w-3xl py-6">
    <x-dashboard.header :title="$title" :description="$description" :eyebrow="__('Module')" />

    <div class="mt-8 rounded-xl border border-ink-200 bg-white p-8 dark:border-ink-800 dark:bg-ink-900">
        <div class="flex items-start gap-5">
            <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-signal-50 text-signal-600 dark:bg-signal-950 dark:text-signal-400">
                <flux:icon :name="$icon" class="size-6" />
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="lg">{{ $title }}</flux:heading>
                    <flux:badge color="amber" size="sm">
                        {{ __('Not yet available') }}
                    </flux:badge>
                </div>

                <flux:text class="mt-2">{{ $description }}</flux:text>

                @if ($features !== [])
                    <div class="mt-6">
                        <p class="font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                            {{ __('Planned') }}
                        </p>
                        <ul class="mt-3 space-y-2">
                            @foreach ($features as $feature)
                                <li class="flex items-start gap-2.5 text-sm text-ink-600 dark:text-ink-300">
                                    <flux:icon name="check" class="mt-0.5 size-4 shrink-0 text-ink-400 dark:text-ink-500" />
                                    <span>{{ $feature }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-6 rounded-lg border border-ink-200 bg-ink-50 p-4 dark:border-ink-800 dark:bg-ink-950">
                    <p class="text-sm text-ink-600 dark:text-ink-300">
                        {{ __('The account roles, permissions and navigation for this module are already in place. The routes and guards resolve today — only the module itself is unbuilt.') }}
                    </p>
                    @if ($spec)
                        <p class="mt-2 text-sm text-ink-500 dark:text-ink-400">
                            {{ __('Specification') }}:
                            <span class="font-mono text-xs">{{ $spec }}</span>
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
