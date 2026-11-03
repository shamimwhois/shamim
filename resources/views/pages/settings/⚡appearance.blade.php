<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Appearance — the theme.
 *
 * The switch writes Flux's appearance store, which is the single owner of the
 * `.dark` class and of the persisted choice; this screen only renders the three
 * options and never touches the class itself. The design's segmented control is
 * used rather than a pair of buttons, because "system" is a third state and a
 * toggle cannot express three.
 */
new #[Title('Appearance settings')] #[Layout('layouts::feed')] class extends Component
{
    //
}; ?>

<x-log.settings tab="appearance">
    <section class="dn-settings-section" aria-labelledby="settings-appearance">
        <h2 id="settings-appearance" class="dn-settings-title">{{ __('Appearance') }}</h2>
        <p class="dn-settings-note">{{ __('Update the appearance settings for your account') }}</p>

        <div class="dn-segmented" x-data role="radiogroup" aria-label="{{ __('Theme') }}">
            <label class="dn-segment">
                <input type="radio" value="light" x-model="$flux.appearance" class="sr-only" data-test="appearance-light" />
                <x-feed.icon name="sun" :size="16" />
                {{ __('Light') }}
            </label>

            <label class="dn-segment">
                <input type="radio" value="dark" x-model="$flux.appearance" class="sr-only" data-test="appearance-dark" />
                <x-feed.icon name="moon" :size="16" />
                {{ __('Dark') }}
            </label>

            <label class="dn-segment">
                <input type="radio" value="system" x-model="$flux.appearance" class="sr-only" data-test="appearance-system" />
                <x-feed.icon name="monitor" :size="16" />
                {{ __('System') }}
            </label>
        </div>

        <p class="dn-settings-note" style="margin-top: 16px;">
            {{ __('Dark is the default. The choice is stored in this browser, not on the account, so each device keeps its own.') }}
        </p>
    </section>

    <section class="dn-settings-section" aria-labelledby="settings-appearance-colour">
        <h2 id="settings-appearance-colour" class="dn-settings-title">{{ __('Author lanes') }}</h2>
        <p class="dn-settings-note">
            {{ __('Six colours mark authors on the graph. Yours is derived from your handle, so it is the same on every surface.') }}
        </p>

        <div class="dn-lanes" aria-hidden="true">
            @foreach (range(0, 5) as $lane)
                <span class="dn-lane-swatch" style="--dn-lane: var(--dn-lane-{{ $lane }})"></span>
            @endforeach
        </div>
    </section>
</x-log.settings>
