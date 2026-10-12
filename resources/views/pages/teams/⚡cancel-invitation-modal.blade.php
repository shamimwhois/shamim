<?php

use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public string $invitationCode = '';

    public string $invitationEmail = '';

    public string $modalName = 'cancel-invitation';

    public function mount(
        Team $team,
        ?string $invitationCode = null,
        ?string $invitationEmail = null,
        ?string $modalName = null,
    ): void
    {
        $this->team = $team;
        $this->invitationCode = $invitationCode ?? '';
        $this->invitationEmail = $invitationEmail ?? '';
        $this->modalName = $modalName ?? ($invitationCode ? "cancel-invitation-{$invitationCode}" : 'cancel-invitation');
    }

    public function cancelInvitation(): void
    {
        $invitation = $this->team->invitations()->where('code', $this->invitationCode)->firstOrFail();

        if ($this->invitationEmail === '') {
            $this->invitationEmail = $invitation->email;
        }

        Gate::authorize('cancelInvitation', $this->team);

        $invitation->delete();

        $this->dispatch('close-modal', name: $this->modalName);

        Flux::toast(variant: 'success', text: __('Invitation cancelled.'));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }
}; ?>

<x-modal
    :name="$modalName"
    :title="__('Cancel invitation')"
    :description="__('Are you sure you want to cancel the invitation for :email?', ['email' => $invitationEmail])"
    icon="envelope"
    tone="danger"
>
    <form wire:submit="cancelInvitation" class="space-y-6">
        <x-modal.actions>
            <flux:modal.close>
                <flux:button variant="filled" class="w-full sm:w-auto">
                    {{ __('Keep invitation') }}
                </flux:button>
            </flux:modal.close>

            <flux:button
                variant="danger"
                type="submit"
                class="w-full sm:w-auto"
                data-test="cancel-invitation-confirm"
            >
                {{ __('Cancel invitation') }}
            </flux:button>
        </x-modal.actions>
    </form>
</x-modal>
