<?php

use App\Concerns\HasCommitActions;
use App\Models\FeedPost;
use App\Models\FeedStar;
use App\Support\LogRow;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Starred — the commits a reader kept.
 *
 * A log like every other list of commits, ordered by when it was starred rather
 * than when it was written, because the star is the reason it is here. Guests
 * are sent to the log: a personal list has nothing to show them, and an empty
 * page pretending otherwise would be a lie.
 */
new #[Title('Starred')] #[Layout('layouts::feed')] class extends Component
{
    use HasCommitActions;

    /** How many commits are rendered before "load older". */
    private const PAGE_SIZE = 12;

    /** The commit whose thread is expanded. */
    public ?int $threadId = null;

    /** The commit whose reply composer is open. */
    public ?int $replyTo = null;

    /** The reply being written. */
    public string $replyDraft = '';

    /** How many commits the page renders. */
    public int $visible = self::PAGE_SIZE;

    public function mount(): void
    {
        if (auth()->guest()) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        app(Seo::class)->set([
            'title' => 'Starred',
            'description' => __('The posts you kept, newest star first.'),
        ]);
    }

    /**
     * The commits this reader starred, newest star first.
     *
     * One query joins the star to its commit so the order is the star's, and the
     * counts the row prints come with it.
     *
     * @return Collection<int, FeedPost>
     */
    #[Computed]
    public function commits(): Collection
    {
        $reader = $this->commitReader();

        if ($reader === null) {
            return new Collection;
        }

        $ids = FeedStar::query()
            ->where('user_id', $reader->id)
            ->orderByDesc('created_at')
            ->pluck('feed_post_id')
            ->all();

        if ($ids === []) {
            return new Collection;
        }

        return FeedPost::query()
            ->visible()
            ->whereIn('id', $ids)
            ->withCount(['stars', 'replies'])
            ->with(['replies.user', 'attachments'])
            ->get()
            ->sortBy(fn (FeedPost $post): int => array_search($post->id, $ids, true) ?: 0)
            ->values();
    }

    /** The slice the page renders. */
    #[Computed]
    public function rendered(): Collection
    {
        return $this->commits->take($this->visible)->values();
    }

    /** Whether more starred commits exist below the slice. */
    #[Computed]
    public function hasMore(): bool
    {
        return $this->commits->count() > $this->visible;
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
            // The starred list is not a live stream, so nothing is "fresh",
            // and the visit line and the interlude belong to the log alone.
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
        $this->visible += self::PAGE_SIZE;
    }

    /** Forget the collections a write invalidated. */
    protected function commitStateChanged(): void
    {
        unset($this->commits, $this->rendered, $this->hasMore, $this->rows);
    }
}; ?>

<x-log.frame
    active="starred"
    data-test="starred"
    x-on:log:star.window="$wire.toggleStar($event.detail.id)"
    x-on:log:reply.window="$wire.openReply($event.detail.id)"
    x-on:log:thread.window="$wire.toggleThread($event.detail.id)"
>
    <x-slot:header>
        <div class="dn-header">
            <div class="dn-page-head">
                <h1 class="dn-page-title">{{ __('Starred') }}</h1>
                <p class="dn-page-meta">
                    {{ trans_choice(':count post|:count posts', $this->commits->count(), ['count' => $this->commits->count()]) }}
                </p>
            </div>
        </div>
    </x-slot:header>

    @php $rows = $this->rows; @endphp

    @if ($rows === [])
        <div class="dn-empty" data-test="starred-empty">
            <p class="dn-empty-command">fatal: no commits are starred</p>
            <p class="dn-empty-text">
                {{ __('Star a post and it lands here. The star is the only bookmark this log has.') }}
                <a href="{{ route('feed.index') }}" wire:navigate>{{ __('Back to the log') }}</a>.
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
                    'maxEdit' => 4000,
                ])
            @endforeach

            @if ($this->hasMore)
                <div class="dn-marker">
                    <div class="dn-rail">
                        <span class="dn-rail-line is-down" style="background: {{ $rows[count($rows) - 1]['lane'] }}"></span>
                    </div>
                    <div class="dn-marker-content" style="border-bottom: 0">
                        <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="loadMore">
                            {{ __('Load older stars') }}
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
                    <p class="dn-root-text">{{ __("That's the oldest star. There's nothing before it.") }}</p>
                </div>
            @endif
        </div>
    @endif
</x-log.frame>
