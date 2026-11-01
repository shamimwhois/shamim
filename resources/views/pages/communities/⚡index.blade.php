<?php

use App\Actions\Communities\ToggleCommunityMembership;
use App\Enums\CommunityKind;
use App\Models\Community;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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
new #[Title('Communities')] #[Layout('layouts::feed')] class extends Component
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
<x-log.module
    active="communities"
    data-test="communities-index"
    :title="__('Communities')"
    :meta="trans_choice(':count circle|:count circles', $this->kindCounts['all'], ['count' => $this->kindCounts['all']])"
    :note="__('Topic-based circles built on the same feed. Joining is one click and leaving takes the same — open communities are public, invite-only circles stay members-only.')"
>
    <x-slot:filters>
        <div class="dn-module-tools">
            <div class="dn-search-field">
                <x-feed.icon name="search" :size="16" />
                <label for="community-search" class="sr-only">{{ __('Search communities') }}</label>
                <input
                    id="community-search"
                    type="search"
                    wire:model.live.debounce.350ms="q"
                    placeholder="{{ __('Search communities…') }}"
                    autocomplete="off"
                />
            </div>

            <div class="dn-filters" style="border-bottom: 0; padding: 0; margin-left: auto;">
                @foreach (['all' => __('All'), 'open' => __('Open'), 'invite-only' => __('Invite only')] as $value => $label)
                    <button
                        type="button"
                        class="dn-filter {{ $kind === $value ? 'is-active' : '' }}"
                        wire:click="$set('kind', '{{ $value }}')"
                        @if ($kind === $value) aria-current="true" @endif
                    >
                        {{ $label }}
                        <span class="dn-filter-count">{{ $this->kindCounts[$value] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </x-slot:filters>

    @if ($this->communities->isEmpty())
        <div class="dn-module-empty" data-test="communities-empty">
            <p class="dn-empty-command">fatal: no matching communities</p>
            <p class="dn-empty-text">
                {{ __('No community matches that search. Loosen the term or browse another kind.') }}
            </p>
            <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="$set('q', '')" style="margin-top: 16px;">
                {{ __('Clear search') }}
            </button>
        </div>
    @else
        <div class="dn-entries">
            @foreach ($this->communities as $community)
                @php $member = $community->memberships->isNotEmpty(); @endphp

                <article class="dn-entry" data-test="community-card">
                    <span class="dn-entry-glyph" aria-hidden="true">{{ $community->glyph }}</span>

                    <span class="dn-entry-main">
                        <span class="dn-entry-head">
                            <a href="{{ route('feed.index', ['category' => $community->topic]) }}" wire:navigate class="dn-entry-title">
                                {{ $community->name }}
                            </a>

                            @if ($community->kind === \App\Enums\CommunityKind::InviteOnly)
                                <span class="dn-status-chip">{{ __('invite only') }}</span>
                            @endif
                        </span>

                        <span class="dn-entry-desc">{{ $community->description }}</span>

                        <span class="dn-entry-meta">
                            <span>{{ trans_choice(':count member|:count members', $community->memberships_count, ['count' => $community->memberships_count]) }}</span>
                            <span>{{ $community->topic }}</span>
                        </span>
                    </span>

                    <span style="flex: none; align-self: center;">
                        @auth
                            @if ($member)
                                <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="toggleMembership('{{ $community->slug }}')" wire:loading.attr="disabled">
                                    {{ __('Leave') }}
                                </button>
                            @elseif ($community->kind === \App\Enums\CommunityKind::Open)
                                <button type="button" class="dn-btn dn-btn-sm" wire:click="toggleMembership('{{ $community->slug }}')" wire:loading.attr="disabled">
                                    {{ __('Join') }}
                                </button>
                            @else
                                <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" disabled>
                                    {{ __('By invitation') }}
                                </button>
                            @endif
                        @else
                            <a
                                href="{{ route('login') }}"
                                x-on:click="$flux.modal('login').show(); $event.preventDefault()"
                                class="dn-btn dn-btn-sm"
                            >{{ __('Sign in to join') }}</a>
                        @endauth
                    </span>
                </article>
            @endforeach
        </div>
    @endif
</x-log.module>
