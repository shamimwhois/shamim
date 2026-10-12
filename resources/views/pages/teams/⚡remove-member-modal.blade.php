<?php

use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public ?int $memberId = null;

    public string $memberName = '';

    public string $modalName = 'remove-member';

    public function mount(
        Team $team,
        ?int $memberId = null,
        ?string $memberName = null,
        ?string $modalName = null,
    ): void
    {
        $this->team = $team;
        $this->memberId = $memberId;
        $this->memberName = $memberName ?? '';
        $this->modalName = $modalName ?? ($memberId ? "remove-member-{$memberId}" : 'remove-member');
    }

    public function removeMember(): void
    {
        Gate::authorize('removeMember', $this->team);

        $user = User::findOrFail($this->memberId);

        if ($this->memberName === '') {
            $this->memberName = $user->name;
        }

        $this->team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTeam($this->team)) {
            $user->switchTeam($user->personalTeam());
        }

        $this->dispatch('close-modal', name: $this->modalName);

        Flux::toast(variant: 'success', text: __('Member removed.'));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }
}; ?>

<x-modal
    :name="$modalName"
    :title="__('Remove team member')"
    :description="__('Are you sure you want to remove :name from this team?', ['name' => $memberName])"
    icon="user-minus"
    tone="danger"
>
    <form wire:submit="removeMember" class="space-y-6">
        <x-modal.actions>
            <flux:modal.close>
                <flux:button variant="filled" class="w-full sm:w-auto">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button
                variant="danger"
                type="submit"
                class="w-full sm:w-auto"
                data-test="remove-member-confirm"
            >
                {{ __('Remove member') }}
            </flux:button>
        </x-modal.actions>
    </form>
</x-modal>
