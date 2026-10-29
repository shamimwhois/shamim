<?php

use App\Models\LogNotification;
use App\Support\LogIdentity;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The inbox — a log of things that happened to you.
 *
 * Every row hangs on the same rail as a commit, with a hollow node holding the
 * type's icon. The sentence is composed here rather than stored, so a row can
 * read correctly in the list, in a filter and in an accessible summary from one
 * type and one actor.
 *
 * Reading is a pull: the page marks what it showed as read, and remembers which
 * rows were new on this visit so the ring around their node survives until the
 * reader leaves. That is the whole unread model — no second table, no counts to
 * drift.
 */
new #[Title('Notifications')] #[Layout('layouts::feed')] class extends Component
{
    /** The active filter, bound to `?type=`. */
    #[Url(as: 'type')]
    public string $filter = 'all';

    /** The ids that were unread when this visit began. */
    public array $newIds = [];

    /** When this visit began, for the "new" comparison. */
    public string $visitStart = '';

    /** The filters, in tab order. */
    private const FILTERS = [
        'all' => 'All',
        'replies' => 'Replies',
        'stars' => 'Stars',
        'mentions' => 'Mentions',
        'follows' => 'Follows',
        'moderation' => 'Moderation',
    ];

    /** Which notification types each filter shows. */
    private const TYPES = [
        'all' => [LogNotification::Starred, LogNotification::Replied, LogNotification::Mentioned, LogNotification::Followed, LogNotification::Moderated],
        'replies' => [LogNotification::Replied],
        'stars' => [LogNotification::Starred],
        'mentions' => [LogNotification::Mentioned],
        'follows' => [LogNotification::Followed],
        'moderation' => [LogNotification::Moderated],
    ];

    public function mount(): void
    {
        if (auth()->guest()) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        app(Seo::class)->set([
            'title' => 'Notifications',
            'description' => __('Stars, replies and mentions on your commits.'),
        ]);

        if (! array_key_exists($this->filter, self::FILTERS)) {
            $this->filter = 'all';
        }

        $this->visitStart = now()->toIso8601String();

        // Snapshot the unread set before pulling, so the ring this visit draws
        // is decided by what was waiting, not by what the page just marked.
        $this->newIds = LogNotification::query()
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->deliverable()
            ->pluck('id')
            ->all();

        LogNotification::query()
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * The rows the current filter shows, newest first.
     *
     * @return Collection<int, LogNotification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        $types = self::TYPES[$this->filter] ?? self::TYPES['all'];

        return LogNotification::query()
            ->where('user_id', auth()->id())
            ->whereIn('type', $types)
            ->deliverable()
            ->with(['actor', 'post', 'reply'])
            ->latest()
            ->limit(100)
            ->get();
    }

    /**
     * The rows, grouped by the day they arrived.
     *
     * @return array<int, array{day: string, items: array<int, array<string, mixed>>}>
     */
    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach ($this->notifications as $notification) {
            $createdAt = $notification->created_at ?? now();
            $day = LogIdentity::day($createdAt);

            if (! isset($groups[$day])) {
                $groups[$day] = ['day' => $day, 'items' => []];
            }

            $groups[$day]['items'][] = $this->present($notification);
        }

        return array_values($groups);
    }

    /**
     * One notification as the rail needs it.
     *
     * @return array<string, mixed>
     */
    private function present(LogNotification $notification): array
    {
        $actor = $notification->actor;
        $post = $notification->post;
        $handle = LogIdentity::handleFor($actor);

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'icon' => match ($notification->type) {
                LogNotification::Starred => 'star',
                LogNotification::Replied => 'reply',
                LogNotification::Mentioned => 'bell',
                LogNotification::Followed => 'profile',
                default => 'check',
            },
            'isNew' => in_array($notification->id, $this->newIds, true),
            'actorName' => $actor?->name ?? __('Someone'),
            'actorHandle' => $handle,
            'actorInitials' => $actor !== null ? LogIdentity::initials($actor->name) : '??',
            'lane' => LogIdentity::lane($handle),
            'time' => LogIdentity::relative($notification->created_at),
            'iso' => ($notification->created_at ?? now())->toIso8601String(),
            'action' => $this->action($notification),
            'body' => $post?->body,
            'postTitle' => $post?->title,
            'postUrl' => $post !== null ? route('feed.show', ['slug' => $post->slug]) : null,
            'replyBody' => $notification->reply?->body,
        ];
    }

    /**
     * What the actor did, without their name.
     *
     * The name is rendered separately so it can be bold and linked; storing the
     * whole sentence and cutting the name out again is how translations break.
     */
    private function action(LogNotification $notification): string
    {
        return match ($notification->type) {
            LogNotification::Starred => __('starred your commit'),
            LogNotification::Replied => __('replied to your commit'),
            LogNotification::Mentioned => __('mentioned you'),
            LogNotification::Followed => __('started following you'),
            LogNotification::Moderated => __('reviewed your commit'),
            default => __('did something'),
        };
    }

    /** Switch filter without losing the scroll position. */
    public function setFilter(string $filter): void
    {
        if (array_key_exists($filter, self::FILTERS)) {
            $this->filter = $filter;
        }
    }

    /** The empty sentence a filter speaks when it has nothing. */
    #[Computed]
    public function emptyCommand(): string
    {
        return match ($this->filter) {
            'replies' => 'fatal: no replies yet',
            'stars' => 'fatal: no stars yet',
            'mentions' => 'fatal: no mentions yet',
            'follows' => 'fatal: no followers yet',
            'moderation' => 'fatal: nothing was moderated',
            default => 'fatal: your inbox is empty',
        };
    }
}; ?>

<x-log.frame active="notifications" data-test="notifications">
    <x-slot:header>
        <div class="dn-header">
            <div class="dn-page-head">
                <h1 class="dn-page-title">{{ __('Notifications') }}</h1>
                <p class="dn-page-meta">
                    {{ trans_choice(':count new|:count new', count($this->newIds), ['count' => count($this->newIds)]) }}
                </p>
            </div>

            <nav class="dn-filters" aria-label="{{ __('Notification filters') }}">
                @foreach (['all' => 'All', 'replies' => 'Replies', 'stars' => 'Stars', 'mentions' => 'Mentions', 'follows' => 'Follows', 'moderation' => 'Moderation'] as $key => $label)
                    <a
                        href="{{ route('notifications.index', ['type' => $key]) }}"
                        wire:navigate
                        class="dn-filter {{ $filter === $key ? 'is-active' : '' }}"
                        @if ($filter === $key) aria-current="page" @endif
                    >{{ __($label) }}</a>
                @endforeach
            </nav>
        </div>
    </x-slot:header>

    @php $groups = $this->groups; @endphp

    @if ($groups === [])
        <div class="dn-empty" data-test="notifications-empty">
            <p class="dn-empty-command">{{ $this->emptyCommand }}</p>
            <p class="dn-empty-text">
                @if ($filter === 'all')
                    {{ __('Stars, replies and mentions on your commits show up here.') }}
                @else
                    {{ __('Nothing has happened in this filter yet.') }}
                @endif
                <a href="{{ route('feed.index') }}" wire:navigate>{{ __('Back to the log') }}</a>.
            </p>
        </div>
    @else
        <div class="dn-feed">
            @foreach ($groups as $group)
                <div class="dn-marker">
                    <div class="dn-rail">
                        <span class="dn-rail-line is-down is-dashed"></span>
                    </div>
                    <div class="dn-marker-content">
                        <h2 class="dn-marker-label">{{ $group['day'] }}</h2>
                    </div>
                </div>

                @foreach ($group['items'] as $item)
                    <article
                        class="dn-note {{ $item['isNew'] ? 'is-new' : '' }}"
                        data-test="notification"
                        style="--dn-lane: {{ $item['lane'] }}"
                    >
                        <div class="dn-rail" aria-hidden="true">
                            <span class="dn-rail-line is-down" style="background: var(--dn-rail)"></span>
                            <span class="dn-rail-node">
                                <span class="dn-note-icon">
                                    <x-feed.icon :name="$item['icon']" :size="16" />
                                </span>
                            </span>
                        </div>

                        <div class="dn-note-content">
                            <div class="dn-note-head">
                                <span class="dn-avatars">
                                    <span class="dn-avatar" style="--dn-lane: {{ $item['lane'] }}; width: 32px; height: 32px; font-size: 11px;">
                                        {{ $item['actorInitials'] }}
                                    </span>
                                </span>
                                <time class="dn-time" datetime="{{ $item['iso'] }}">{{ $item['time'] }}</time>
                            </div>

                            <p class="dn-note-sentence">
                                <strong>{{ $item['actorName'] }}</strong>
                                <span class="dn-handle">{{ '@'.$item['actorHandle'] }}</span>
                                {{ $item['action'] }}
                            </p>

                            @if (filled($item['replyBody']))
                                <p class="dn-note-words">{{ $item['replyBody'] }}</p>
                            @endif

                            @if ($item['postUrl'] !== null)
                                <a href="{{ $item['postUrl'] }}" class="dn-note-quote" wire:navigate>
                                    @if (filled($item['postTitle']))
                                        <span class="dn-note-quote-title">{{ $item['postTitle'] }}</span>
                                    @endif
                                    <span class="dn-note-quote-body">{{ Str::limit((string) $item['body'], 180) }}</span>
                                </a>
                            @endif
                        </div>
                    </article>
                @endforeach
            @endforeach

            <div class="dn-root">
                <div class="dn-rail">
                    <span class="dn-rail-node" style="--dn-lane: var(--dn-rail)" aria-hidden="true">
                        <span class="dn-node is-root"></span>
                    </span>
                </div>
                <p class="dn-root-text">{{ __("That's the oldest notification. There's nothing before it.") }}</p>
            </div>
        </div>
    @endif
</x-log.frame>
