<?php

use App\Actions\Communities\ToggleCommunityMembership;
use App\Enums\CommunityKind;
use App\Models\Community;
use App\Models\FeedPost;
use App\Models\MarketplaceProduct;
use App\Models\User;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Validation\UnauthorizedException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The community feed — social + forum hybrid.
 */
new #[Title('Feed')] class extends Component
{
    /** Cards shown per page; Load more raises it. */
    private const PAGE_SIZE = 3;

    /** Renderable content types; `all` is the unfiltered view. */
    private const TYPES = ['all', 'question', 'discussion', 'showcase', 'snippet', 'article', 'poll'];

    /** Orderings, in the order the rail and the segment render them. */
    private const SORTS = ['for-you', 'following', 'latest', 'trending', 'unanswered', 'solved'];

    /** The canonical topic rail, in corpus order. */
    private const TOPIC_LABELS = [
        'backend' => 'Backend',
        'frontend' => 'Frontend',
        'tooling' => 'Tooling',
        'community' => 'Community',
    ];

    /** Active topic slug; null = all topics. Bound to `?category=`. */
    #[Url]
    public ?string $category = null;

    /** Feed ordering: for-you | following | latest | trending | unanswered. Bound to `?sort=`. */
    #[Url]
    public string $sort = 'for-you';

    /** Content-type filter. Bound to `?type=`. */
    #[Url]
    public string $type = 'all';

    /** Full-text filter across author, body, title and tags. Bound to `?q=`. */
    #[Url]
    public string $q = '';

    /** Tag filter; null = every tag. Bound to `?tag=`. */
    #[Url]
    public ?string $tag = null;

    /** How many filtered cards the feed currently renders. */
    public int $visible = self::PAGE_SIZE;

    /**
     * The newest post id the stream has already shown.
     *
     * The live feed's cursor: anything above this arrived after the page was
     * opened and is announced by the pill instead of being inserted under the
     * reader's scroll position.
     */
    public int $seenThroughId = 0;

    /** Posts waiting behind the "new posts" pill. */
    public int $pendingNew = 0;

    /**
     * Ids revealed by the last "show new posts" click, so their cards can
     * animate in once instead of appearing mid-morph.
     *
     * @var array<int, int>
     */
    public array $freshIds = [];

    /** The community whose invite-only gate refused the last join attempt. */
    public ?string $refusedSlug = null;

    public function mount(): void
    {
        // The head partial reads its metadata from the per-request Seo
        // singleton; the #[Title] attribute alone never reaches it.
        app(Seo::class)->set([
            'title' => 'Community Feed',
            'description' => __('Compare notes with the community: discussions, diffs and answers across the stack.'),
        ]);

        if ($this->category !== null && ! in_array($this->category, array_keys(self::TOPIC_LABELS), true)) {
            $this->category = null;
        }

        if (! in_array($this->sort, self::SORTS, true)) {
            $this->sort = 'for-you';
        }

        if (! in_array($this->type, self::TYPES, true)) {
            $this->type = 'all';
        }

        // A crafted tag degrades to the untagged view, exactly like the other
        // URL-bound filters: the tag must exist in the corpus to mean anything.
        if ($this->tag !== null && ! collect($this->trendingTags)->contains(fn (array $trend): bool => $trend['tag'] === $this->tag)) {
            $this->tag = null;
        }

        // The stream starts "caught up": everything already in the corpus is
        // visible, and only posts arriving from here on are held back. Reading
        // the cursor off the memoised collection keeps the page at its one
        // corpus query — a separate MAX(id) would be a second round trip for
        // the same rows.
        $this->seenThroughId = (int) $this->posts->max('id');
    }

    public function syncFeed(): void
    {
        $latest = (int) FeedPost::query()->visible()->max('id');

        if ($latest <= $this->seenThroughId) {
            $this->pendingNew = 0;

            return;
        }

        // Count only what the current view would actually show: a pill that
        // announces posts the active filter excludes would empty itself when
        // clicked. `posts()` is memoised, so this is an in-memory pass.
        $this->pendingNew = $this->posts
            ->filter(fn (FeedPost $post): bool => $post->id > $this->seenThroughId && $this->matchesFilters($post))
            ->count();
    }

    /**
     * Fold the announced posts into the stream, once, with an entry animation.
     */
    public function showNewPosts(): void
    {
        $fresh = FeedPost::query()
            ->visible()
            ->where('id', '>', $this->seenThroughId)
            ->pluck('id')
            ->all();

        $this->freshIds = array_map(intval(...), $fresh);
        $this->seenThroughId = (int) FeedPost::query()->visible()->max('id');
        $this->pendingNew = 0;

        // Reveal every arrived post on top of what was already rendered, so
        // the click never hides cards the reader was looking at.
        $this->visible += count($this->freshIds);
    }

    /**
     * A post the reader just published joins the stream at once.
     *
     * It is not held behind the live pill: the author pressed Publish, so the
     * card appearing at the top of the stream is the confirmation. Every
     * memoised widget is dropped rather than patched, because the new row can
     * change the topic counts, the trending tags, the bounty card and the pulse
     * total all at once — the corpus re-reads in the single query `posts()`
     * already costs, which is the same fold the pill's own reveal uses.
     */
    #[On('feed-post-published')]
    public function onPostPublished(int $id): void
    {
        unset(
            $this->posts,
            $this->topics,
            $this->filteredPosts,
            $this->visiblePosts,
            $this->hasMore,
            $this->bounties,
            $this->joinedCommunityIds,
            $this->followingTopics,
            $this->typeCounts,
            $this->trendingTags,
            $this->contributors,
        );

        $this->freshIds = [$id];
        $this->seenThroughId = max($this->seenThroughId, $id);
        $this->pendingNew = 0;
        $this->visible += 1;
    }

    /**
     * Any filter change restarts paging from the top of the new set. The five
     * URL-bound filters share one body, so the rule lives in a single place.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['category', 'sort', 'q', 'type', 'tag'], true)) {
            $this->visible = self::PAGE_SIZE;
        }
    }

    public function loadMore(): void
    {
        $this->visible += self::PAGE_SIZE;
    }

    /** Reset every filter at once; used by the empty state. */
    public function clearFilters(): void
    {
        $this->category = null;
        $this->sort = 'for-you';
        $this->type = 'all';
        $this->tag = null;
        $this->q = '';
        $this->visible = self::PAGE_SIZE;
    }

    /**
     * Every visible post, in curated order — the one query the page runs.
     *
     * Curated order (pinned, then featured, then newest) is the `for-you`
     * ordering; the rail and every widget derive from this collection, so a
     * render costs one SQL statement no matter how many widgets paint.
     *
     * @return Collection<int, FeedPost>
     */
    #[Computed]
    public function posts(): Collection
    {
        // `with('attachments')` is the one query the composer's published media
        // costs the stream. It replaces a query rather than adding one: the
        // bounty card used to run its own, and it now reads this collection.
        return FeedPost::query()->visible()->with('attachments')->curated()->get();
    }

    /**
     * Feed topics with live counts, plus the totals the chrome needs.
     *
     * @return array{items: array<int, array{slug: string, label: string, count: int}>, total: int, unansweredCount: int, questionCount: int}
     */
    #[Computed]
    public function topics(): array
    {
        $counts = [];
        $unanswered = 0;

        foreach ($this->posts as $post) {
            $counts[$post->topic] = ($counts[$post->topic] ?? 0) + 1;

            if ($post->isQuestion() && ! $post->answered) {
                $unanswered++;
            }
        }

        $items = array_map(function (string $slug, string $label) use ($counts): array {
            return ['slug' => $slug, 'label' => $label, 'count' => $counts[$slug] ?? 0];
        }, array_keys(self::TOPIC_LABELS), self::TOPIC_LABELS);

        return [
            'items' => $items,
            'total' => $this->posts->count(),
            'unansweredCount' => $unanswered,
            'questionCount' => $this->posts->filter(fn ($post) => $post->isQuestion())->count(),
        ];
    }

    /**
     * The stream filtered by the URL-bound topic, search and ordering.
     *
     * Filters run in memory over the memoised collection rather than as extra
     * SQL: the rail needs the unfiltered set anyway, so a second query would
     * pay twice for the same ten rows on every keystroke.
     *
     * @return BaseCollection<int, FeedPost>
     */
    #[Computed]
    public function filteredPosts(): BaseCollection
    {
        // Posts that arrived after the page opened wait behind the pill. They
        // are still counted by the masthead and the pulse card — the stream is
        // the one surface that must not move under the reader.
        $posts = $this->posts
            ->filter(fn (FeedPost $post): bool => $post->id <= $this->seenThroughId && $this->matchesFilters($post))
            ->values();

        // Curated order (pinned, then featured, then newest) already holds
        // for `for-you` and degrades to the same order for `latest`.
        if ($this->sort === 'following') {
            // The following view is the content of the circles the reader
            // actually joined — their topics, in the same curated order.
            // Guests and non-members get the honest empty state, not a
            // hardcoded list of demo handles.
            $topics = $this->followingTopics;
            $posts = $posts->filter(fn (FeedPost $post): bool => in_array($post->topic, $topics, true))->values();
        } elseif ($this->sort === 'trending') {
            $posts = $posts->sortByDesc(fn (FeedPost $post): int => $post->reactionSum())->values();
        } elseif ($this->sort === 'unanswered') {
            $posts = $posts->filter(fn (FeedPost $post): bool => $post->isQuestion() && ! $post->answered)->values();
        } elseif ($this->sort === 'solved') {
            $posts = $posts->filter(fn (FeedPost $post): bool => $post->isQuestion() && $post->answered)->values();
        }

        return $posts;
    }

    /**
     * The topic / type / search / audience predicate, shared by the stream and
     * the live poll so the pill and the view can never disagree about what is
     * new.
     *
     * Audience is decided here rather than in SQL on purpose. `visible()` is the
     * corpus-wide definition of publicly readable and every widget derives from
     * that one collection; pushing a per-reader predicate into it would give the
     * rail, the pulse counts and the stream three different answers to "how many
     * posts are there". The reader's memberships are already in memory.
     */
    private function matchesFilters(FeedPost $post): bool
    {
        if (! $post->isVisibleTo($this->reader, $this->joinedCommunityIds)) {
            return false;
        }
        if ($this->category !== null && $post->topic !== $this->category) {
            return false;
        }

        if ($this->type !== 'all' && $post->type !== $this->type) {
            return false;
        }

        if ($this->tag !== null && ! in_array($this->tag, $post->tags, true)) {
            return false;
        }

        $needle = mb_strtolower(trim($this->q));

        if ($needle === '') {
            return true;
        }

        return str_contains(
            mb_strtolower($post->title.' '.$post->body.' '.$post->author.' '.implode(' ', $post->tags)),
            $needle,
        );
    }

    /**
     * The slice of filtered posts the feed currently renders.
     *
     * @return BaseCollection<int, FeedPost>
     */
    #[Computed]
    public function visiblePosts(): BaseCollection
    {
        return $this->filteredPosts->take($this->visible);
    }

    /** How many cards are rendered versus how many match, for the count line. */
    #[Computed]
    public function hasMore(): bool
    {
        return $this->filteredPosts->count() > $this->visible;
    }

    /**
     * Questions still waiting on an answer, hottest first.
     *
     * An in-memory projection over the memoised corpus, not a second query.
     * This is provably the same set the old `visible()->unanswered()` builder
     * returned: `posts()` is the unfiltered visible corpus, and "is a question
     * and is unanswered" — the `unanswered()` scope — is a subset of its
     * conditions. Dropping the round trip here is what pays for the attachment
     * eager load, so the page's pinned four-query budget is unchanged.
     *
     * @return Collection<int, FeedPost>
     */
    #[Computed]
    public function bounties(): Collection
    {
        return $this->posts
            ->filter(fn (FeedPost $post): bool => $post->isQuestion() && ! $post->answered)
            ->sortByDesc('score')
            ->take(2)
            ->values();
    }

    /**
     * The community ids the signed-in reader belongs to.
     *
     * Read off the memoised directory rather than the pivot: `communities()`
     * already eager-loads this reader's membership rows, so answering "did they
     * join?" for a post's audience costs nothing it was not already paying for.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function joinedCommunityIds(): array
    {
        return array_column(
            array_filter($this->communities, fn (array $community): bool => $community['joined']),
            'id',
        );
    }

    /**
     * The topics of the communities the signed-in reader joined — what the
     * `following` sort renders. Empty for guests and non-members.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function followingTopics(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        return Community::query()
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->id))
            ->pluck('topic')
            ->all();
    }

    /**
     * The whole community directory, busiest first, with the reader's state.
     *
     * Real directory rows, not curated demo entries: member counts derive
     * from the pivot rows with `withCount` (a counter column cannot drift),
     * and `joined` reflects the signed-in account's own membership.
     *
     * The list is NOT limited: the rail wants six rows and the inspector a
     * bounded sample, but the "All communities" row wants the true total,
     * and a second `count()` query for that number would be the fifth round
     * trip the page's budget does not have. Consumers slice.
     *
     * @return array<int, array{slug: string, label: string, topic: string, glyph: string, members: int, joined: bool, invite_only: bool}>
     */
    #[Computed]
    public function communities(): array
    {
        $user = auth()->user();

        return Community::query()
            ->withCount('memberships')
            ->when($user !== null, fn ($query) => $query->with(['memberships' => fn ($member) => $member->where('user_id', $user->id)]))
            ->orderByDesc('memberships_count')
            ->orderBy('name')
            ->get()
            ->map(fn (Community $community): array => [
                'id' => $community->id,
                'slug' => $community->slug,
                'label' => $community->name,
                'topic' => $community->topic,
                'glyph' => $community->glyph,
                'members' => $community->memberships_count,
                // Never touch the relation when it was not loaded: a guest's
                // render would lazy-load one query per community.
                'joined' => $user !== null && $community->memberships->isNotEmpty(),
                'invite_only' => $community->kind === CommunityKind::InviteOnly,
            ])
            ->all();
    }

    /**
     * How many communities the directory holds — the rail's "All
     * communities" row count and the section header's badge.
     *
     * Derived from the memoised directory collection rather than a second
     * query: the page's four-query budget has no room for a fifth, and the
     * rows it counts are already in memory.
     */
    #[Computed]
    public function communityCount(): int
    {
        return count($this->communities);
    }

    /**
     * Live counts for the forum section of the navigation rail.
     *
     * Every figure is a pass over the memoised corpus — no query, and no
     * number the corpus cannot back. Each renderable type is counted, plus
     * the `solved` pseudo-count (answered questions) the forum rows show.
     *
     * @return array{question: int, discussion: int, article: int, snippet: int, showcase: int, poll: int, solved: int}
     */
    #[Computed]
    public function typeCounts(): array
    {
        $counts = ['question' => 0, 'discussion' => 0, 'article' => 0, 'snippet' => 0, 'showcase' => 0, 'poll' => 0, 'solved' => 0];

        foreach ($this->posts as $post) {
            if (isset($counts[$post->type])) {
                $counts[$post->type]++;
            }

            if ($post->isQuestion() && $post->answered) {
                $counts['solved']++;
            }
        }

        return $counts;
    }

    /**
     * The marketplace's front page, for the inspector's Featured projects.
     *
     * The documented ranking rule is the platform's own: featured first, then
     * most recently published. An empty directory renders an empty state, not
     * invented listings.
     *
     * @return Collection<int, MarketplaceProduct>
     */
    #[Computed]
    public function featuredProducts(): Collection
    {
        return MarketplaceProduct::query()
            ->published()
            ->featured()
            ->latest('published_at')
            ->limit(2)
            ->get();
    }

    /**
     * Join or leave a community as the signed-in reader — the same toggle the
     * directory page uses, so both surfaces agree on what membership means.
     */
    public function toggleMembership(string $slug): void
    {
        $user = auth()->user();

        if ($user === null) {
            // Stay on the feed with the login dialog open rather than walking
            // the reader to a separate screen mid-scroll. The flag is what the
            // dialog reads to open itself; the route's own guard still refuses
            // a guest who reaches the destination any other way.
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        $community = Community::query()->where('slug', $slug)->first();

        if ($community === null) {
            return;
        }

        try {
            app(ToggleCommunityMembership::class)->handle($user, $community);
        } catch (UnauthorizedException) {
            // Visible refusal at the widget, never a hidden button.
            $this->refusedSlug = $slug;

            return;
        }

        $this->refusedSlug = null;

        // Drop both memoised properties so member counts and the `following`
        // sort re-read the pivot state instead of serving the pre-toggle set.
        unset($this->communities, $this->followingTopics);
    }

    /** The signed-in reader, or null when the feed is browsed anonymously. */
    #[Computed]
    public function reader(): ?User
    {
        /** @var User|null $reader */
        $reader = auth()->user();

        return $reader;
    }

    /** Up to two initials for the reader's avatar fallback. */
    #[Computed]
    public function readerInitials(): string
    {
        $name = trim((string) $this->reader?->name);

        return collect(preg_split('/\s+/', $name) ?: [])
            ->filter()
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');
    }

    /**
     * Tag frequencies across the whole corpus, hottest first.
     *
     * @return array<int, array{tag: string, count: int}>
     */
    #[Computed]
    public function trendingTags(): array
    {
        $counts = [];
        foreach ($this->posts as $post) {
            foreach ($post->tags as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        arsort($counts);

        return array_map(
            fn (string $tag, int $count): array => ['tag' => $tag, 'count' => $count],
            array_keys($counts),
            $counts,
        );
    }

    /**
     * Contributor leaderboard: cards published and score earned, top four.
     *
     * @return array<int, array{author: string, handle: string, avatar: string, posts: int, score: int}>
     */
    #[Computed]
    public function contributors(): array
    {
        $totals = [];
        foreach ($this->posts as $post) {
            if (! isset($totals[$post->handle])) {
                $totals[$post->handle] = [
                    'author' => $post->author, 'handle' => $post->handle,
                    'avatar' => $post->avatar, 'posts' => 0, 'score' => 0,
                ];
            }
            $totals[$post->handle]['posts']++;
            $totals[$post->handle]['score'] += $post->score;
        }

        usort($totals, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($totals, 0, 4);
    }
}; ?>

{{-- The feed surface. It renders inside the public guest shell
     (`layouts::guest.home`), so the shared header, footer and auth dialogs
     are the site's own; every feed filter is URL-bound state on this
     component, and the 15s poll re-renders the stream in place.

     The canvas is the feed module's own surface, drawn here rather than by
     the layout: the shell body is the site's white, and the module's cards,
     wells and raised panels are all mixed against the canvas. `4rem` is the
     shell header's height, so the fill reaches the footer. --}}
<div
    class="min-h-[calc(100vh-4rem)] w-full bg-feed-canvas"
    data-test="feed-index"
    wire:poll.15s="syncFeed"
    x-data="{ folding: false }"
    x-init="setInterval(() => { if (! folding && window.scrollY < 80 && $wire.pendingNew > 0) { folding = true; $wire.showNewPosts().finally(() => folding = false) } }, 1200)"
    x-on:scroll.window.throttle.300ms="if (! folding && window.scrollY < 80 && $wire.pendingNew > 0) { folding = true; $wire.showNewPosts().finally(() => folding = false) }"
>
    {{-- ================================================================== --}}
    {{-- The broadcast band — the page's one hero moment.                     --}}
    {{--                                                                       --}}
    {{-- The band states the platform's promise at the largest scale the       --}}
    {{-- layout allows, then proves it with the corpus' own numbers: posts,    --}}
    {{-- people, questions solved, questions still waiting. Every figure is    --}}
    {{-- derived from the collections the stream already holds — no query is   --}}
    {{-- spent here and no number is a screenshot.                             --}}
    {{--                                                                       --}}
    {{-- The right-hand panel is the instrument: the live topic meters. It is   --}}
    {{-- always dark (a lamp, not wallpaper — docs/themes.md §7) so it reads   --}}
    {{-- the same in both themes, and its bars ride the accent so it follows   --}}
    {{-- the visitor's chosen signal.                                          --}}
    {{-- ================================================================== --}}
    @php
        $coverInitials = $this->readerInitials !== ''
            ? $this->readerInitials
            : collect(preg_split('/\s+/', (string) config('portfolio.author.short_name', config('app.name'))) ?: [])
                ->filter()
                ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                ->take(2)
                ->implode('');
        $coverName = $this->reader?->name ?? config('portfolio.author.name');
        $coverRole = $this->reader?->role->label();
        $coverSince = $this->reader?->created_at?->translatedFormat('F Y');
        $coverVerified = $this->reader !== null && $this->reader->email_verified_at !== null;

        // The readout: four figures, all derived from what the page already
        // loaded. No query, no screenshot, no number the corpus cannot back.
        $heroPosts = $this->posts->count();
        $heroPeople = $this->posts->pluck('handle')->unique()->count();
        $heroSolved = $this->typeCounts['solved'];
        $heroOpen = $this->topics['unansweredCount'];
        $heroMeters = $this->topics['items'];
        $heroMeterMax = max(1, max(array_column($heroMeters, 'count')));
        $heroTicker = array_slice($this->trendingTags, 0, 8);

        // The corpus by type — the same six types the composer offers, with
        // the real counts. Read from the memoised typeCounts, no extra query.
        $heroTypes = collect([
            'question' => __('Question'),
            'discussion' => __('Discussion'),
            'showcase' => __('Showcase'),
            'snippet' => __('Snippet'),
            'article' => __('Article'),
            'poll' => __('Poll'),
        ])
            ->map(fn (string $label, string $key): array => [
                'label' => $label,
                'count' => $this->typeCounts[$key] ?? 0,
            ])
            ->values()
            ->all();
    @endphp
    <section class="feed-hero" aria-labelledby="feed-cover-heading">
        <div class="mx-auto w-full max-w-[1920px] px-4 pt-7 sm:px-6 xl:px-8">
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_21rem] xl:grid-cols-[minmax(0,1fr)_25rem]">
                {{-- The lead: promise, proof, and who is reading. --}}
                <div class="min-w-0">
                    <div class="feed-rise feed-rise-1 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                        <p class="feed-hero-eyebrow">
                            <span class="feed-live-dot size-1.5 rounded-full bg-feed-mint"></span>
                            {{ __('Live feed · every 15s') }}
                        </p>

                        {{-- Identity: the reader's own account record; a guest
                             gets the platform's author identity, never a
                             fabricated member. --}}
                        <div class="flex min-w-0 items-center gap-2.5">
                            <x-feed.avatar :initials="$coverInitials" tone="mint" />
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-semibold text-feed-ink">{{ $coverName }}</p>
                                <p class="feed-meta truncate">
                                    @if ($this->reader !== null)
                                        {{ $coverRole ?? __('Member') }}@if ($coverSince) · {{ $coverSince }}@endif
                                    @else
                                        {{ config('portfolio.author.role') }}
                                    @endif
                                </p>
                            </div>
                            @if ($coverVerified)
                                <span class="feed-chip is-mint shrink-0" title="{{ __('Verified email address') }}">
                                    <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M4.5 12.75l6 6 9-13.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    {{ __('Verified') }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <h1
                        id="feed-cover-heading"
                        class="feed-hero-title feed-rise feed-rise-2 mt-6 max-w-[15ch] text-[clamp(2.15rem,5.6vw,4.5rem)]"
                    >
                        {{ __('Where the people who build things') }}
                        <span class="feed-hero-mark">{!! __('compare&nbsp;notes') !!}</span>.
                    </h1>

                    <p class="feed-hero-sub feed-rise feed-rise-3 mt-5">
                        {{ __('Wins worth shipping, questions worth solving and threads worth following — the rhythm of a social timeline, the structure of a forum.') }}
                    </p>

                    {{-- The readout: the promise, measured. --}}
                    <dl class="feed-hero-stats feed-rise feed-rise-4 mt-7">
                        <div class="feed-hero-stat">
                            <dt class="feed-hero-stat-label">{{ __('Posts live') }}</dt>
                            <dd class="feed-hero-stat-value">{{ $heroPosts }}</dd>
                        </div>
                        <div class="feed-hero-stat">
                            <dt class="feed-hero-stat-label">{{ __('People') }}</dt>
                            <dd class="feed-hero-stat-value">{{ $heroPeople }}</dd>
                        </div>
                        <div class="feed-hero-stat">
                            <dt class="feed-hero-stat-label">{{ __('Solved') }}</dt>
                            <dd class="feed-hero-stat-value text-feed-mint">{{ $heroSolved }}</dd>
                        </div>
                        <div class="feed-hero-stat">
                            <dt class="feed-hero-stat-label">{{ __('Need answers') }}</dt>
                            <dd class="feed-hero-stat-value">{{ $heroOpen }}</dd>
                        </div>
                    </dl>
                </div>

                {{-- The instrument: live topic meters. --}}
                <aside class="feed-hero-panel feed-rise feed-rise-4 hidden self-start lg:block" aria-label="{{ __('Live topic signal') }}">
                    <p class="feed-hero-panel-title">
                        <span>{{ __('Topic signal') }}</span>
                        <span class="text-signal-400">{{ __('live') }}</span>
                    </p>
                    <div class="mt-1">
                        @foreach ($heroMeters as $meter)
                            <div class="feed-meter">
                                <span class="truncate">{{ $meter['label'] }}</span>
                                <span class="tabular-nums">{{ $meter['count'] }}</span>
                                <span class="feed-meter-track" aria-hidden="true">
                                    <span class="feed-meter-fill" style="width: {{ (int) round($meter['count'] / $heroMeterMax * 100) }}%"></span>
                                </span>
                            </div>
                        @endforeach
                    </div>

                    {{-- The corpus by type: the same counts the composer offers,
                         so the reader sees what this surface is made of. --}}
                    <p class="mt-4 border-t border-ink-800 pt-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-500">
                        {{ __('Corpus by type') }}
                    </p>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5">
                        @foreach ($heroTypes as $heroType)
                            <div class="flex items-baseline justify-between gap-2">
                                <dt class="truncate text-[11px] text-ink-400">{{ $heroType['label'] }}</dt>
                                <dd class="tabular-nums text-[11px] font-semibold text-ink-200">{{ $heroType['count'] }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <p class="mt-4 border-t border-ink-800 pt-2.5 text-[11px] leading-relaxed text-ink-500">
                        {{ __('Every count is read from the corpus as you load it. Nothing on this page is a mock.') }}
                    </p>
                </aside>
            </div>

            {{-- Section tabs — only destinations that exist --}}
            <nav class="feed-hero-tabs mt-8 flex items-end gap-6 overflow-x-auto" aria-label="{{ __('Feed sections') }}">
                <a href="{{ route('feed.index') }}" wire:navigate class="feed-tab" aria-current="page">{{ __('Posts') }}</a>
                <a href="{{ route('communities.index') }}" wire:navigate class="feed-tab">{{ __('Communities') }}</a>
                <a href="{{ route('about') }}" wire:navigate class="feed-tab">{{ __('About') }}</a>
                <a href="{{ route('marketplace.index') }}" wire:navigate class="feed-tab">{{ __('Projects') }}</a>
                <a href="{{ route('blog.index') }}" wire:navigate class="feed-tab">{{ __('Writing') }}</a>
                <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                    <button
                        type="button"
                        class="feed-tab"
                        x-on:click="open = ! open"
                        x-bind:aria-expanded="open ? 'true' : 'false'"
                        aria-haspopup="menu"
                    >
                        {{ __('More') }}
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M19.5 8.25l-7.5 7.5-7.5-7.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div x-show="open" x-cloak x-on:click.outside="open = false" class="feed-menu" role="menu">
                        <a href="{{ route('ai-hub.index') }}" wire:navigate role="menuitem" class="feed-menu-item">{{ __('AI Knowledge Hub') }}</a>
                        <a href="{{ route('dev-lab.index') }}" wire:navigate role="menuitem" class="feed-menu-item">{{ __('Developer Lab') }}</a>
                        <a href="{{ route('ideas.index') }}" wire:navigate role="menuitem" class="feed-menu-item">{{ __('Idea Bank') }}</a>
                        <a href="{{ route('topics.index') }}" wire:navigate role="menuitem" class="feed-menu-item">{{ __('Browse by subject') }}</a>
                        @auth
                            <a href="{{ route('dashboard.index') }}" wire:navigate role="menuitem" class="feed-menu-item">{{ __('Dashboard') }}</a>
                        @endauth
                    </div>
                </div>
            </nav>
        </div>

        {{-- The ticker: what is moving right now. One continuous strip — the
             module's signature motion — carrying the live tag list and the
             destinations a community member actually uses. The second copy
             makes the loop seamless; it is inert, so it never takes focus or
             reaches the accessibility tree. --}}
        <div class="feed-ticker mt-6" aria-label="{{ __('What is moving right now') }}">
            <div class="feed-ticker-run">
                <ul class="feed-ticker-items">
                    @foreach ($heroTicker as $trend)
                        <li>
                            <a href="{{ route('feed.index', ['tag' => $trend['tag']]) }}" wire:navigate class="feed-ticker-item">
                                <span class="text-feed-azure">#</span>{{ $trend['tag'] }}
                                <span class="feed-count">{{ $trend['count'] }}</span>
                            </a>
                        </li>
                    @endforeach
                    <li><a href="{{ route('communities.index') }}" wire:navigate class="feed-ticker-item">{{ __('Communities') }}</a></li>
                    <li><a href="{{ route('ai-hub.index') }}" wire:navigate class="feed-ticker-item">{{ __('AI Knowledge Hub') }}</a></li>
                    <li><a href="{{ route('dev-lab.index') }}" wire:navigate class="feed-ticker-item">{{ __('Developer Lab') }}</a></li>
                    <li><a href="{{ route('marketplace.index') }}" wire:navigate class="feed-ticker-item">{{ __('Marketplace') }}</a></li>
                    <li><a href="{{ route('ideas.index') }}" wire:navigate class="feed-ticker-item">{{ __('Idea Bank') }}</a></li>
                </ul>
                <ul class="feed-ticker-items" inert aria-hidden="true">
                    @foreach ($heroTicker as $trend)
                        <li>
                            <a href="{{ route('feed.index', ['tag' => $trend['tag']]) }}" wire:navigate class="feed-ticker-item" tabindex="-1">
                                <span class="text-feed-azure">#</span>{{ $trend['tag'] }}
                                <span class="feed-count">{{ $trend['count'] }}</span>
                            </a>
                        </li>
                    @endforeach
                    <li><a href="{{ route('communities.index') }}" wire:navigate class="feed-ticker-item" tabindex="-1">{{ __('Communities') }}</a></li>
                    <li><a href="{{ route('ai-hub.index') }}" wire:navigate class="feed-ticker-item" tabindex="-1">{{ __('AI Knowledge Hub') }}</a></li>
                    <li><a href="{{ route('dev-lab.index') }}" wire:navigate class="feed-ticker-item" tabindex="-1">{{ __('Developer Lab') }}</a></li>
                    <li><a href="{{ route('marketplace.index') }}" wire:navigate class="feed-ticker-item" tabindex="-1">{{ __('Marketplace') }}</a></li>
                    <li><a href="{{ route('ideas.index') }}" wire:navigate class="feed-ticker-item" tabindex="-1">{{ __('Idea Bank') }}</a></li>
                </ul>
            </div>
        </div>
    </section>

    {{-- ================================================================== --}}
    {{-- Body — a three-column desktop grid: navigation, stream and the     --}}
    {{-- inspector. Below xl it folds to two columns, below lg to one, so   --}}
    {{-- breakpoint leaves a dead gutter.                                   --}}
    {{--                                                                        --}}
    {{-- On the single-column breakpoints the stream leads (order-1) and the --}}
    {{-- rails follow it: a phone opens on the feed, not on navigation.      --}}
    {{-- ================================================================== --}}
    <div class="mx-auto w-full max-w-[1920px] px-4 pb-14 pt-5 sm:px-6 xl:px-8">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem] lg:gap-6 xl:grid-cols-[14rem_minmax(0,1fr)_20rem]">
            <div class="order-2 lg:col-span-2 lg:order-none xl:col-span-1">
                <x-feed.nav-rail
                    :sort="$sort"
                    :category="$category"
                    :type="$type"
                    :topics="$this->topics"
                    :type-counts="$this->typeCounts"
                    :communities="$this->communities"
                    :community-count="$this->communityCount"
                />
            </div>

            <div class="order-1 min-w-0 lg:order-none">
                {{-- Sticky command bar. The live pill rides above the tabs so an
                     arriving post is announced without moving the stream. --}}
                <div class="feed-command-bar">
                    @if ($pendingNew > 0)
                        <button
                            type="button"
                            wire:click="showNewPosts"
                            class="feed-live-pill mb-3"
                            data-test="feed-live-pill"
                            aria-live="polite"
                        >
                            <span class="feed-live-dot size-1.5 rounded-full bg-feed-mint" aria-hidden="true"></span>
                            {{ $pendingNew }} {{ $pendingNew === 1 ? __('new post') : __('new posts') }}
                            <span class="font-normal text-feed-ink-2">— {{ __('show') }}</span>
                        </button>
                    @endif

                    <x-feed.filters
                        :sort="$sort"
                        :type="$type"
                        :category="$category"
                        :tag="$tag"
                        :topics="$this->topics"
                        :tags="$this->trendingTags"
                    />
                </div>

                <x-feed.composer :initials="$coverInitials" />

                {{-- One write surface for the whole feed. Guests never render it:
                     there is no composer for an account that cannot authorise
                     `create`, and mounting it for them would cost a component
                     they can only be refused by. --}}
                @auth
                    <livewire:pages::feed.composer-modal />
                @endauth

                <p class="feed-eyebrow mt-4 flex items-center justify-between" aria-live="polite">
                    <span>{{ $this->filteredPosts->count() }} {{ __('matching posts') }} · {{ __('showing') }} {{ $this->visiblePosts->count() }}</span>
                    @if ($pendingNew > 0)
                        <span class="text-feed-mint">{{ $pendingNew }} {{ __('waiting') }}</span>
                    @endif
                </p>

                {{-- Cards — one per row, the reference's timeline width: author,
                     body, artefact, tags, then the meta bar. The stream's own
                     column is the honest measure, so no container query folds
                     it into two. --}}
                <div class="mt-4 space-y-4" aria-live="polite">
                    @forelse ($this->visiblePosts as $post)
                        <x-feed.card :post="$post" :fresh="in_array($post->id, $freshIds, true)" :index="$loop->iteration" />
                    @empty
                        <div class="feed-empty feed-card">
                            <p class="font-mono text-[10px] uppercase tracking-wider text-feed-ink-3">{{ __('No matching posts') }}</p>
                            <p class="mt-3 max-w-sm text-sm leading-relaxed text-feed-ink-2">
                                {{ __('No posts match this topic, sort and search together. Loosen one filter and the feed comes back.') }}
                            </p>
                            <button type="button" wire:click="clearFilters" class="feed-ghost mt-6 border border-feed-line">
                                {{ __('Reset filters') }}
                            </button>
                        </div>
                    @endforelse
                </div>

                {{-- Load more --}}
                @if ($this->hasMore)
                    <div class="flex flex-col items-center gap-3 pt-6">
                        <button type="button" wire:click="loadMore" class="feed-ghost border border-feed-line px-5! py-2.5! text-sm! text-feed-ink!">
                            {{ __('Load more posts') }}
                        </button>
                        <p class="feed-eyebrow">
                            {{ $this->filteredPosts->count() - $this->visiblePosts->count() }} {{ __('more in this view') }}
                        </p>
                    </div>
                @endif
            </div>

            <div class="order-3 min-w-0 lg:order-none">
                <x-feed.inspector
                    :posts="$this->posts"
                    :trending-tags="$this->trendingTags"
                    :bounties="$this->bounties"
                    :contributors="$this->contributors"
                    :communities="$this->communities"
                    :featured-products="$this->featuredProducts"
                    :reader="$this->reader"
                    :reader-initials="$this->readerInitials"
                    :tag="$tag"
                    :refused-slug="$refusedSlug"
                />
            </div>

        </div>
    </div>
</div>
