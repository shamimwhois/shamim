<?php

use App\Concerns\HasCommitActions;
use App\Models\FeedPost;
use App\Models\User;
use App\Support\LogIdentity;
use App\Support\LogRow;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A profile — `/@{handle}`.
 *
 * The page a commit's byline points at: who this is, what they shipped, and how
 * much of it. Identity is the account's own (`users`), the byline is the
 * commit's snapshot, and the two are reconciled by handle, so a renamed account
 * still owns the commits it wrote under its current name.
 *
 * The activity strip is the design's "scaled to how long the user has been
 * visibly active" rule: thirty days of cells in the profile owner's lane, with
 * the busiest day setting the top of the scale.
 */
new #[Title('Profile')] #[Layout('layouts::feed')] class extends Component
{
    use HasCommitActions;

    /** The account this page is about. */
    public User $profile;

    /** The commit whose thread is expanded. */
    public ?int $threadId = null;

    /** The commit whose reply composer is open. */
    public ?int $replyTo = null;

    /** The reply being written. */
    public string $replyDraft = '';

    /** How many commits the page renders. */
    public int $visible = 12;

    public function mount(string $handle): void
    {
        $this->profile = User::query()
            ->whereRaw('lower(handle) = ?', [mb_strtolower($handle)])
            ->firstOrFail();

        app(Seo::class)->set([
            'title' => $this->profile->name.' (@'.LogIdentity::handleFor($this->profile).')',
            'description' => __('The commits :name shipped, newest first.', ['name' => $this->profile->name]),
        ]);
    }

    /**
     * The commits this account wrote, newest first.
     *
     * @return Collection<int, FeedPost>
     */
    #[Computed]
    public function commits(): Collection
    {
        return FeedPost::query()
            ->visible()
            ->where('user_id', $this->profile->id)
            ->withCount(['stars', 'replies'])
            ->with(['replies.user', 'attachments'])
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->get();
    }

    /** The slice the page renders. */
    #[Computed]
    public function rendered(): Collection
    {
        return $this->commits->take($this->visible)->values();
    }

    /** Whether more commits exist below the slice. */
    #[Computed]
    public function hasMore(): bool
    {
        return $this->commits->count() > $this->visible;
    }

    /**
     * The counts the header prints.
     *
     * @return array{commits: int, stars: int, replies: int}
     */
    #[Computed]
    public function totals(): array
    {
        return [
            'commits' => $this->commits->count(),
            'stars' => (int) $this->commits->sum(fn (FeedPost $post): int => (int) $post->getAttribute('stars_count')),
            'replies' => (int) $this->commits->sum(fn (FeedPost $post): int => (int) $post->getAttribute('replies_count')),
        ];
    }

    /**
     * Thirty days of activity, in the profile owner's lane.
     *
     * A cell's level is its commit count relative to the busiest day in the
     * window, in four steps — the design's 32/56/80/100% mix. Days are UTC
     * calendar days, which is also how the graph groups them.
     *
     * @return array<int, array{day: string, label: string, count: int, level: int}>
     */
    #[Computed]
    public function activity(): array
    {
        $counts = [];

        foreach ($this->commits as $post) {
            $day = ($post->posted_at ?? $post->created_at)?->utc()->format('Y-m-d');

            if ($day !== null) {
                $counts[$day] = ($counts[$day] ?? 0) + 1;
            }
        }

        $busiest = max(1, ...array_values($counts ?: [0]));
        $cells = [];

        for ($offset = 29; $offset >= 0; $offset--) {
            $date = Carbon::now()->utc()->startOfDay()->subDays($offset);
            $key = $date->format('Y-m-d');
            $count = $counts[$key] ?? 0;

            $cells[] = [
                'day' => $key,
                'label' => $date->format('D, M j'),
                'count' => $count,
                'level' => $count === 0 ? 0 : (int) max(1, ceil($count / $busiest * 4)),
            ];
        }

        return $cells;
    }

    /**
     * The rows, in the shared shape every rail uses.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        return LogRow::rows(
            $this->rendered,
            LogRow::starredLookup($this->commitReader()),
            $this->threadId,
            $this->replyTo,
            $this->commitReader(),
            // A profile's rows are not "fresh" in the live-poll sense, and the
            // visit line and the interlude belong to the log alone.
            [],
            null,
            null,
            // The commits this reader has already reported.
            $this->reportedCommitIds,
            // The votes cast on this install, by poll and by option.
            $this->pollVotes,
        );
    }

    /** Reveal the next page. */
    public function loadMore(): void
    {
        $this->visible += 12;
    }

    /** Forget the collections a write invalidated. */
    protected function commitStateChanged(): void
    {
        unset($this->commits, $this->rendered, $this->hasMore, $this->totals, $this->activity, $this->rows);
    }
}; ?>

@php
    $handle = \App\Support\LogIdentity::handleFor($this->profile);
    $lane = \App\Support\LogIdentity::lane($handle);
    $isSelf = auth()->id() === $this->profile->id;
@endphp

<x-log.frame
    active="profile"
    data-test="profile"
    x-on:log:star.window="$wire.toggleStar($event.detail.id)"
    x-on:log:reply.window="$wire.openReply($event.detail.id)"
    x-on:log:thread.window="$wire.toggleThread($event.detail.id)"
>
    <x-slot:header>
        <div class="dn-header">
            <div class="dn-page-head">
                <h1 class="dn-page-title">{{ $this->profile->name }}</h1>
                <p class="dn-page-meta">{{ '@'.$handle }}</p>
            </div>
        </div>
    </x-slot:header>

    <section class="dn-profile" style="--dn-lane: {{ $lane }}" aria-labelledby="profile-name">
        <div class="dn-profile-head">
            <span class="dn-profile-avatar" aria-hidden="true">
                {{ \App\Support\LogIdentity::initials($this->profile->name) }}
            </span>

            <div class="dn-profile-body">
                <h2 id="profile-name" class="dn-profile-name">{{ $this->profile->name }}</h2>
                <p class="dn-profile-handle">
                    <span class="dn-lane-dot" style="--dn-lane: {{ $lane }}"></span>
                    {{ '@'.$handle }}
                </p>

                <p class="dn-profile-meta">
                    <span>{{ $this->profile->accountRole()->label() }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ __('joined :date', ['date' => $this->profile->created_at?->format('F Y')]) }}</span>
                </p>

                <dl class="dn-profile-stats">
                    <div>
                        <dd>{{ $this->totals['commits'] }}</dd>
                        <dt>{{ trans_choice('commit|commits', $this->totals['commits']) }}</dt>
                    </div>
                    <div>
                        <dd>{{ $this->totals['stars'] }}</dd>
                        <dt>{{ trans_choice('star|stars', $this->totals['stars']) }}</dt>
                    </div>
                    <div>
                        <dd>{{ $this->totals['replies'] }}</dd>
                        <dt>{{ trans_choice('reply|replies', $this->totals['replies']) }}</dt>
                    </div>
                </dl>
            </div>

            @if ($isSelf)
                <a href="{{ route('profile.edit') }}" class="dn-btn dn-btn-outline dn-btn-sm" style="align-self: flex-start;">
                    {{ __('Edit profile') }}
                </a>
            @endif
        </div>

        <div class="dn-activity">
            <div class="dn-activity-head">
                <p class="dn-activity-total">
                    <strong>{{ $this->totals['commits'] }}</strong>
                    {{ trans_choice('commit|commits', $this->totals['commits']) }}
                    <span>{{ __('in the last 30 days') }}</span>
                </p>
            </div>

            <div class="dn-activity-strip" role="img" aria-label="{{ __('Commits per day for the last 30 days') }}">
                @foreach ($this->activity as $cell)
                    <span
                        class="dn-cell"
                        data-level="{{ $cell['level'] }}"
                        title="{{ trans_choice(':count commit on :day|:count commits on :day', $cell['count'], ['count' => $cell['count'], 'day' => $cell['label']]) }}"
                    ></span>
                @endforeach
            </div>

            <div class="dn-activity-axis">
                <span>{{ $this->activity[0]['label'] }}</span>
                <span>{{ __('today') }}</span>
            </div>
        </div>
    </section>

    @php $rows = $this->rows; @endphp

    @if ($rows === [])
        <div class="dn-empty" data-test="profile-empty">
            <p class="dn-empty-command">fatal: no commits yet</p>
            <p class="dn-empty-text">
                {{ $isSelf ? __('You have not committed anything yet.') : __(':name has not committed anything yet.', ['name' => $this->profile->name]) }}
                <a href="{{ route('feed.index') }}" wire:navigate>{{ __('The log is this way') }}</a>.
            </p>
        </div>
    @else
        <div class="dn-feed">
            @foreach ($rows as $row)
                @if ($loop->first || $row['day'] !== $rows[$loop->index - 1]['day'])
                    <div class="dn-marker">
                        <div class="dn-rail">
                            <span class="dn-rail-line is-down is-dashed"></span>
                        </div>
                        <div class="dn-marker-content">
                            <h3 class="dn-marker-label">{{ $row['day'] }}</h3>
                        </div>
                    </div>
                @endif

                @include('feed.commit', [
                    'commit' => $row,
                    'isLast' => $loop->last && ! $this->hasMore,
                    'maxReply' => 1000,
                ])
            @endforeach

            @if ($this->hasMore)
                <div class="dn-marker">
                    <div class="dn-rail">
                        <span class="dn-rail-line is-down" style="background: {{ $rows[count($rows) - 1]['lane'] }}"></span>
                    </div>
                    <div class="dn-marker-content" style="border-bottom: 0">
                        <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="loadMore">
                            {{ __('Load older commits') }}
                        </button>
                    </div>
                </div>
            @else
                <div class="dn-root">
                    <div class="dn-rail">
                        <span class="dn-rail-node" style="--dn-lane: {{ $rows[count($rows) - 1]['lane'] }}" aria-hidden="true">
                            <span class="dn-node is-root"></span>
                        </span>
                    </div>
                    <p class="dn-root-text">{{ __("That's the root commit. There's nothing before it.") }}</p>
                </div>
            @endif
        </div>
    @endif
</x-log.frame>
