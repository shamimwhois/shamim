@props([
    'posts',
    'trendingTags',
    'bounties',
    'contributors',
    'communities',
    'featuredProducts',
    'reader' => null,
    'readerInitials' => '',
    'tag' => null,
    'refusedSlug' => null,
])

{{--
    Inspector rail — the PRD's contextual right pane.

    The top of the rail is the reader's own context — profile overview and
    About Me, both from the authenticated account (guests get the community's
    invitation instead) — then the discovery widgets the PRD names: trending
    topics, needs-answers and featured projects, followed by the membership
    directory, the corpus telemetry, the contributor leaderboard and the house
    guidelines. It is a pure projection of the parent's already-loaded
    collections plus the featured-products query: the widgets cost nothing
    extra to render.

    On desktop it sticks below the feed header; below `lg` it stacks under
    the stream.
--}}

@php
    $questionCount = $posts->filter(fn ($post) => $post->isQuestion())->count();
    $solvedCount = $posts
        ->filter(fn ($post) => $post->isQuestion() && $post->answered)
        ->count();
    $newest = $posts->map(fn (\App\Models\FeedPost $post) => $post->posted_at)->filter()->max();
    $weeklyActivity = $posts
        ->map(fn (\App\Models\FeedPost $post) => $post->posted_at)
        ->filter()
        // Age in weeks, capped to the trailing 12 the chart renders.
        ->groupBy(fn ($postedAt) => max(0, min(11, (int) (abs((float) $newest->diffInDays($postedAt)) / 7))))
        ->map->count();
    $peakWeek = max(1, $weeklyActivity->max());
    $memberSince = $reader?->created_at?->translatedFormat('M Y');

    // The reader's own footprint in the corpus — a pass over the memoised
    // collection, not a query. Zero for a guest, which is the truth: a guest
    // has published nothing here.
    $readerPosts = $reader !== null
        ? $posts->where('user_id', $reader->id)->count()
        : 0;
@endphp

<aside class="space-y-4 lg:sticky lg:top-[6.25rem]" aria-label="{{ __('Feed context') }}">
    {{-- ===== Profile overview — the reader, from the account record ======== --}}
    <div class="feed-card p-4" aria-labelledby="overview-heading">
        <div class="flex items-center justify-between">
            <h3 id="overview-heading" class="feed-eyebrow font-semibold">{{ __('Profile overview') }}</h3>
            @auth
                <a href="{{ route('profile.edit') }}" wire:navigate class="feed-ghost py-0.5! text-[10px]! uppercase tracking-wider">{{ __('Edit') }}</a>
            @endauth
        </div>

        <div class="mt-3 flex items-center gap-3">
            <x-feed.avatar :initials="$readerInitials !== '' ? $readerInitials : '––'" tone="mint" />
            <div class="min-w-0">
                <p class="truncate text-xs font-semibold text-feed-ink">{{ $reader?->name ?? __('Guest reader') }}</p>
                <p class="truncate font-mono text-[10px] text-feed-ink-3">
                    @auth
                        {{ $reader->role->label() }}@if ($memberSince !== null) · {{ __('since :date', ['date' => $memberSince]) }}@endif
                    @else
                        {{ __('Browsing the open feed') }}
                    @endauth
                </p>
            </div>
        </div>

        {{-- The reader's own footprint. The corpus totals have a single home
             now — the hero band — so this widget answers the question that is
             actually about the reader: how much have I published? --}}
        <dl class="mt-3 grid grid-cols-2 gap-2">
            <div class="feed-inset p-2.5">
                <dt class="feed-eyebrow">{{ __('Your posts') }}</dt>
                <dd class="mt-0.5 feed-stat-value text-feed-ink">{{ $readerPosts }}</dd>
            </div>
            <div class="feed-inset p-2.5">
                <dt class="feed-eyebrow">{{ __('Member since') }}</dt>
                <dd class="mt-1 feed-meta">{{ $memberSince ?? '—' }}</dd>
            </div>
        </dl>
    </div>

    {{-- ===== About me — the profile facts that exist, and the invitation === --}}
    <div class="feed-card p-4" aria-labelledby="about-heading">
        <h3 id="about-heading" class="feed-eyebrow font-semibold">{{ __('About me') }}</h3>

        @auth
            <p class="mt-3 text-xs font-semibold text-feed-ink">{{ $reader->role->label() }}</p>
            <p class="mt-1 text-[11px] leading-relaxed text-feed-ink-2">
                @if ($memberSince !== null)
                    {{ __('Member since :date.', ['date' => $memberSince]) }}
                @endif
                @if ($reader->email_verified_at !== null)
                    {{ __('Email address verified.') }}
                @else
                    {{ __('Email address not yet verified.') }}
                @endif
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                @if ($reader->email_verified_at !== null)
                    <span class="feed-chip is-mint">
                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M4.5 12.75l6 6 9-13.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('Verified') }}
                    </span>
                @endif
                <a href="{{ route('profile.edit') }}" wire:navigate class="feed-ghost border border-feed-line">{{ __('Edit profile') }}</a>
            </div>
        @else
            <p class="mt-2 text-[11px] leading-relaxed text-feed-ink-2">
                {{ __('Get help, share knowledge, and grow together. Sign in to follow topics, join communities and publish your own posts.') }}
            </p>
            <a href="{{ route('register') }}" x-on:click="$flux.modal('register').show(); $event.preventDefault()" class="feed-action mt-3 w-full justify-center">{{ __('Join now') }}</a>
            <p class="mt-2 text-center text-[10px] text-feed-ink-3">
                {{ __('Already a member?') }}
                <a href="{{ route('login') }}" x-on:click="$flux.modal('login').show(); $event.preventDefault()" class="text-feed-azure underline-offset-2 hover:underline">{{ __('Sign in') }}</a>
            </p>
        @endauth
    </div>

    {{-- ===== Trending tags — the corpus' own frequencies ==================== --}}
    <div class="feed-card p-4" aria-labelledby="tags-heading">
        <h3 id="tags-heading" class="feed-eyebrow font-semibold">{{ __('Trending tags') }}</h3>
        <ul class="mt-3 flex flex-wrap gap-1.5">
            @foreach (array_slice($trendingTags, 0, 10) as $trend)
                <li wire:key="tag-{{ $trend['tag'] }}">
                    <button
                        type="button"
                        wire:click="$set('tag', '{{ $trend['tag'] }}')"
                        @class(['feed-chip transition-colors hover:text-feed-ink', 'is-azure' => $tag === $trend['tag']])
                        aria-pressed="{{ $tag === $trend['tag'] ? 'true' : 'false' }}"
                        aria-label="{{ __('Filter by tag :tag', ['tag' => $trend['tag']]) }}"
                    >
                        #{{ $trend['tag'] }} <span class="tabular-nums text-feed-ink-3">{{ $trend['count'] }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- ===== Unanswered questions ranked by community demand =============== --}}
    <div class="feed-card p-4" aria-labelledby="bounties-heading">
        <div class="flex items-center justify-between">
            <h3 id="bounties-heading" class="feed-eyebrow font-semibold">{{ __('Needs answers') }}</h3>
            <button type="button" wire:click="$set('sort', 'unanswered')" class="feed-ghost py-0.5! text-[10px]! uppercase tracking-wider">{{ __('Explore') }}</button>
        </div>
        <ul class="mt-3 space-y-3">
            @foreach ($bounties as $bounty)
                <li wire:key="bounty-{{ $bounty->id }}">
                    {{-- A bounty with authored detail opens the full thread —
                         the only slug feed.show resolves. The rest send the
                         reader to the unanswered queue, not an arbitrary
                         first-tag filter. --}}
                    @if ($bounty->hasDetail())
                        <a
                            href="{{ route('feed.show', $bounty) }}"
                            wire:navigate
                            class="group block w-full feed-inset is-action p-3 text-left"
                        >
                            <div class="flex items-center justify-between">
                                <span class="feed-chip is-attention">{{ __('Unanswered') }}</span>
                                <span class="feed-count">↑ {{ $bounty->formattedScore() }}</span>
                            </div>
                            <p class="mt-2 line-clamp-2 text-xs font-semibold leading-relaxed text-feed-ink">{{ $bounty->title }}</p>
                            <p class="mt-2 font-mono text-[10px] text-feed-ink-3">
                                <span class="text-feed-azure">#{{ $bounty->tags[0] ?? '' }}</span> · {{ $bounty->posted_at?->diffForHumans() }}
                            </p>
                        </a>
                    @else
                        <button
                            type="button"
                            wire:click="$set('sort', 'unanswered')"
                            class="group w-full feed-inset is-action p-3 text-left"
                        >
                            <div class="flex items-center justify-between">
                                <span class="feed-chip is-attention">{{ __('Unanswered') }}</span>
                                <span class="feed-count">↑ {{ $bounty->formattedScore() }}</span>
                            </div>
                            <p class="mt-2 line-clamp-2 text-xs font-semibold leading-relaxed text-feed-ink">{{ $bounty->title }}</p>
                            <p class="mt-2 font-mono text-[10px] text-feed-ink-3">
                                <span class="text-feed-azure">#{{ $bounty->tags[0] ?? '' }}</span> · {{ $bounty->posted_at?->diffForHumans() }}
                            </p>
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    {{-- ===== Featured projects — the marketplace front page ================= --}}
    <div class="feed-card p-4" aria-labelledby="featured-heading">
        <div class="flex items-center justify-between">
            <h3 id="featured-heading" class="feed-eyebrow font-semibold">{{ __('Featured projects') }}</h3>
            <a href="{{ route('marketplace.index') }}" wire:navigate class="feed-ghost py-0.5! text-[10px]! uppercase tracking-wider">{{ __('View all') }}</a>
        </div>
        <ul class="mt-3 space-y-2.5">
            @forelse ($featuredProducts as $product)
                <li wire:key="featured-{{ $product->id }}">
                    <a
                        href="{{ route('marketplace.show', ['product' => $product->slug]) }}"
                        wire:navigate
                        class="group block feed-inset is-action p-2.5"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-xs font-semibold text-feed-ink">{{ $product->name }}</span>
                            <span class="shrink-0 feed-count">↓ {{ number_format($product->download_count) }}</span>
                        </div>
                        <p class="mt-1 line-clamp-2 text-[11px] leading-relaxed text-feed-ink-2">{{ $product->tagline }}</p>
                        <p class="mt-1 font-mono text-[10px] text-feed-azure">v{{ $product->version }} · {{ $product->formattedPrice() }}</p>
                    </a>
                </li>
            @empty
                <li class="feed-inset p-3 text-center" data-test="featured-empty">
                    <p class="text-[11px] font-semibold text-feed-ink-2">{{ __('No featured products yet') }}</p>
                    <p class="mt-1 text-[10px] leading-relaxed text-feed-ink-3">
                        {{ __('The front page fills from published, featured marketplace listings.') }}
                    </p>
                </li>
            @endforelse
        </ul>
    </div>

    {{-- ===== Communities to join: the same live toggle the directory owns === --}}
    {{-- The rail owns the full directory; this widget shows the busiest six
         so the contextual pane stays a summary, not a second directory. --}}
    @php
        $isGuest = auth()->user() === null;
        $joinable = array_slice($communities, 0, 6);
    @endphp
    <div class="feed-card p-4" aria-labelledby="circles-heading">
        <h3 id="circles-heading" class="feed-eyebrow font-semibold">{{ __('Communities to join') }}</h3>
        <ul class="mt-3 space-y-3">
            @forelse ($joinable as $circle)
                @php
                    $inviteOnly = $circle['invite_only'] ?? false;
                    $refused = $refusedSlug !== null && $refusedSlug === $circle['slug'];
                @endphp
                <li
                    @class([
                        'feed-inset is-action p-2.5',
                        'ring-1 ring-feed-error' => $refused,
                    ])
                    wire:key="circle-{{ $circle['slug'] }}"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold text-feed-ink">{{ $circle['label'] }}</p>
                            <p class="font-mono text-[10px] text-feed-ink-3">
                                {{ $circle['members'] }} {{ __('members') }}@if ($inviteOnly && ! $circle['joined']) · {{ __('invite only') }}@endif
                            </p>
                        </div>
                        @if ($circle['joined'])
                            <button
                                type="button"
                                wire:click="toggleMembership('{{ $circle['slug'] }}')"
                                class="feed-chip shrink-0 transition-colors hover:text-feed-ink"
                                aria-label="{{ __('Leave :community', ['community' => $circle['label']]) }}"
                            >✓ {{ __('Joined') }}</button>
                        @elseif ($isGuest)
                            <a href="{{ route('login') }}" x-on:click="$flux.modal('login').show(); $event.preventDefault()" class="feed-action shrink-0 px-3! py-1! text-[11px]!">{{ __('Sign in to join') }}</a>
                        @else
                            <button
                                type="button"
                                wire:click="toggleMembership('{{ $circle['slug'] }}')"
                                wire:loading.attr="disabled"
                                class="feed-action shrink-0 px-3! py-1! text-[11px]!"
                                aria-label="{{ __('Join :community', ['community' => $circle['label']]) }}"
                            >{{ __('Join') }}</button>
                        @endif
                    </div>
                    @if ($refused)
                        <p class="mt-2 font-mono text-[10px] uppercase tracking-wider text-feed-error" data-test="membership-refused">
                            {{ __('Invite only — membership is by invitation from the owner.') }}
                        </p>
                    @endif
                </li>
            @empty
                {{-- An empty directory is a real state, not a broken widget:
                     the card still explains where the communities live. --}}
                <li class="feed-inset p-2.5">
                    <p class="text-xs text-feed-ink-2">{{ __('No communities have been opened yet.') }}</p>
                    <a href="{{ route('communities.index') }}" wire:navigate class="feed-ghost mt-2 inline-flex px-3! py-1! text-[11px]!">
                        {{ __('Browse the directory') }}
                    </a>
                </li>
            @endforelse
        </ul>
    </div>

    {{-- ===== Corpus telemetry: every figure derived from the passed collection --}}
    {{-- never a hardcoded demo number.                                    --}}
    <div class="feed-card p-4" aria-labelledby="reader-heading">
        <h3 id="reader-heading" class="feed-eyebrow is-accent font-semibold">{{ __('Corpus telemetry') }}</h3>
        <div class="mt-3 grid grid-cols-2 gap-2">
            <div class="feed-inset p-2.5">
                <p class="feed-eyebrow">{{ __('Answer rate') }}</p>
                <p class="mt-0.5 feed-stat-value text-feed-ink">{{ $questionCount > 0 ? (int) round(($solvedCount / $questionCount) * 100) : 0 }}%</p>
                <p class="font-mono text-[10px] text-feed-ink-3">{{ __('of :n questions', ['n' => $questionCount]) }}</p>
            </div>
            <div class="feed-inset p-2.5">
                <p class="feed-eyebrow">{{ __('Busiest week') }}</p>
                <p class="mt-0.5 feed-stat-value text-feed-mint">{{ $peakWeek }}</p>
                <p class="font-mono text-[10px] text-feed-ink-3">{{ __('posts at the high-water mark') }}</p>
            </div>
        </div>
        <p class="mt-3 feed-eyebrow">{{ __('Corpus activity') }} · {{ __('last 12 weeks') }}</p>
        <div class="feed-signal mt-1.5" aria-hidden="true">
            @foreach (range(11, 0) as $week)
                <span class="feed-signal-bar" style="height: {{ $weeklyActivity->has($week) ? 15 + 85 * ($weeklyActivity[$week] / $peakWeek) : 3 }}%; opacity: {{ $weeklyActivity->has($week) ? 1 : 0.3 }};"></span>
            @endforeach
        </div>
        <p class="sr-only">{{ __('Corpus activity over the last 12 weeks.') }}</p>
    </div>

    {{-- ===== Top contributors =============================================== --}}
    <div class="feed-card p-4" aria-labelledby="people-heading">
        <h3 id="people-heading" class="feed-eyebrow font-semibold">{{ __('Top contributors') }}</h3>
        <ol class="mt-3 space-y-3">
            @foreach ($contributors as $index => $person)
                <li class="flex items-center gap-3" wire:key="person-{{ $person['handle'] }}">
                    <span class="feed-count">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    <x-feed.avatar :initials="$person['avatar']" size="sm" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-semibold text-feed-ink">{{ $person['author'] }}</p>
                        <p class="font-mono text-[10px] text-feed-ink-3">{{ $person['posts'] }} {{ __('posts') }}</p>
                    </div>
                    <span class="font-mono text-[10px] tabular-nums text-feed-mint">↑ {{ number_format($person['score']) }}</span>
                </li>
            @endforeach
        </ol>
    </div>

    {{-- ===== House guidelines =============================================== --}}
    <div class="feed-card p-4" aria-labelledby="rules-heading">
        <h3 id="rules-heading" class="feed-eyebrow font-semibold">{{ __('House guidelines') }}</h3>
        <ul class="mt-3 space-y-2.5 text-xs leading-relaxed text-feed-ink-2">
            <li class="flex gap-2"><span class="font-mono text-[10px] text-feed-mint">01</span>{{ __('Questions get a clear title; the answer earns the solved mark.') }}</li>
            <li class="flex gap-2"><span class="font-mono text-[10px] text-feed-mint">02</span>{{ __('Show your work — screenshots, snippets or numbers beat slogans.') }}</li>
            <li class="flex gap-2"><span class="font-mono text-[10px] text-feed-mint">03</span>{{ __('Disagree with the idea, never the person.') }}</li>
        </ul>
    </div>
</aside>
