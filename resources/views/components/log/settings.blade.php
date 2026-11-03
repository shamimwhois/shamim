@props([
    'tab' => 'profile',
])

@php
    /**
     * The settings shell.
     *
     * Settings are the one place in the log with a second row of tabs rather
     * than a rail: the pages are forms, and a form does not hang on a graph. The
     * tab strip is the same control as the inbox's filters, so moving between
     * settings and the log does not feel like changing products.
     */
    $tabs = [
        ['key' => 'profile', 'title' => __('Profile'), 'href' => route('profile.edit')],
        ['key' => 'appearance', 'title' => __('Appearance'), 'href' => route('appearance.edit')],
        ['key' => 'terminal', 'title' => __('Terminal'), 'href' => route('settings.terminal')],
        ['key' => 'safety', 'title' => __('Safety'), 'href' => route('settings.safety')],
        ['key' => 'devices', 'title' => __('Devices'), 'href' => route('settings.devices')],
        ['key' => 'billing', 'title' => __('Billing'), 'href' => route('settings.billing')],
        ['key' => 'security', 'title' => __('Security'), 'href' => route('security.edit')],
    ];

    $handle = \App\Support\LogIdentity::handleFor(auth()->user());
@endphp

<x-log.frame active="settings" data-test="settings">
    <x-slot:header>
        <div class="dn-header">
            <div class="dn-page-head">
                <h1 class="dn-page-title">{{ __('Settings') }}</h1>
                <p class="dn-page-meta">{{ '@'.$handle }}</p>
            </div>

            <nav class="dn-filters" aria-label="{{ __('Settings') }}">
                @foreach ($tabs as $item)
                    <a
                        href="{{ $item['href'] }}"
                        wire:navigate
                        class="dn-filter {{ $tab === $item['key'] ? 'is-active' : '' }}"
                        @if ($tab === $item['key']) aria-current="page" @endif
                    >{{ $item['title'] }}</a>
                @endforeach
            </nav>
        </div>
    </x-slot:header>

    <div class="dn-settings">
        {{ $slot }}
    </div>
</x-log.frame>
