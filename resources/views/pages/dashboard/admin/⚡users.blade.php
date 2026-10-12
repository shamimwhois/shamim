<?php

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
new #[Layout('layouts::dashboard')] #[Title('Users')] class extends Component
{
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
            ->paginate(20);
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
}; ?>

<div>
    <x-dashboard.header
        eyebrow="Administration"
        title="Users"
        description="Every registered account, and the role that governs what it can reach."
    />

    <div class="space-y-6 px-6 py-8 lg:px-10">
        {{-- ============================================================= --
         | Filters
         --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="w-full lg:max-w-xs">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    :placeholder="__('Search name or email…')"
                    :label="__('Search')"
                    label:sr-only
                />
            </div>

            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by role">
                <button
                    type="button"
                    wire:click="$set('roleFilter', 'all')"
                    @class([
                        'rounded-full border px-3 py-1 font-mono text-2xs font-medium transition-colors',
                        'border-ink-900 bg-ink-900 text-white dark:border-ink-50 dark:bg-ink-50 dark:text-ink-950' => $roleFilter === 'all',
                        'border-ink-200 text-ink-600 hover:border-ink-300 hover:text-ink-900 dark:border-ink-700 dark:text-ink-400 dark:hover:text-ink-100' => $roleFilter !== 'all',
                    ])
                >
                    {{ __('All') }} · {{ collect($this->roleCounts)->sum('count') }}
                </button>

                @foreach ($this->roleCounts as $row)
                    <button
                        type="button"
                        wire:click="$set('roleFilter', '{{ $row['role']->value }}')"
                        @class([
                            'rounded-full border px-3 py-1 font-mono text-2xs font-medium transition-colors',
                            'border-ink-900 bg-ink-900 text-white dark:border-ink-50 dark:bg-ink-50 dark:text-ink-950' => $roleFilter === $row['role']->value,
                            'border-ink-200 text-ink-600 hover:border-ink-300 hover:text-ink-900 dark:border-ink-700 dark:text-ink-400 dark:hover:text-ink-100' => $roleFilter !== $row['role']->value,
                        ])
                    >
                        {{ $row['role']->label() }} · {{ $row['count'] }}
                    </button>
                @endforeach

                @if ($this->hasFilters)
                    <button
                        type="button"
                        wire:click="clearFilters"
                        class="font-mono text-2xs font-medium text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400 dark:hover:text-signal-300"
                    >
                        {{ __('Clear') }}
                    </button>
                @endif
            </div>
        </div>

        @error('role')
            <div class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/40 dark:bg-red-950/40 dark:text-red-300">
                {{ $message }}
            </div>
        @enderror

        {{-- ============================================================= --
         | Account table
         --}}
        @if ($this->accounts->isEmpty())
            <x-empty-state
                icon="users"
                title="No accounts match those filters"
                description="Try a different search term, or clear the role filter."
            >
                @if ($this->hasFilters)
                    <flux:button size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-hidden rounded-lg border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
                <table class="w-full text-left">
                    <thead class="border-b border-ink-200 bg-ink-50/60 dark:border-ink-800 dark:bg-ink-950/40">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                {{ __('Account') }}
                            </th>
                            <th scope="col" class="hidden px-5 py-3 font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 sm:table-cell dark:text-ink-500">
                                {{ __('Joined') }}
                            </th>
                            <th scope="col" class="px-5 py-3 text-right font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                {{ __('Role') }}
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-ink-200 dark:divide-ink-800">
                        @foreach ($this->accounts as $account)
                            @php
                                $isSelf = $account->is(auth()->user());
                                $canEdit = ! $isSelf
                                    && ! $account->accountRole()->isAtLeast(auth()->user()->accountRole());
                            @endphp

                            <tr wire:key="account-{{ $account->id }}">
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="grid size-8 shrink-0 place-items-center rounded-md border border-ink-200 bg-ink-50 font-mono text-xs font-semibold text-ink-500 dark:border-ink-700 dark:bg-ink-800 dark:text-ink-300">
                                            {{ Str::of($account->name)->substr(0, 1)->upper() }}
                                        </div>

                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-ink-900 dark:text-ink-50">
                                                {{ $account->name }}
                                                @if ($isSelf)
                                                    <span class="ml-1 font-mono text-2xs text-ink-400 dark:text-ink-500">({{ __('you') }})</span>
                                                @endif
                                            </p>
                                            <p class="truncate font-mono text-2xs text-ink-400 dark:text-ink-500">
                                                {{ $account->email }}
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <td class="hidden px-5 py-3.5 font-mono text-2xs text-ink-500 sm:table-cell dark:text-ink-400">
                                    {{ $account->created_at->format('M j, Y') }}
                                </td>

                                <td class="px-5 py-3.5 text-right">
                                    @if ($canEdit)
                                        <flux:select
                                            wire:change="updateRole({{ $account->id }}, $event.target.value)"
                                            size="sm"
                                            class="ml-auto w-32"
                                            :aria-label="__('Role for :name', ['name' => $account->name])"
                                        >
                                            @foreach ($this->assignableRoles as $option)
                                                <flux:select.option
                                                    value="{{ $option['value'] }}"
                                                    :selected="$account->accountRole()->value === $option['value']"
                                                >
                                                    {{ $option['label'] }}
                                                </flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @else
                                        <flux:badge :color="$account->accountRole()->badgeColor()" size="sm">
                                            {{ $account->accountRole()->label() }}
                                        </flux:badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$this->accounts" />
        @endif
    </div>
</div>
