<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin dashboard home.
 *
 * A platform-health overview: how many accounts exist per role, what content is
 * live, and what is waiting on a moderator. Every number is a direct count from
 * the database.
 *
 * The navigation an admin sees is wider than this page's content on purpose —
 * this is the landing surface, and the modules behind the other menu entries
 * (products, orders, payments) land in later phases. No card on this page
 * claims a feature that does not exist.
 */
new #[Layout('layouts::dashboard')] #[Title('Admin dashboard')] class extends Component
{
    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Admin dashboard',
            'description' => 'Platform administration.',
            'noindex' => true,
        ]);
    }

    /**
     * Account counts, broken down by role.
     *
     * @return array<int, array{role: AccountRole, count: int}>
     */
    #[Computed]
    public function accountsByRole(): array
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
     * Total registered accounts.
     */
    #[Computed]
    public function totalAccounts(): int
    {
        return User::query()->count();
    }

    /**
     * Published posts across the platform.
     */
    #[Computed]
    public function publishedPosts(): int
    {
        return Post::query()->published()->count();
    }

    /**
     * Comments awaiting moderation platform-wide.
     */
    #[Computed]
    public function pendingComments(): int
    {
        return Comment::query()->pending()->count();
    }

    /**
     * The accounts most recently created.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function recentAccounts(): Collection
    {
        return User::query()->latest()->limit(6)->get();
    }

    /**
     * Permissions held by the signed-in admin, grouped by area.
     *
     * Rendered so the access model is visible rather than implied — an operator
     * can see exactly what the admin role confers.
     *
     * @return array<string, array<int, AccountPermission>>
     */
    #[Computed]
    public function permissionGroups(): array
    {
        return AccountPermission::grouped();
    }
}; ?>

<div>
    <x-dashboard.header
        eyebrow="Administration"
        :title="'Welcome back, '.Str::of(auth()->user()->name)->explode(' ')->first()"
        description="Platform health, accounts, and the moderation queue."
    >
        <x-slot:actions>
            <flux:button :href="route('admin.users')" icon="users" wire:navigate>
                Manage users
            </flux:button>
        </x-slot:actions>
    </x-dashboard.header>

    <div class="space-y-8 px-6 py-8 lg:px-10">
        {{-- ============================================================= --
         | Platform metrics
         --}}
        <section aria-labelledby="metrics-heading">
            <h2 id="metrics-heading" class="sr-only">Platform metrics</h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-dashboard.stat
                    label="Accounts"
                    :value="$this->totalAccounts"
                    icon="users"
                    hint="All roles"
                    :href="route('admin.users')"
                />

                <x-dashboard.stat
                    label="Published posts"
                    :value="$this->publishedPosts"
                    icon="globe-alt"
                    hint="Live on the public site"
                />

                <x-dashboard.stat
                    label="Awaiting moderation"
                    :value="$this->pendingComments"
                    icon="shield-exclamation"
                    :tone="$this->pendingComments > 0 ? 'attention' : 'default'"
                    :hint="$this->pendingComments > 0 ? 'Needs review' : 'Queue is clear'"
                    :href="route('dashboard.comments')"
                />

                <x-dashboard.stat
                    label="Sellers"
                    :value="collect($this->accountsByRole)->firstWhere('role', AccountRole::Seller)['count'] ?? 0"
                    icon="building-storefront"
                    hint="With selling rights"
                />
            </div>
        </section>

        <div class="grid gap-8 xl:grid-cols-2">
            {{-- ========================================================= --
             | Accounts by role
             --}}
            <section aria-labelledby="roles-heading" class="rounded-lg border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
                <div class="border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                    <h2 id="roles-heading" class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        Accounts by role
                    </h2>
                </div>

                <ul class="divide-y divide-ink-200 dark:divide-ink-800">
                    @foreach ($this->accountsByRole as $row)
                        <li class="flex items-center justify-between gap-4 px-5 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink-900 dark:text-ink-50">
                                    {{ $row['role']->label() }}
                                </p>
                                <p class="mt-0.5 text-xs text-ink-500 dark:text-ink-400">
                                    {{ $row['role']->description() }}
                                </p>
                            </div>

                            <span class="shrink-0 font-mono text-lg font-semibold tabular-nums text-ink-900 dark:text-ink-50">
                                {{ number_format($row['count']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- ========================================================= --
             | Recently created accounts
             --}}
            <section aria-labelledby="recent-heading" class="rounded-lg border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
                <div class="flex items-center justify-between gap-4 border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                    <h2 id="recent-heading" class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        Recently created
                    </h2>

                    <a
                        href="{{ route('admin.users') }}"
                        wire:navigate
                        class="font-mono text-2xs font-medium text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400 dark:hover:text-signal-300"
                    >
                        All users →
                    </a>
                </div>

                <ul class="divide-y divide-ink-200 dark:divide-ink-800">
                    @foreach ($this->recentAccounts as $account)
                        <li class="flex items-center gap-3.5 px-5 py-3.5">
                            <div class="grid size-7 shrink-0 place-items-center rounded-md border border-ink-200 bg-ink-50 font-mono text-2xs font-semibold text-ink-500 dark:border-ink-700 dark:bg-ink-800 dark:text-ink-300">
                                {{ Str::of($account->name)->substr(0, 1)->upper() }}
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink-900 dark:text-ink-50">
                                    {{ $account->name }}
                                </p>
                                <p class="truncate font-mono text-2xs text-ink-400 dark:text-ink-500">
                                    {{ $account->email }}
                                </p>
                            </div>

                            <flux:badge :color="$account->accountRole()->badgeColor()" size="sm" class="shrink-0">
                                {{ $account->accountRole()->label() }}
                            </flux:badge>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>

        {{-- ============================================================= --
         | The admin permission set, made visible
         --}}
        <section aria-labelledby="permissions-heading" class="rounded-lg border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
            <div class="border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                <h2 id="permissions-heading" class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                    What the admin role can do
                </h2>
                <p class="mt-1 text-xs text-ink-500 dark:text-ink-400">
                    Rendered from the permission matrix, so this list cannot drift from the code that enforces it.
                </p>
            </div>

            <div class="grid gap-x-8 gap-y-6 px-5 py-5 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($this->permissionGroups as $group => $permissions)
                    <div>
                        <p class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                            {{ collect($permissions)->first()->groupLabel() }}
                        </p>

                        <ul class="mt-3 space-y-1.5">
                            @foreach ($permissions as $permission)
                                <li class="flex items-start gap-2 text-xs text-ink-600 dark:text-ink-400">
                                    <flux:icon name="check" class="mt-0.5 size-3.5 shrink-0 text-signal-600 dark:text-signal-400" />
                                    <span>{{ $permission->label() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</div>
