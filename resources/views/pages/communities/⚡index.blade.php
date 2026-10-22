<?php

use App\Actions\Communities\ToggleCommunityMembership;
use App\Enums\CommunityKind;
use App\Models\Community;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Illuminate\Validation\UnauthorizedException;

/**
 * The community directory — every community, its live member count and its
 * membership toggle.
 *
 * The grid is one query (`withCount` derives member counts from the pivot
 * rows, so a counter column cannot drift). Search is URL-bound (`?q=`), and
 * the kind filter narrows the directory without reloading. Joining and
 * leaving run through {@see ToggleCommunityMembership}, so the directory and
 * the community page can never disagree about what a membership means.
 */
new #[Title('Communities')] class extends Component
{
    /** Search across name and description. Bound to `?q=`. */
    #[Url]
    public string $q = '';

    /** Kind filter: all | open | invite-only. */
    #[Url]
    public string $kind = 'all';

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Communities',
            'description' => __('Topic-based circles of the developer community — join with one click.'),
        ]);
    }

    public function updatedQ(): void
    {
        $this->reset('kind');
    }

    /**
     * Join or leave the given community as the signed-in account.
     */
    public function toggleMembership(string $slug): void
    {
        $user = auth()->user();

        if ($user === null) {
            // Same contract as the feed: the login dialog opens here, on the
            // page the reader was reading, instead of a separate login screen.
            $this->redirectRoute('communities.index', ['signin' => 'required']);

            return;
        }

        $community = Community::query()->where('slug', $slug)->first();

        if ($community === null) {
            return;
        }

        try {
            app(ToggleCommunityMembership::class)->handle($user, $community);
        } catch (UnauthorizedException) {
            $this->dispatch('membership-refused', refused: $community->slug);

            return;
        }

        // Nudge every computed property to re-read the pivot state.
        unset($this->communities);
    }

    /**
     * The directory, filtered and with live member counts.
     *
     * @return Collection<int, Community>
     */
    #[Computed]
    public function communities(): Collection
    {
        return Community::query()
            ->withCount('memberships')
            ->with(['memberships' => fn ($query) => $query->where('user_id', auth()->id())])
            ->when($this->q !== '', fn ($query) => $query->matching($this->q))
            ->when($this->kind !== 'all', fn ($query) => $query->where('kind', $this->kind))
            ->orderBy('name')
            ->get();
    }

    /**
     * The kinds the filter rail offers, with live counts.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function kindCounts(): array
    {
        $counts = ['all' => 0, CommunityKind::Open->value => 0, CommunityKind::InviteOnly->value => 0];

        foreach (Community::query()->selectRaw('kind, count(*) as total')->groupBy('kind')->get() as $row) {
            $counts[$row->kind->value] = $row->total;
            $counts['all'] += $row->total;
        }

        return $counts;
    }
}; ?>

<div class="mx-auto w-full max-w-6xl px-4 py-10 sm:px-6" data-test="communities-index">
    <p class="font-mono text-[10px] font-medium uppercase tracking-[0.14em] text-signal-600 dark:text-signal-400">
        {{ __('Community · directory') }}
    </p>
    <h1 class="mt-3 text-3xl font-semibold tracking-tight text-ink-900 dark:text-ink-50">
        {{ __('Find your people') }}
    </h1>
    <p class="mt-2 max-w-[70ch] text-sm leading-relaxed text-ink-600 dark:text-ink-300">
        {{ __('Topic-based circles built on the same feed. Joining is one click and leaving takes the same — open communities are public, invite-only circles stay members-only.') }}
    </p>

    {{-- Filter bar --}}
    <div class="mt-6 flex flex-wrap items-center gap-3">
        <flux:input wire:model.live.debounce.350ms="q" icon="magnifying-glass" :placeholder="__('Search communities…')" class="max-w-xs" />

        <flux:button.group>
            @foreach (['all' => __('All'), 'open' => __('Open'), 'invite-only' => __('Invite only')] as $value => $label)
                <flux:button
                    wire:click="$set('kind', '{{ $value }}')"
                    :variant="$kind === $value ? 'primary' : 'ghost'"
                    size="sm"
                >
                    {{ $label }}
                    <span class="font-mono text-[10px] tabular-nums opacity-70">{{ $this->kindCounts[$value] ?? 0 }}</span>
                </flux:button>
            @endforeach
        </flux:button.group>
    </div>

    {{-- Directory grid --}}
    <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->communities as $community)
            <x-communities.card :community="$community" :member="$community->memberships->isNotEmpty()" />
        @empty
            <div class="col-span-full rounded-lg border border-ink-200 bg-white px-6 py-16 text-center dark:border-ink-800 dark:bg-ink-900">
                <p class="font-mono text-[10px] uppercase tracking-wider text-ink-500 dark:text-ink-400">{{ __('No matching communities') }}</p>
                <p class="mt-3 max-w-sm text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                    {{ __('No community matches that search. Loosen the term or browse another kind.') }}
                </p>
                <flux:button wire:click="$set('q', '')" variant="outline" class="mt-6">{{ __('Clear search') }}</flux:button>
            </div>
        @endforelse
    </div>
</div>
