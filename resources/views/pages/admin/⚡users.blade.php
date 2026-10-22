<?php

use App\Actions\Users\DeleteUser;
use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\User;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin account management.
 *
 * Lists every account and allows an admin to change a role. Two rules bound
 * what is possible here, and both are enforced on the server:
 *
 *   1. An actor may never assign a role above their own
 *      ({@see AccountRole::assignableBy()}) — that is how privilege escalation
 *      is prevented without a second permission.
 *   2. An actor may never change an account that is at least as privileged as
 *      themselves, so an admin cannot demote another admin.
 *
 * The role *picker* is filtered by rule 1, but the rule is re-checked in
 * `updateRole()`. The menu is a convenience; the check is the control.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Users')] class extends Component
{
    use WithPagination;

    /** Search term, reflected in the URL so a filtered list is shareable. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Role filter, `all` or an {@see AccountRole} value. */
    #[Url(as: 'role', except: 'all')]
    public string $roleFilter = 'all';

    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);

        app(Seo::class)->set([
            'title' => 'Users',
            'description' => 'Manage platform accounts and roles.',
            'noindex' => true,
        ]);
    }

    /**
     * Reset to the first page whenever a filter changes.
     *
     * Without this, a filter that shrinks the result set below the current
     * page leaves the user staring at an empty table.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Clear all filters.
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->roleFilter = 'all';
        $this->resetPage();
    }

    /**
     * True when at least one filter is narrowing the list.
     */
    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->roleFilter !== 'all';
    }

    /**
     * Counts per role, for the filter chip labels.
     *
     * @return array<int, array{role: AccountRole, count: int}>
     */
    #[Computed]
    public function roleCounts(): array
    {
        $counts = User::query()
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        return collect(AccountRole::cases())
            ->map(fn (AccountRole $role) => [
                'role' => $role,
                'count' => (int) $counts->get($role->value, 0),
            ])
            ->all();
    }

    /**
     * The filtered, paginated account list.
     *
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        return User::query()
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';

                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when(
                $this->roleFilter !== 'all',
                fn ($query) => $query->where('role', $this->roleFilter),
            )
            ->orderByRaw("case role when 'admin' then 0 when 'seller' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
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
     * Change an account's role.
     *
     * Authorises first, validates the target against the assignable set second,
     * and only then writes. The order matters: authorisation failures are 403s,
     * validation failures are field errors, and conflating them would leak
     * which role values exist to an account that may not assign them.
     */
    public function updateRole(int $userId, string $role): void
    {
        Gate::authorize('updateRole', User::class);

        $target = User::query()->findOrFail($userId);
        $newRole = AccountRole::tryFrom($role);

        $allowed = collect($this->assignableRoles())->pluck('value')->all();

        if ($newRole === null || ! in_array($role, $allowed, true)) {
            $this->addError('role', __('You cannot assign that role.'));

            return;
        }

        /* An admin must not be able to demote a peer or themselves. */
        if ($target->accountRole()->isAtLeast(auth()->user()->accountRole())) {
            $this->addError('role', __('You cannot change the role of this account.'));

            return;
        }

        $target->update(['role' => $newRole]);

        unset($this->accounts, $this->roleCounts);

        Flux::toast(
            text: __(':name is now a :role.', [
                'name' => $target->name,
                'role' => $newRole->label(),
            ]),
            variant: 'success',
        );
    }

    /**
     * Permanently delete an account.
     *
     * Authorisation is re-checked here rather than trusted from the rendered
     * button, because a Livewire update POST bypasses route middleware. The
     * policy blocks self-deletion and deleting a peer or superior; everything
     * the account authored cascades with it, which is why the UI asks twice.
     */
    public function delete(int $userId, DeleteUser $deleteUser): void
    {
        $target = User::query()->findOrFail($userId);

        Gate::authorize('delete', $target);

        $deleteUser->handle($target);

        unset($this->accounts, $this->roleCounts);

        Flux::toast(
            text: __(':name was deleted.', ['name' => $target->name]),
            variant: 'success',
        );
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Administration"
        title="Users"
        description="Every registered account, and the role that governs what it can reach."
        :breadcrumbs="[['label' => __('Administration')], ['label' => __('Users')]]"
    >
        <x-slot:actions>
            <flux:button variant="primary" :href="route('admin.users.create')" icon="plus" wire:navigate>
                {{ __('New user') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.body>
        {{-- ============================================================= --
         | Filters
         --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="w-full lg:max-w-xs">
                <label for="user-search" class="sr-only">{{ __('Search') }}</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>

                    <input
                        id="user-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('Search name or email…')"
                        autocomplete="off"
                        class="sui-control w-full pl-9"
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by role">
                <button
                    type="button"
                    wire:click="$set('roleFilter', 'all')"
                    @class(['sui-focus sui-tab', 'sui-tab-active' => $roleFilter === 'all'])
                >
                    {{ __('All') }} <span class="opacity-60 tabular-nums">{{ collect($this->roleCounts)->sum('count') }}</span>
                </button>

                @foreach ($this->roleCounts as $row)
                    <button
                        type="button"
                        wire:click="$set('roleFilter', '{{ $row['role']->value }}')"
                        @class(['sui-focus sui-tab', 'sui-tab-active' => $roleFilter === $row['role']->value])
                    >
                        {{ $row['role']->label() }} <span class="opacity-60 tabular-nums">{{ $row['count'] }}</span>
                    </button>
                @endforeach

                @if ($this->hasFilters)
                    <button
                        type="button"
                        wire:click="clearFilters"
                        class="sui-focus sui-action !px-2.5 !py-1.5 !text-xs"
                    >
                        {{ __('Clear') }}
                    </button>
                @endif
            </div>
        </div>

        @error('role')
            <div class="rounded-md border border-sui-danger/30 bg-sui-danger-soft px-4 py-3 text-sm text-sui-danger">
                {{ $message }}
            </div>
        @enderror

        {{-- ============================================================= --
         | Account table
         --}}
        @if ($this->accounts->isEmpty())
            <x-app.ui.empty-state
                icon="users"
                title="No accounts match those filters"
                description="Try a different search term, or clear the role filter."
            >
                @if ($this->hasFilters)
                    <flux:button size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                @endif
            </x-app.ui.empty-state>
        @else
            <div class="sui-card overflow-hidden">
                <table class="w-full text-left">
                    <thead class="border-b border-sui-hairline bg-sui-well">
                        <tr>
                            <th scope="col" class="px-5 py-3 sui-table-head">
                                {{ __('Account') }}
                            </th>
                            <th scope="col" class="hidden px-5 py-3 sui-table-head sm:table-cell">
                                {{ __('Joined') }}
                            </th>
                            <th scope="col" class="px-5 py-3 text-right sui-table-head">
                                {{ __('Role') }}
                            </th>
                            <th scope="col" class="px-5 py-3 text-right sui-table-head">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-sui-hairline">
                        @foreach ($this->accounts as $account)
                            @php
                                $isSelf = $account->is(auth()->user());
                                $canEdit = ! $isSelf
                                    && ! $account->accountRole()->isAtLeast(auth()->user()->accountRole());
                                $canUpdate = ! $isSelf && Gate::allows('update', $account);
                                $canDelete = Gate::allows('delete', $account);
                            @endphp

                            <tr
                                wire:key="account-{{ $account->id }}"
                                x-data="{ confirmingDelete: false }"
                                class="transition duration-150 ease-soft hover:bg-sui-hover"
                            >
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="grid size-8 shrink-0 place-items-center rounded-md border border-sui-hairline bg-sui-well text-xs font-semibold text-ink-500">
                                            {{ Str::of($account->name)->substr(0, 1)->upper() }}
                                        </div>

                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-sui-ink">
                                                {{ $account->name }}
                                                @if ($isSelf)
                                                    <span class="ml-1 text-2xs text-ink-400">({{ __('you') }})</span>
                                                @endif
                                            </p>
                                            <p class="truncate text-2xs text-ink-400">
                                                {{ $account->email }}
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <td class="hidden px-5 py-3.5 text-2xs text-ink-500 sm:table-cell">
                                    {{ $account->created_at->format('M j, Y') }}
                                </td>

                                <td class="px-5 py-3.5 text-right">
                                    @if ($canEdit)
                                        <select
                                            wire:change="updateRole({{ $account->id }}, $event.target.value)"
                                            class="sui-control w-auto !py-1.5 text-xs"
                                            data-test="role-select-{{ $account->id }}"
                                            :aria-label="__('Role for :name', ['name' => $account->name])"
                                        >
                                            @foreach ($this->assignableRoles as $option)
                                                <option
                                                    value="{{ $option['value'] }}"
                                                    @selected($account->accountRole()->value === $option['value'])
                                                >
                                                    {{ $option['label'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @else
                                        <flux:badge :color="$account->accountRole()->badgeColor()" size="sm">
                                            {{ $account->accountRole()->label() }}
                                        </flux:badge>
                                    @endif
                                </td>

                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($canUpdate)
                                            <flux:button variant="ghost" size="xs" :href="route('admin.users.edit', $account)" wire:navigate icon="pencil-square" data-test="edit-account-{{ $account->id }}">
                                                {{ __('Edit') }}
                                            </flux:button>
                                        @endif

                                        @if ($canDelete)
                                            <template x-if="! confirmingDelete">
                                                <flux:button variant="ghost" size="xs" x-on:click="confirmingDelete = true" icon="trash" class="!text-sui-danger">
                                                    {{ __('Delete') }}
                                                </flux:button>
                                            </template>

                                            <template x-if="confirmingDelete">
                                                <span class="inline-flex items-center gap-1.5">
                                                    <span class="text-2xs text-sui-danger">{{ __('Delete permanently?') }}</span>
                                                    <flux:button variant="danger" size="xs" wire:click="delete({{ $account->id }})" wire:loading.attr="disabled" data-test="confirm-delete-{{ $account->id }}">
                                                        {{ __('Yes') }}
                                                    </flux:button>
                                                    <flux:button variant="ghost" size="xs" x-on:click="confirmingDelete = false">
                                                        {{ __('No') }}
                                                    </flux:button>
                                                </span>
                                            </template>
                                        @endif

                                        @if (! $canUpdate && ! $canDelete)
                                            <span class="text-2xs text-ink-400">&mdash;</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-app.ui.pagination :paginator="$this->accounts" />
        @endif
</x-app.ui.body>
</div>
