<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun" data-test="appearance-light">{{ __('Light') }}</flux:radio>
            <flux:radio value="dark" icon="moon" data-test="appearance-dark">{{ __('Dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop" data-test="appearance-system">{{ __('System') }}</flux:radio>
        </flux:radio.group>
    </x-pages::settings.layout>
</section>
