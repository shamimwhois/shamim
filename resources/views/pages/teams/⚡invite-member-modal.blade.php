<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Rules\UniqueTeamInvitation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function createInvitation(): void
    {
        Gate::authorize('inviteMember', $this->team);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($this->team)],
            'inviteRole' => ['required', 'string', Rule::enum(TeamRole::class)],
        ]);

        $invitation = $this->team->invitations()->create([
            'email' => $validated['inviteEmail'],
            'role' => TeamRole::from($validated['inviteRole']),
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        $this->reset('inviteEmail', 'inviteRole');
        $this->dispatch('close-modal', name: 'invite-member');

        Flux::toast(variant: 'success', text: __('Invitation sent.'));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }

    #[Computed]
    public function availableRoles(): array
    {
        return TeamRole::assignable();
    }
}; ?>

<x-modal
    name="invite-member"
    title="{{ __('Invite a team member') }}"
    :description="__('Send an invitation to join this team.')"
    icon="user-plus"
>
    <form wire:submit="createInvitation" class="space-y-6">
        <div class="space-y-4">
            <flux:input
                wire:model="inviteEmail"
                type="email"
                :label="__('Email address')"
                required
                data-test="invite-email"
            />

            <flux:select wire:model="inviteRole" :label="__('Role')" data-test="invite-role">
                @foreach ($this->availableRoles as $role)
                    <flux:select.option value="{{ $role['value'] }}">{{ $role['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <x-modal.actions :submit="__('Send invitation')">
            <flux:modal.close>
                <flux:button variant="filled" class="w-full sm:w-auto">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button
                variant="primary"
                type="submit"
                class="w-full sm:w-auto"
                data-test="invite-submit"
            >
                {{ __('Send invitation') }}
            </flux:button>
        </x-modal.actions>
    </form>
</x-modal>
