<?php

use App\Enums\AccountPermission;
use App\Models\FeedPost;
use App\Support\FeedCorpus;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Feed moderation console.
 *
 * The admin surface over the community corpus. Every action toggles one
 * boolean column on one post — pin, feature, hide, delete, solve — and the
 * write is persisted on {@see FeedPost}, so every community surface (the
 * public feed and the discussion detail) immediately renders the decision.
 * Nothing is session-scoped: a curation decision is a property of the post,
 * not of the operator's browser.
 *
 * The whitelist of flags is enforced in the action, not just in the UI:
 * `wire:click` is client input, so `toggle()` re-checks every call, and
 * `solved` is additionally restricted to questions — solving a showcase
 * would leave the public feed claiming a thread that never asked a question.
 *
 * "Restore defaults" re-applies the seeded baseline from
 * {@see FeedCorpus} — the one undo for every decision at once.
 *
 * The `boot()` guard is not decoration. A `wire:click` is a POST to
 * `/livewire/update`, which never passes through the `permission:admin:access`
 * on the route, so without it a non-admin with a hand-built request would be
 * flipping moderation flags on the community corpus. Each mutating method
 * re-checks by name rather than trusting the page guard alone, which is the
 * arrangement the marketplace console already documents for the same reason.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Feed moderation')] class extends Component
{
    /** The moderation flags an operator can toggle, per post kind. */
    private const FLAGS = ['pinned', 'featured', 'hidden', 'deleted'];

    /** Queue tabs, in render order. */
    private const TABS = [
        'all' => 'All posts',
        'needs-moderation' => 'Needs moderation',
        'curated' => 'Pinned & solved',
        'removed' => 'Removed',
    ];

    /** Active queue tab. */
    public string $tab = 'all';

    /** Free-text filter across title, author and handle. */
    public string $q = '';

    /** The post the inspector is pointed at; null = nothing selected. */
    public ?int $focusedId = null;

    public function boot(): void
    {
        $this->authorizeAdmin();
    }

    /**
     * The admin panel permission, checked once for the page and again in every
     * write. Extracted so an action names its own authority.
     */
    private function authorizeAdmin(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(AccountPermission::AccessAdminPanel) ?? false,
            403
        );
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Feed moderation',
            'description' => 'Curate the community feed.',
            'noindex' => true,
        ]);
    }

    /**
     * Toggle one whitelist-checked flag on one post, persisting the change.
     */
    public function toggle(int $postId, string $flag): void
    {
        $this->authorizeAdmin();

        abort_unless(in_array($flag, [...self::FLAGS, 'solved'], true), 404);

        $post = FeedPost::query()->find($postId);

        abort_unless($post !== null, 404);

        if ($flag === 'solved') {
            // Only a question can be solved; the public feed renders the
            // solved badge from `answered`, and a showcase wearing it would
            // be a lie about the thread.
            abort_unless($post->isQuestion(), 404);

            $post->answered = ! $post->answered;
        } else {
            $post->{$flag} = ! $post->{$flag};
        }

        $post->save();

        $this->focusedId = $post->id;
    }

    /**
     * Point the inspector at one post.
     */
    public function focus(int $postId): void
    {
        abort_unless(FeedPost::query()->whereKey($postId)->exists(), 404);

        $this->focusedId = $postId;
    }

    /**
     * Re-apply the seeded moderation baseline for every post.
     */
    public function restoreDefaults(): void
    {
        $this->authorizeAdmin();

        foreach (FeedCorpus::posts() as $seed) {
            FeedPost::query()
                ->where('slug', $seed['slug'])
                ->update([
                    'pinned' => $seed['pinned'],
                    'featured' => $seed['featured'],
                    'hidden' => $seed['hidden'],
                    'deleted' => $seed['deleted'],
                    'answered' => $seed['answered'],
                ]);
        }

        $this->focusedId = null;
    }

    /**
     * The corpus, curated posts first, filtered by the active tab and search.
     *
     * @return Collection<int, FeedPost>
     */
    #[Computed]
    public function posts(): Collection
    {
        $posts = FeedPost::query()
            ->orderByDesc('pinned')
            ->orderByDesc('featured')
            ->orderByDesc('posted_at')
            ->get();

        if ($this->tab === 'needs-moderation') {
            $posts = $posts->filter(fn (FeedPost $post): bool => $post->reports > 0)->values();
        } elseif ($this->tab === 'curated') {
            $posts = $posts->filter(fn (FeedPost $post): bool => $post->pinned || $post->featured || ($post->isQuestion() && $post->answered))->values();
        } elseif ($this->tab === 'removed') {
            $posts = $posts->filter(fn (FeedPost $post): bool => $post->hidden || $post->deleted)->values();
        }

        $needle = mb_strtolower(trim($this->q));
        if ($needle !== '') {
            $posts = $posts->filter(function (FeedPost $post) use ($needle): bool {
                return str_contains(
                    mb_strtolower($post->title.' '.$post->body.' '.$post->author.' '.$post->handle),
                    $needle,
                );
            })->values();
        }

        return $posts;
    }

    /**
     * Headline numbers for the KPI band.
     *
     * @return array{total: int, solved: int, reported: int, removed: int, decisions: int}
     */
    #[Computed]
    public function stats(): array
    {
        $all = FeedPost::query()->get();

        $defaults = collect(FeedCorpus::posts())->keyBy('slug');

        $decisions = $all->filter(function (FeedPost $post) use ($defaults): bool {
            $seed = $defaults->get($post->slug);

            return $seed === null
                || $post->pinned !== (bool) $seed['pinned']
                || $post->featured !== (bool) $seed['featured']
                || $post->hidden !== (bool) $seed['hidden']
                || $post->deleted !== (bool) $seed['deleted']
                || $post->answered !== (bool) $seed['answered'];
        })->count();

        return [
            'total' => $all->count(),
            'solved' => $all->where('type', 'question')->where('answered', true)->count(),
            'reported' => $all->where('reports', '>', 0)->count(),
            'removed' => $all->filter(fn (FeedPost $post): bool => $post->hidden || $post->deleted)->count(),
            'decisions' => $decisions,
        ];
    }

    /**
     * The post the inspector shows — the focused one, or the first in the queue.
     */
    #[Computed]
    public function focused(): ?FeedPost
    {
        if ($this->focusedId !== null) {
            $focused = FeedPost::query()->find($this->focusedId);

            if ($focused !== null) {
                return $focused;
            }
        }

        return $this->posts->first();
    }
}; ?>

@php
    $status = function (FeedPost $post): array {
        if ($post->deleted) {
            return ['label' => __('Deleted'), 'tone' => 'error'];
        }
        if ($post->hidden) {
            return ['label' => __('Hidden'), 'tone' => 'error'];
        }
        if ($post->reports > 0) {
            return ['label' => __(':n reports', ['n' => $post->reports]), 'tone' => 'error'];
        }
        if ($post->pinned) {
            return ['label' => __('Pinned'), 'tone' => 'mint'];
        }
        if ($post->featured) {
            return ['label' => __('Featured'), 'tone' => 'azure'];
        }
        if ($post->isSolved()) {
            return ['label' => __('Solved'), 'tone' => 'mint'];
        }

        return ['label' => __($post->type), 'tone' => 'neutral'];
    };

    $accent = function (FeedPost $post) use ($status): string {
        return match ($status($post)['tone']) {
            'error' => 'bg-feed-error',
            'mint' => 'bg-feed-mint',
            'azure' => 'bg-feed-azure',
            default => 'bg-feed-line-strong',
        };
    };
@endphp

<div data-test="admin-feed-moderation">
    <x-app.ui.header
        eyebrow="Administration"
        title="Feed moderation"
        description="Curate the community feed: pin, feature, hide, delete, and resolve."
        :breadcrumbs="[['label' => __('Administration')]]"
    >
        <x-slot:actions>
            <flux:button :href="route('feed.index')" icon="arrow-right" wire:navigate>
                {{ __('View the public feed') }}
            </flux:button>
            <flux:button variant="danger" wire:click="restoreDefaults" wire:confirm="{{ __('Restore every post to its seeded moderation baseline?') }}">
                {{ __('Restore defaults') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    {{-- KPI band --}}
    <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="feed-card p-4">
            <p class="font-mono text-[10px] uppercase tracking-wider text-feed-ink-3">{{ __('Posts in corpus') }}</p>
            <p class="mt-2 font-mono text-2xl font-semibold tabular-nums text-feed-ink">{{ $this->stats['total'] }}</p>
        </div>
        <div class="feed-card p-4">
            <p class="font-mono text-[10px] uppercase tracking-wider text-feed-ink-3">{{ __('Solved questions') }}</p>
            <p class="mt-2 font-mono text-2xl font-semibold tabular-nums text-feed-mint">{{ $this->stats['solved'] }}</p>
        </div>
        <div class="feed-card p-4">
            <p class="font-mono text-[10px] uppercase tracking-wider text-feed-ink-3">{{ __('Reported posts') }}</p>
            <p class="mt-2 font-mono text-2xl font-semibold tabular-nums text-feed-error">{{ $this->stats['reported'] }}</p>
        </div>
        <div class="feed-card p-4">
            <p class="font-mono text-[10px] uppercase tracking-wider text-feed-ink-3">{{ __('Active decisions') }}</p>
            <p class="mt-2 font-mono text-2xl font-semibold tabular-nums text-feed-ink">{{ $this->stats['decisions'] }}</p>
        </div>
    </div>

    <p class="mt-4 text-xs leading-relaxed text-feed-ink-2">
        {{ __('Decisions persist on the post itself and are visible on the public feed immediately, for every visitor. Restoring defaults re-applies the seeded baseline.') }}
    </p>

    {{-- Filter bar --}}
    <div class="mt-6 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <div class="feed-segment" role="group" aria-label="{{ __('Queue tabs') }}">
            @foreach (self::TABS as $key => $label)
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $key }}')"
                    aria-pressed="{{ $tab === $key ? 'true' : 'false' }}"
                >{{ __($label) }}</button>
            @endforeach
        </div>

        <div class="relative xl:w-72">
            <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-feed-ink-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <label class="sr-only" for="admin-feed-search">{{ __('Filter the queue') }}</label>
            <input
                id="admin-feed-search"
                type="search"
                wire:model.live.debounce.350ms="q"
                placeholder="{{ __('Filter by title, author, @handle…') }}"
                class="feed-search"
            />
        </div>
    </div>

    {{-- Queue + inspector --}}
    <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_21rem]">
        <div class="space-y-4">
            <p class="px-1 font-mono text-[10px] uppercase tracking-wider text-feed-ink-3">
                {{ __('Showing :n of :m queue items', ['n' => count($this->posts), 'm' => $this->stats['total']]) }}
            </p>

            @forelse ($this->posts as $post)
                @php
                    $pill = $status($post);
                @endphp
                <article
                    @class([
                        'feed-card relative overflow-hidden p-4 sm:p-5',
                        'ring-1 ring-feed-azure' => $focusedId === $post->id,
                    ])
                    wire:key="mod-post-{{ $post->id }}"
                >
                    <span aria-hidden="true" class="absolute inset-y-0 left-0 w-1 {{ $accent($post) }}"></span>

                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-feed.avatar :initials="$post->avatar" :tone="$post->pinned || $post->featured ? 'mint' : 'neutral'" />
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-x-1.5">
                                    <span class="text-[13px] font-semibold text-feed-ink">{{ $post->author }}</span>
                                    <span class="font-mono text-[11px] text-feed-ink-3">{{ $post->handle }}</span>
                                    <span aria-hidden="true" class="size-1 rounded-full bg-feed-line-strong"></span>
                                    <span class="font-mono text-[11px] text-feed-ink-3">{{ $post->type }} · {{ $post->posted_at?->diffForHumans() }}</span>
                                </div>
                                <h3 class="mt-1 max-w-xl text-sm font-semibold leading-snug text-feed-ink">{{ $post->title }}</h3>
                            </div>
                        </div>

                        <span @class(['feed-chip shrink-0', 'is-mint' => $pill['tone'] === 'mint', 'is-azure' => $pill['tone'] === 'azure', 'bg-feed-error/15! text-feed-error!' => $pill['tone'] === 'error'])>
                            {{ $pill['label'] }}
                        </span>
                    </div>

                    <p class="mt-2 line-clamp-2 max-w-2xl text-xs leading-relaxed text-feed-ink-2">{{ $post->body }}</p>

                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-feed-line pt-4">
                        <span class="font-mono text-[10px] uppercase tracking-wider text-feed-ink-3">{{ __('Actions') }}</span>

                        @foreach (['pinned' => __('Pin'), 'featured' => __('Feature'), 'hidden' => __('Hide'), 'deleted' => __('Delete')] as $flag => $label)
                            <button
                                type="button"
                                wire:click="toggle({{ $post->id }}, '{{ $flag }}')"
                                @class([
                                    'feed-filter',
                                    'border-feed-azure! text-feed-azure!' => $post->{$flag},
                                ])
                                aria-pressed="{{ $post->{$flag} ? 'true' : 'false' }}"
                            >
                                {{ $label }}
                            </button>
                        @endforeach

                        @if ($post->isQuestion())
                            <button
                                type="button"
                                wire:click="toggle({{ $post->id }}, 'solved')"
                                @class(['feed-filter', 'border-feed-mint! text-feed-mint!' => $post->isSolved()])
                                aria-pressed="{{ $post->isSolved() ? 'true' : 'false' }}"
                            >
                                {{ __('Solve') }}
                            </button>
                        @endif

                        <button type="button" wire:click="focus({{ $post->id }})" class="feed-ghost ml-auto">
                            {{ __('Inspect') }}
                        </button>

                        @if ($post->hasDetail())
                            <a href="{{ route('feed.show', ['slug' => $post->slug]) }}" wire:navigate class="feed-ghost">
                                {{ __('View thread') }}
                            </a>
                        @endif
                    </div>
                </article>
            @empty
                <div class="feed-card grid place-items-center px-6 py-16 text-center">
                    <p class="font-mono text-[10px] uppercase tracking-wider text-feed-ink-3">{{ __('No matching posts') }}</p>
                    <p class="mt-3 max-w-sm text-sm leading-relaxed text-feed-ink-2">
                        {{ __('No posts match this tab and search together. Loosen one filter and the queue comes back.') }}
                    </p>
                    <button type="button" wire:click="$set('tab', 'all'); $set('q', '')" class="feed-ghost mt-6 border border-feed-line">
                        {{ __('Reset filters') }}
                    </button>
                </div>
            @endforelse
        </div>

        {{-- Inspector --}}
        <aside class="feed-card p-4 xl:sticky xl:top-24" aria-label="{{ __('Post inspector') }}">
            @if ($this->focused)
                @php $focused = $this->focused; @endphp
                <div class="flex items-center justify-between">
                    <h3 class="font-mono text-[10px] font-semibold uppercase tracking-wider text-feed-ink-3">
                        {{ __('Inspector · :id', ['id' => '#'.$focused->id]) }}
                    </h3>
                    <span @class(['feed-chip', 'is-mint' => $focused->pinned || $focused->featured])>
                        {{ $focused->type }}
                    </span>
                </div>

                <p class="mt-3 text-sm font-semibold leading-snug text-feed-ink">{{ $focused->title }}</p>
                <p class="mt-1 font-mono text-[11px] text-feed-ink-3">
                    {{ $focused->author }} · {{ $focused->handle }} · {{ $focused->posted_at?->diffForHumans() }}
                </p>

                <dl class="mt-4 grid grid-cols-2 gap-2 font-mono text-[11px]">
                    <div class="rounded-md bg-feed-well p-2.5">
                        <dt class="text-feed-ink-3">{{ __('Score') }}</dt>
                        <dd class="mt-0.5 font-semibold tabular-nums text-feed-ink">{{ $focused->formattedScore() }}</dd>
                    </div>
                    <div class="rounded-md bg-feed-well p-2.5">
                        <dt class="text-feed-ink-3">{{ __('Reports') }}</dt>
                        <dd class="mt-0.5 font-semibold tabular-nums {{ $focused->reports > 0 ? 'text-feed-error' : 'text-feed-ink' }}">{{ $focused->reports }}</dd>
                    </div>
                    <div class="rounded-md bg-feed-well p-2.5">
                        <dt class="text-feed-ink-3">{{ __('Comments') }}</dt>
                        <dd class="mt-0.5 font-semibold tabular-nums text-feed-ink">{{ $focused->comments }}</dd>
                    </div>
                    <div class="rounded-md bg-feed-well p-2.5">
                        <dt class="text-feed-ink-3">{{ __('Views') }}</dt>
                        <dd class="mt-0.5 font-semibold tabular-nums text-feed-ink">{{ number_format($focused->views) }}</dd>
                    </div>
                </dl>

                <ul class="mt-4 space-y-1.5 font-mono text-[11px]">
                    @foreach (['pinned' => __('Pinned'), 'featured' => __('Featured'), 'hidden' => __('Hidden'), 'deleted' => __('Deleted')] as $flag => $label)
                        <li class="flex items-center justify-between">
                            <span class="text-feed-ink-3">{{ $label }}</span>
                            <span class="{{ $focused->{$flag} ? 'text-feed-mint' : 'text-feed-ink-3' }}">{{ $focused->{$flag} ? __('on') : __('off') }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-4 flex flex-wrap gap-2 border-t border-feed-line pt-4">
                    @if ($focused->hasDetail())
                        <a href="{{ route('feed.show', ['slug' => $focused->slug]) }}" wire:navigate class="feed-ghost">
                            {{ __('Open the full thread') }}
                        </a>
                    @endif
                    <button type="button" wire:click="focus({{ $focused->id }})" class="feed-ghost">
                        {{ __('Refresh inspector') }}
                    </button>
                </div>
            @else
                <p class="py-8 text-center text-sm text-feed-ink-2">{{ __('Nothing to inspect.') }}</p>
            @endif
        </aside>
    </div>
</div>
