@php
    /**
     * Settings shell.
     *
     * The settings sibling of the main dashboard nav: a labelled sidebar of
     * destinations beside the active screen. Above `md` the nav is a column;
     * below it, it becomes a horizontally scrollable tab strip, because a
     * two-column layout with one item per row wastes the whole first screen on
     * mobile.
     *
     * The destinations come from {@see \App\Support\SettingsNavigation}, the
     * same declaration the routes are checked against.
     */
    $items = \App\Support\SettingsNavigation::items();
@endphp

<div class="mx-auto w-full max-w-6xl px-5 py-8 sm:px-6 lg:px-10">
    <div class="flex flex-col gap-8 md:flex-row md:gap-10">
        {{-- Settings nav: tabs on mobile, a column on desktop. --}}
        <nav class="shrink-0 md:w-56" aria-label="{{ __('Settings') }}">
            <p class="hidden px-3 pb-2 font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 md:block dark:text-ink-500">
                {{ __('Settings') }}
            </p>

            <ul class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1 md:mx-0 md:flex-col md:overflow-visible md:px-0 md:pb-0">
                @foreach ($items as $item)
                    @php $current = request()->routeIs($item['pattern']); @endphp

                    <li class="shrink-0 md:shrink">
                        <a
                            href="{{ route($item['route']) }}"
                            wire:navigate
                            @if ($current) aria-current="page" @endif
                            @class([
                                'group flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors md:whitespace-normal',
                                'bg-ink-900 text-white dark:bg-ink-50 dark:text-ink-950' => $current,
                                'text-ink-600 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-100' => ! $current,
                            ])
                        >
                            <flux:icon :name="$item['icon']" class="size-4.5 shrink-0" />
                            <span>{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        {{-- Active screen. --}}
        <div class="min-w-0 flex-1">
            @if (! empty($heading))
                <div class="mb-6">
                    <h1 class="text-xl font-semibold tracking-tighter text-ink-900 dark:text-ink-50">
                        {{ $heading }}
                    </h1>

                    @if (! empty($subheading))
                        <p class="mt-1.5 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ $subheading }}
                        </p>
                    @endif
                </div>
            @endif

            <div class="w-full max-w-2xl">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
