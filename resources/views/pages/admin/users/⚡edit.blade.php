<?php

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\UpdateUser;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\User;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin account editor.
 *
 * Handles both create and edit through one component: the routes differ, the
 * form does not. When `$user` is null the component is creating.
 *
 * The editor deliberately does not write roles. Role changes are the accounts
 * list's `updateRole()` job — with its own assignable-set and peer guards —
 * and duplicating that delicate logic here would only let it drift. On edit,
 * the role is shown as a read-only badge; on create, the picker is limited to
 * {@see AccountRole::assignableBy()}, so an admin cannot create a peer.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Users')] class extends Component
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * The account under edit. Null when creating.
     */
    #[Locked]
    public ?User $user = null;

    public string $name = '';

    public string $handle = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Role for a new account. Never read while editing.
     */
    public string $role = AccountRole::User->value;

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::ManageUsers), 403);
    }

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            Gate::authorize('update', $user);

            $this->user = $user;
            $this->name = $user->name;
            $this->handle = $user->handle ?? '';
            $this->email = $user->email;

            app(Seo::class)->set([
                'title' => 'Edit: '.$user->name,
                'noindex' => true,
            ]);

            return;
        }

        Gate::authorize('create', User::class);

        app(Seo::class)->set([
            'title' => 'New user',
            'noindex' => true,
        ]);
    }

    /**
     * The roles the signed-in admin is allowed to hand out.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function assignableRoles(): array
    {
        return AccountRole::assignableBy(auth()->user()->accountRole());
    }

    /**
     * True while the component is creating a new account.
     */
    public function creating(): bool
    {
        return $this->user === null || ! $this->user->exists;
    }

    /**
     * Validate and persist the form, branching create vs update.
     */
    public function save(CreateUser $createUser, UpdateUser $updateUser): void
    {
        $editing = ! $this->creating();

        $rules = [
            ...$this->profileRules($this->user?->id),
            'handle' => ['nullable', 'string', 'max:255', Rule::unique(User::class)->ignore($this->user?->id)],
        ];

        if ($editing) {
            $rules['password'] = ['nullable', 'string', Password::default(), 'confirmed'];
        } else {
            $rules['password'] = $this->passwordRules();
            $rules['role'] = ['required', 'string', Rule::in(collect($this->assignableRoles())->pluck('value')->all())];
        }

        $this->validate($rules);

        $data = [
            'name' => $this->name,
            'handle' => $this->handle !== '' ? $this->handle : null,
            'email' => $this->email,
            'password' => $this->password,
        ];

        if ($editing) {
            Gate::authorize('update', $this->user);

            $updateUser->handle(auth()->user(), $this->user, $data);

            Flux::toast(variant: 'success', text: __('Account updated.'));

            $this->redirectRoute('admin.users', navigate: true);

            return;
        }

        $account = $createUser->handle(auth()->user(), [...$data, 'role' => $this->role]);

        Flux::toast(variant: 'success', text: __(':name was created.', ['name' => $account->name]));

        $this->redirectRoute('admin.users', navigate: true);
    }

    /**
     * Permanently delete the account under edit.
     */
    public function delete(DeleteUser $deleteUser): void
    {
        if ($this->user === null || ! $this->user->exists) {
            return;
        }

        Gate::authorize('delete', $this->user);

        $deleteUser->handle($this->user);

        Flux::toast(variant: 'success', text: __('Account deleted.'));

        $this->redirectRoute('admin.users', navigate: true);
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Administration"
        :title="$this->creating() ? 'New user' : $user->name"
        :description="$this->creating()
            ? 'Create an account. It starts unverified, and roles are managed from the users list.'
            : 'Update the identity fields for this account.'"
        :breadcrumbs="[
            ['label' => __('Administration')],
            ['label' => __('Users'), 'href' => route('admin.users')],
            ['label' => $this->creating() ? __('New user') : __('Edit')],
        ]"
    >
        <x-slot:actions>
            <flux:button variant="ghost" :href="route('admin.users')" wire:navigate>
                {{ __('Cancel') }}
            </flux:button>

            <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" icon="check">
                <span wire:loading.remove wire:target="save">
                    {{ $this->creating() ? __('Create user') : __('Save changes') }}
                </span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.body>
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
            {{-- ========================================================= --
             | Identity fields
             --}}
            <div class="sui-card space-y-5 p-5 sm:p-6">
                <div>
                    <label for="user-name" class="sui-control-label">{{ __('Name') }}</label>
                    <input
                        id="user-name"
                        type="text"
                        wire:model="name"
                        autocomplete="off"
                        class="sui-control mt-2 w-full"
                    />
                    @error('name')
                        <p class="mt-2 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="user-handle" class="sui-control-label">{{ __('Handle') }}</label>
                    <input
                        id="user-handle"
                        type="text"
                        wire:model="handle"
                        placeholder="@shamim"
                        autocomplete="off"
                        class="sui-control mt-2 w-full"
                    />
                    <p class="sui-help mt-2">
                        {{ __('Optional. The identity shown alongside community posts.') }}
                    </p>
                    @error('handle')
                        <p class="mt-2 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="user-email" class="sui-control-label">{{ __('Email') }}</label>
                    <input
                        id="user-email"
                        type="email"
                        wire:model="email"
                        autocomplete="off"
                        class="sui-control mt-2 w-full"
                    />
                    @error('email')
                        <p class="mt-2 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="border-t border-sui-hairline pt-5">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="user-password" class="sui-control-label">
                                {{ $this->creating() ? __('Password') : __('New password') }}
                            </label>
                            <input
                                id="user-password"
                                type="password"
                                wire:model="password"
                                autocomplete="new-password"
                                class="sui-control mt-2 w-full"
                            />
                            @error('password')
                                <p class="mt-2 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="user-password-confirmation" class="sui-control-label">
                                {{ __('Confirm password') }}
                            </label>
                            <input
                                id="user-password-confirmation"
                                type="password"
                                wire:model="password_confirmation"
                                autocomplete="new-password"
                                class="sui-control mt-2 w-full"
                            />
                        </div>
                    </div>

                    @unless ($this->creating())
                        <p class="sui-help mt-3">
                            {{ __('Leave blank to keep the current password.') }}
                        </p>
                    @endunless
                </div>
            </div>

            {{-- ========================================================= --
             | Sidebar: role + danger zone
             --}}
            <div class="space-y-4">
                <div class="sui-card p-5">
                    <h2 class="sui-label">{{ __('Role') }}</h2>

                    @if ($this->creating())
                        <select
                            wire:model.live="role"
                            class="sui-control mt-4 w-full"
                            data-test="create-role-select"
                        >
                            @foreach ($this->assignableRoles as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>

                        <p class="sui-help mt-2">
                            {{ __('Roles above your own are never offered here.') }}
                        </p>
                    @else
                        <div class="mt-4">
                            <flux:badge :color="$user->accountRole()->badgeColor()" size="sm">
                                {{ $user->accountRole()->label() }}
                            </flux:badge>
                        </div>

                        <p class="sui-help mt-3">
                            {{ __('Roles are changed from the users list, where the assignable set is enforced per account.') }}
                        </p>
                    @endif
                </div>

                {{-- Danger zone --}}
                @if (! $this->creating() && Gate::allows('delete', $user))
                    <div class="rounded-xl border border-sui-danger/25 bg-sui-danger-soft p-5" x-data="{ confirming: false }">
                        <h2 class="text-2xs font-semibold uppercase tracking-wide text-sui-danger">
                            {{ __('Danger zone') }}
                        </h2>

                        <p class="mt-3 text-xs leading-normal text-ink-500">
                            {{ __('Deleting this account is permanent. All of its resources and data — posts, ideas, listings — will be permanently deleted.') }}
                        </p>

                        <div class="mt-4">
                            <template x-if="! confirming">
                                <flux:button variant="danger" size="xs" x-on:click="confirming = true" icon="trash">
                                    {{ __('Delete account') }}
                                </flux:button>
                            </template>

                            <template x-if="confirming">
                                <div class="space-y-3">
                                    <p class="text-xs font-medium text-sui-danger">
                                        {{ __('This will permanently delete “:name”. Are you sure?', ['name' => Str::limit($user->name, 32)]) }}
                                    </p>
                                    <div class="flex gap-2">
                                        <flux:button variant="danger" size="xs" wire:click="delete" wire:loading.attr="disabled" data-test="confirm-delete">
                                            {{ __('Yes, delete') }}
                                        </flux:button>
                                        <flux:button variant="ghost" size="xs" x-on:click="confirming = false">
                                            {{ __('Cancel') }}
                                        </flux:button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </x-app.ui.body>
</div>
