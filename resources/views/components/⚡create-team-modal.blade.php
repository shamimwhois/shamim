<?php

use App\Actions\Teams\CreateTeam;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public string $teamName = '';

    public function createTeam(CreateTeam $createTeam): void
    {
        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = $createTeam->handle(Auth::user(), $validated['teamName']);

        $this->dispatch('close-modal', name: 'create-team-switcher');

        $this->reset('teamName');

        Flux::toast(variant: 'success', text: __('Team created.'));

        $this->redirectRoute('teams.edit', ['team' => $team->slug], navigate: true);
    }
}; ?>

<x-modal
    name="create-team-switcher"
    title="{{ __('Create a new team') }}"
    :description="__('Give your team a name to get started.')"
    icon="user-group"
>
    <form wire:submit="createTeam" class="space-y-6">
        <flux:input
            wire:model="teamName"
            :label="__('Team name')"
            type="text"
            required
            autofocus
            data-test="switcher-create-team-name"
        />

        <x-modal.actions :submit="__('Create team')">
            <flux:modal.close>
                <flux:button variant="filled" class="w-full sm:w-auto">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button
                variant="primary"
                type="submit"
                class="w-full sm:w-auto"
                data-test="switcher-create-team-submit"
            >
                {{ __('Create team') }}
            </flux:button>
        </x-modal.actions>
    </form>
</x-modal>
