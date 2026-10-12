<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<x-modal
    name="confirm-user-deletion"
    title="{{ __('Delete your account?') }}"
    :description="__('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm.')"
    icon="exclamation-triangle"
    tone="danger"
>
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        <x-modal.actions danger :submit="__('Delete account')">
            <flux:modal.close>
                <flux:button variant="filled" class="w-full sm:w-auto">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button
                variant="danger"
                type="submit"
                class="w-full sm:w-auto"
                data-test="confirm-delete-user-button"
            >
                {{ __('Delete account') }}
            </flux:button>
        </x-modal.actions>
    </form>
</x-modal>
