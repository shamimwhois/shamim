<?php

use App\Models\Remote;
use App\Models\RemoteMessage;
use App\Models\RemoteMessageReaction;
use App\Models\User;
use App\Models\UserBlock;
use App\Support\LogIdentity;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Remotes — the log's direct messages.
 *
 * Three panes, the way a messenger reads: the inbox on the left, the thread in
 * the middle, and nothing on the right until there is something to say. On a
 * phone the first two are pages — the list until you check one out, then the
 * thread, which is why the back control exists at all.
 *
 * Reading is a pull: opening a conversation moves the reader's marker past its
 * newest push, which is also what clears the nav's badge. Sending is a push,
 * and it moves the marker too — you have obviously read what you wrote.
 *
 * The inbox has three tabs, and which one a conversation lands in is derived,
 * not stored: a thread somebody else opened and you have never answered is a
 * request (Fetched); filing it away puts it in the stash; everything else is
 * Open. Answering is what merges a request — there is no second action for it,
 * because replying is the only thing a merge could mean.
 *
 * Every write below re-checks that the push belongs to the conversation on
 * screen, because the id arrives from the client. The list is not a permission
 * boundary; membership is.
 */
new #[Title('Remotes')] #[Layout('layouts::feed')] class extends Component
{
    /**
     * The reactions a reader may leave, in the order they are offered when
     * nobody has used any of them yet.
     *
     * @var list<string>
     */
    public const REACTIONS = ['👍', '🎉', '🔥', '❤️', '😂', '👀', '🚀', '👎'];

    /** The tabs, in the order they are drawn. */
    public const TABS = ['open', 'fetched', 'stashed'];

    /** The conversation on screen, if one is checked out. */
    public ?int $remoteId = null;

    /** The message being written. */
    public string $draft = '';

    /** Whether the thread is open on a phone, where the two panes are pages. */
    public bool $threadOpen = false;

    /**
     * Which tab the inbox is showing.
     *
     * In the URL because it is a view of the list, not a transient toggle: a
     * reload has to come back to the stash, and a link can point at it.
     */
    #[Url(as: 'tab', except: 'open')]
    public string $tab = 'open';

    /**
     * A term the list is narrowed to.
     *
     * In the URL for the same reason, and because the search page links
     * straight into it: "See all" from a remotes result has to land on the
     * conversations that matched, not on the whole inbox.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Whether the developer picker is showing instead of the inbox. */
    public bool $picking = false;

    /** The push being quoted, if the composer is answering one. */
    public ?int $replyTo = null;

    /** The push whose reaction tray is open. */
    public ?int $trayFor = null;

    /** The conversation whose action menu is open, if any. */
    public ?int $menuFor = null;

    /**
     * Whether the open conversation's header menu is showing.
     *
     * Separate from `menuFor` on purpose: the header's menu and the row's menu
     * are about the same conversation, so sharing one id would draw both at
     * once — one anchored under the row and one under the header.
     */
    public bool $threadMenu = false;

    /** Whether that menu is showing the mute durations instead of the actions. */
    public bool $muteMenu = false;

    /** The conversation whose block confirmation is showing. */
    public ?int $blockFor = null;

    /** Whether the account-wide settings popover is open. */
    public bool $settingsOpen = false;

    /** Whether the pinned banner is expanded. */
    public bool $pinsOpen = false;

    public function mount(?string $remote = null): void
    {
        if (auth()->guest()) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        app(Seo::class)->set([
            'title' => 'Remotes',
            'description' => __('Direct messages between developers.'),
        ]);

        // A tab from the URL is a claim like any other, so it is checked against
        // the set rather than trusted.
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'open';
        }

        $this->remoteId = $remote !== null ? (int) $remote : null;

        // A conversation that is not yours is not a conversation: the id in the
        // URL is a claim, and membership is what decides it.
        if ($this->remoteId !== null && ! $this->mine()->contains('id', $this->remoteId)) {
            $this->remoteId = null;
        }

        if ($this->remoteId === null) {
            $this->remoteId = $this->visible->first()?->id;
        }

        $this->markRead();
    }

    /**
     * Every conversation this account is part of.
     *
     * `mine_count` is how many pushes this reader has made, which is what
     * decides whether a thread somebody else opened is still a request. It is
     * counted in the same query as the rows rather than asked per row.
     *
     * @return Collection<int, Remote>
     */
    #[Computed]
    public function conversations(): Collection
    {
        /** @var User $reader */
        $reader = auth()->user();

        return Remote::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $reader->id))
            ->with(['participants', 'latestMessage'])
            ->withCount(['messages as mine_count' => fn ($query) => $query->where('user_id', $reader->id)])
            ->get()
            ->sort(function (Remote $a, Remote $b) use ($reader): int {
                // Pinned first, then newest push: a pin is a decision to keep
                // something where you can see it, so it outranks recency.
                $pinned = (int) $b->pinnedFor($reader) <=> (int) $a->pinnedFor($reader);

                return $pinned !== 0
                    ? $pinned
                    : ($b->latestMessage?->created_at?->getTimestamp() ?? 0) <=> ($a->latestMessage?->created_at?->getTimestamp() ?? 0);
            })
            ->values();
    }

    /**
     * The ids of the accounts this reader has blocked.
     *
     * One query for the page: every row and every candidate asks whether the
     * other side is blocked, and asking per row would be a query per row.
     *
     * @return list<int>
     */
    #[Computed]
    public function blocked(): array
    {
        /** @var User $reader */
        $reader = auth()->user();

        return $reader->blockedIds();
    }

    /**
     * The conversations the current tab lists, already narrowed by the term.
     *
     * @return Collection<int, Remote>
     */
    #[Computed]
    public function visible(): Collection
    {
        return $this->conversations
            ->reject(fn (Remote $remote): bool => $this->isBlocked($remote))
            ->filter(fn (Remote $remote): bool => $this->stateOf($remote) === $this->tab)
            ->when($this->search !== '' && ! $this->picking, fn (Collection $rows): Collection => $rows
                ->filter(fn (Remote $remote): bool => $this->matches->has($remote->id)))
            ->values();
    }

    /**
     * The newest matching push in each of this reader's conversations.
     *
     * Keyed by remote id, and only built when there is a term: the list uses it
     * twice — to decide which conversations survive the filter, and to preview
     * the line that matched instead of the newest one, which would be a
     * sentence the reader never searched for.
     *
     * @return Collection<int, RemoteMessage>
     */
    #[Computed]
    public function matches(): Collection
    {
        if (trim($this->search) === '') {
            return new Collection;
        }

        // Scoped to the reader's own conversations first, then matched: the
        // `whereIn` is what keeps a term from reading anyone else's thread.
        $ids = RemoteMessage::query()
            ->search($this->search)
            ->whereIn('remote_id', $this->conversations->pluck('id')->all())
            ->selectRaw('max(id) as id')
            ->groupBy('remote_id')
            ->pluck('id');

        return RemoteMessage::query()
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('remote_id');
    }

    /**
     * How many unread pushes each conversation holds.
     *
     * One grouped query rather than a count per row: the badge is drawn on
     * every row of the list, and "unread" is a comparison against the reader's
     * own marker, which lives on the pivot and so cannot be expressed as a
     * relation count.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function ahead(): array
    {
        /** @var User $reader */
        $reader = auth()->user();

        /** @var array<int, int> $counts */
        $counts = RemoteMessage::query()
            ->join('remote_participants', function ($join) use ($reader): void {
                $join->on('remote_participants.remote_id', '=', 'remote_messages.remote_id')
                    ->where('remote_participants.user_id', '=', $reader->id);
            })
            ->where('remote_messages.user_id', '!=', $reader->id)
            ->where(function ($query): void {
                $query->whereNull('remote_participants.last_read_at')
                    ->orWhereColumn('remote_messages.created_at', '>', 'remote_participants.last_read_at');
            })
            ->groupBy('remote_messages.remote_id')
            ->selectRaw('remote_messages.remote_id as remote_id, count(*) as total')
            ->pluck('total', 'remote_id')
            ->all();

        return array_map(intval(...), $counts);
    }

    /** How many conversations each tab holds, for its count. */
    #[Computed]
    public function counts(): array
    {
        $rows = $this->conversations->reject(fn (Remote $remote): bool => $this->isBlocked($remote));

        $counts = array_fill_keys(self::TABS, 0);

        foreach ($rows as $remote) {
            $counts[$this->stateOf($remote)]++;
        }

        return $counts;
    }

    /** The conversation on screen. */
    #[Computed]
    public function current(): ?Remote
    {
        return $this->conversations->firstWhere('id', $this->remoteId);
    }

    /** The other person in the open conversation, for the header. */
    #[Computed]
    public function partner(): ?User
    {
        $current = $this->current;

        if ($current === null) {
            return null;
        }

        /** @var User $reader */
        $reader = auth()->user();

        return $current->others($reader)->first();
    }

    /** Whether this reader silenced the open conversation. */
    #[Computed]
    public function muted(): bool
    {
        $current = $this->current;

        /** @var User $reader */
        $reader = auth()->user();

        return $current !== null && $current->mutedFor($reader);
    }

    /** Whether this reader pinned the open conversation to the top. */
    #[Computed]
    public function pinnedConversation(): bool
    {
        $current = $this->current;

        /** @var User $reader */
        $reader = auth()->user();

        return $current !== null && $current->pinnedFor($reader);
    }

    /** Whether this reader filed the open conversation away. */
    #[Computed]
    public function archived(): bool
    {
        $current = $this->current;

        /** @var User $reader */
        $reader = auth()->user();

        return $current !== null && $current->archivedFor($reader);
    }

    /**
     * The selected thread's pushes, oldest first.
     *
     * @return Collection<int, RemoteMessage>
     */
    #[Computed]
    public function messages(): Collection
    {
        if ($this->current === null) {
            return new Collection;
        }

        return $this->current->messages()
            ->with(['user', 'reactions', 'replyTo.user'])
            ->get();
    }

    /**
     * The pinned pushes in the open conversation, newest pin first.
     *
     * @return Collection<int, RemoteMessage>
     */
    #[Computed]
    public function pinned(): Collection
    {
        return $this->messages
            ->filter(fn (RemoteMessage $message): bool => $message->isPinned())
            ->sortByDesc(fn (RemoteMessage $message): int => $message->pinned_at?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * When the other side last pulled, which is what "seen" means here.
     *
     * A group has several markers; the newest is the one that matters, because
     * a push is read once anybody has read it. Nobody gets a receipt when the
     * partner has turned sharing off — that is the whole point of the switch.
     */
    #[Computed]
    public function seenAt(): ?Carbon
    {
        $current = $this->current;

        /** @var User $reader */
        $reader = auth()->user();

        if ($current === null || ! $reader->remote_pull_receipts) {
            return null;
        }

        return $current->others($reader)
            ->filter(fn (User $other): bool => (bool) $other->remote_pull_receipts)
            ->map(fn (User $other): ?Carbon => $current->readAtFor($other))
            ->filter()
            ->sortByDesc(fn (Carbon $at): int => $at->getTimestamp())
            ->first();
    }

    /**
     * The reaction set, ordered by what this thread actually uses.
     *
     * The reference orders its tray by usage and so does this: a thread that
     * runs on 🔥 should offer 🔥 first, not wherever it happens to sit in the
     * constant. The constant is the tie-break, so the tray is stable when
     * nothing has been used yet.
     *
     * @return list<string>
     */
    #[Computed]
    public function reactionSet(): array
    {
        $usage = $this->messages
            ->flatMap(fn (RemoteMessage $message): array => $message->reactions->pluck('emoji')->all())
            ->countBy();

        $order = array_flip(self::REACTIONS);

        $set = self::REACTIONS;

        usort($set, fn (string $a, string $b): int => [$usage->get($b, 0), $order[$a]] <=> [$usage->get($a, 0), $order[$b]]);

        return $set;
    }

    /** How long ago the other side pulled, for the receipt. */
    #[Computed]
    public function seenLabel(): string
    {
        return LogIdentity::relative($this->seenAt);
    }

    /**
     * The list rows: who, the last push, and how far ahead they are.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        /** @var User $reader */
        $reader = auth()->user();

        return $this->visible->map(function (Remote $remote) use ($reader): array {
            $other = $remote->others($reader)->first();
            $group = $other === null;
            $handle = LogIdentity::handleFor($other);
            // While filtering, the row previews the line that matched; the
            // newest push would be a sentence the reader did not search for.
            $last = $this->search !== '' && ! $this->picking
                ? $this->matches->get($remote->id)
                : $remote->latestMessage;
            $readAt = $remote->readAtFor($reader);
            $ahead = $this->ahead[$remote->id] ?? 0;
            $mine = $last !== null && $last->user_id === $reader->id;
            $pulled = $mine && $last !== null && $readAt !== null && $other !== null
                && $remote->readAtFor($other) !== null
                && $remote->readAtFor($other)->greaterThanOrEqualTo($last->created_at);

            return [
                'id' => $remote->id,
                'title' => $group ? (string) $remote->displayTitle($reader) : (string) $other->name,
                'handle' => $group ? '' : $handle,
                'initials' => $other !== null ? LogIdentity::initials($other->name) : '??',
                'lane' => $group ? LogIdentity::lane('group') : LogIdentity::lane($handle),
                'members' => $group ? $remote->others($reader)->count() + 1 : 0,
                'preview' => $last !== null
                    ? ($mine ? __('you: ') : ($group ? ($last->user->name ?? '').': ' : '')).Str::limit($last->body, 52)
                    : __('No pushes yet'),
                'time' => LogIdentity::relative($last?->created_at),
                'muted' => $remote->mutedFor($reader),
                'pinned' => $remote->pinnedFor($reader),
                'ahead' => $ahead,
                // Only my own newest push carries a receipt in the list, and
                // only once the partner has actually read it.
                'status' => $mine ? ($pulled ? 'pulled' : 'pushed') : null,
                'blocked' => false,
            ];
        })->all();
    }

    /**
     * The thread's pushes, shaped for the template.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function thread(): array
    {
        /** @var User $reader */
        $reader = auth()->user();

        $seenAt = $this->seenAt;
        $lastMine = $this->messages->reverse()->firstWhere('user_id', $reader->id)?->id;
        $previous = null;
        $day = null;

        $rows = [];

        foreach ($this->messages as $message) {
            $handle = LogIdentity::handleFor($message->user);
            $mine = $message->user_id === $reader->id;
            $messageDay = LogIdentity::day($message->created_at);

            $reply = $message->replyTo;
            $replyAuthor = $reply?->user;

            $rows[] = [
                'id' => $message->id,
                'body' => $message->body,
                'isMine' => $mine,
                'name' => (string) $message->user->name,
                'handle' => $handle,
                'initials' => LogIdentity::initials((string) $message->user->name),
                'lane' => LogIdentity::lane($handle),
                'time' => LogIdentity::relative($message->created_at),
                // The day is printed on the divider, not on the row: a bubble
                // does not need a date, the group it belongs to does.
                'day' => $messageDay !== $day ? $messageDay : null,
                // Consecutive pushes by one person share an author line.
                'author' => $previous !== $message->user_id,
                'pinned' => $message->isPinned(),
                'reactions' => $this->chips($message, $reader),
                'reply' => $reply === null ? null : [
                    'id' => $reply->id,
                    'name' => (string) $replyAuthor?->name,
                    'body' => Str::limit($reply->body, 90),
                ],
                // Only the newest push of yours carries the receipt: repeating
                // it under every message would be noise, and the marker it
                // reads is monotonic anyway.
                'status' => $mine && $message->id === $lastMine
                    ? ($seenAt !== null && $message->created_at !== null && $message->created_at->lessThanOrEqualTo($seenAt) ? 'pulled' : 'pushed')
                    : null,
            ];

            $previous = $message->user_id;
            $day = $messageDay;
        }

        return $rows;
    }

    /**
     * The people the search could start a conversation with.
     *
     * Only drawn when the picker is open or there is a term: an inbox is a list
     * of conversations, and a directory of everyone on the log under it would
     * be a second product. People already in an open conversation are left out,
     * because the row for them is already in the list.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function candidates(): array
    {
        if (! $this->picking && trim($this->search) === '') {
            return [];
        }

        /** @var User $reader */
        $reader = auth()->user();

        $known = $this->conversations
            ->map(fn (Remote $remote): ?int => $remote->others($reader)->first()?->id)
            ->filter()
            ->all();

        $term = ltrim(trim($this->search), '@');

        $query = User::query()
            ->whereKeyNot($reader->id)
            ->whereNotIn('id', $this->blocked)
            ->whereNotIn('id', $known);

        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

            $query->where(fn ($query) => $query
                ->where('name', 'like', $like)
                ->orWhere('handle', 'like', $like));
        }

        return $query->orderBy('name')->limit(8)->get()
            ->map(function (User $user): array {
                $handle = LogIdentity::handleFor($user);

                return [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'handle' => $handle,
                    'initials' => LogIdentity::initials((string) $user->name),
                    'lane' => LogIdentity::lane($handle),
                ];
            })
            ->all();
    }

    /**
     * The accounts this reader blocked, for the unblock footer.
     *
     * Blocking is reversible or it is a trap: the conversation is gone from
     * every list, so the only place the row could come back from is here.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function blockedAccounts(): array
    {
        if ($this->blocked === []) {
            return [];
        }

        /** @var User $reader */
        $reader = auth()->user();

        return User::query()->whereIn('id', $this->blocked)->orderBy('name')->get()
            ->map(function (User $user) use ($reader): array {
                $handle = LogIdentity::handleFor($user);

                return [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'handle' => $handle,
                    'initials' => LogIdentity::initials((string) $user->name),
                    'lane' => LogIdentity::lane($handle),
                    // Unblocking is only offered where a conversation exists to
                    // come back to; blocking is also used to silence a stranger
                    // you never spoke to.
                    'restorable' => $this->conversations->contains(
                        fn (Remote $remote): bool => $remote->others($reader)->contains('id', $user->id),
                    ),
                ];
            })
            ->all();
    }

    /** Open a conversation and pull it. */
    public function open(int $remoteId): void
    {
        if (! $this->mine()->contains('id', $remoteId)) {
            return;
        }

        $this->remoteId = $remoteId;
        $this->draft = '';
        $this->replyTo = null;
        $this->threadOpen = true;
        $this->picking = false;
        $this->closeMenu();

        $this->forget();

        $this->markRead();

        $this->dispatch('remote-scrolled');
    }

    /** Push a message into the open conversation. */
    public function push(): void
    {
        /** @var User $reader */
        $reader = auth()->user();

        $this->validate([
            'draft' => ['required', 'string', 'max:2000'],
        ], attributes: ['draft' => __('message')]);

        $remote = $this->current;

        if ($remote === null) {
            return;
        }

        // A quote points at a push in this thread or it does not exist: the id
        // arrives from the client, and a stale one would draw a ghost for a
        // message the reader cannot see.
        $replyTo = $this->replyTo !== null && $this->messages->contains('id', $this->replyTo)
            ? $this->replyTo
            : null;

        RemoteMessage::query()->create([
            'remote_id' => $remote->id,
            'user_id' => $reader->id,
            'body' => trim($this->draft),
            'reply_to_id' => $replyTo,
        ]);

        $this->draft = '';
        $this->replyTo = null;

        $this->forget();

        $this->markRead();

        $this->dispatch('remote-scrolled');
    }

    /**
     * Toggle one reaction on one push.
     *
     * The emoji arrives from the client, so it is checked against the offered
     * set before it is stored: the column is counted by emoji, and a stray
     * value would be a chip nobody can read.
     */
    public function react(int $messageId, string $emoji): void
    {
        if (! in_array($emoji, self::REACTIONS, true)) {
            return;
        }

        $message = $this->messageInThread($messageId);

        if ($message === null) {
            return;
        }

        /** @var User $reader */
        $reader = auth()->user();

        $existing = RemoteMessageReaction::query()
            ->where('remote_message_id', $message->id)
            ->where('user_id', $reader->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();
        } else {
            RemoteMessageReaction::query()->create([
                'remote_message_id' => $message->id,
                'user_id' => $reader->id,
                'emoji' => $emoji,
            ]);
        }

        $this->trayFor = null;

        unset($this->messages, $this->thread, $this->pinned, $this->reactionSet);
    }

    /** Toggle the pin on one push. */
    public function pin(int $messageId): void
    {
        $message = $this->messageInThread($messageId);

        if ($message === null) {
            return;
        }

        $message->pinned_at = $message->isPinned() ? null : now();
        $message->save();

        // `messages` is the cache the chips and the pin marks are read from, so
        // dropping only `thread` would redraw the old collection: the write
        // would land and the screen would lag a click behind it.
        unset($this->messages, $this->thread, $this->pinned, $this->reactionSet);
    }

    /** Open or close the reaction tray on a push. */
    public function tray(int $messageId): void
    {
        $this->trayFor = $this->trayFor === $messageId ? null : $messageId;
    }

    /** Quote a push in the composer. */
    public function reply(int $messageId): void
    {
        if ($this->messageInThread($messageId) === null) {
            return;
        }

        $this->replyTo = $messageId;
        $this->trayFor = null;
    }

    /** Drop the quote. */
    public function cancelReply(): void
    {
        $this->replyTo = null;
    }

    /** Pin or unpin a conversation, from its row or its header. */
    public function pinRemote(int $remoteId): void
    {
        if (! $this->mine()->contains('id', $remoteId)) {
            return;
        }

        $pinned = $this->conversations->firstWhere('id', $remoteId)?->pinnedFor(auth()->user()) ?? false;

        $this->stamp($remoteId, 'pinned_at', $pinned ? null : now());
        $this->closeMenu();
    }

    /**
     * Silence a conversation for a while, or stop silencing it.
     *
     * A mute is a duration, not a flag: "for one hour" has to release itself,
     * or the reader has to remember a switch they cannot see. `off` is the same
     * write with nothing in it.
     */
    public function mute(int $remoteId, string $duration): void
    {
        if (! $this->mine()->contains('id', $remoteId)) {
            return;
        }

        $this->stamp($remoteId, 'muted_until_at', match ($duration) {
            'hour' => now()->addHour(),
            'tomorrow' => now()->addDay()->startOfDay(),
            'week' => now()->addWeek(),
            'forever' => now()->addYears(100),
            default => null,
        });

        $this->closeMenu();
    }

    /** File a conversation away, or bring it back. */
    public function archive(int $remoteId): void
    {
        if (! $this->mine()->contains('id', $remoteId)) {
            return;
        }

        /** @var User $reader */
        $reader = auth()->user();

        $archived = $this->conversations->firstWhere('id', $remoteId)?->archivedFor($reader) ?? false;

        $this->stamp($remoteId, 'archived_at', $archived ? null : now());
        $this->closeMenu();

        // A conversation that just left the tab cannot stay selected: the pane
        // would show a thread with no row in the list beside it.
        $this->settle();
    }

    /**
     * Show one of the three tabs.
     *
     * Tabs rather than one toggle: a tab says where you want to be, so clicking
     * the one you are already on has to be a no-op and not a flip to another
     * list.
     */
    public function showTab(string $tab): void
    {
        if (! in_array($tab, self::TABS, true) || $this->tab === $tab) {
            return;
        }

        $this->tab = $tab;
        $this->picking = false;

        $this->settle();
    }

    /** Show or hide the developer picker. */
    public function pick(): void
    {
        $this->picking = ! $this->picking;
        $this->search = '';
        $this->closeMenu();
    }

    /**
     * Start (or find) a direct conversation with somebody and open it.
     *
     * The pair is the identity: two people cannot have two direct remotes, so
     * pressing a name that already has a thread opens that thread rather than
     * making a second one.
     */
    public function startWith(int $userId): void
    {
        /** @var User $reader */
        $reader = auth()->user();

        if ($userId === $reader->id || in_array($userId, $this->blocked, true)) {
            return;
        }

        $other = User::query()->find($userId);

        if ($other === null) {
            return;
        }

        $existing = $this->conversations->first(
            fn (Remote $remote): bool => $remote->kind === 'direct'
                && $remote->others($reader)->count() === 1
                && $remote->others($reader)->first()?->id === $other->id,
        );

        if ($existing !== null) {
            $this->open($existing->id);

            return;
        }

        $remote = Remote::query()->create([
            'kind' => 'direct',
            'created_by' => $reader->id,
        ]);

        $remote->participants()->attach([$reader->id, $other->id]);

        $this->picking = false;
        $this->search = '';
        $this->tab = 'open';
        $this->remoteId = $remote->id;
        $this->threadOpen = true;

        $this->forget();

        $this->markRead();

        $this->dispatch('remote-scrolled');
    }

    /** Ask to block the other side of a conversation. */
    public function confirmBlock(int $remoteId): void
    {
        $this->blockFor = $this->blockFor === $remoteId ? null : $remoteId;

        // The confirmation is drawn inside the menu that asked for it, so the
        // menu stays open: closing it here would remove the panel and leave the
        // press with nothing to show for it.
        $this->muteMenu = false;
    }

    /**
     * Block the other side of a conversation.
     *
     * The row is the same one a mute uses, with `muted` false, because both
     * answer "does this person reach me". A block also files the conversation
     * away: it is gone from the inbox for this reader, and the partner's pushes
     * stop arriving, so leaving it in Open would be a row nobody can answer.
     */
    public function block(int $remoteId): void
    {
        $current = $this->conversations->firstWhere('id', $remoteId);

        /** @var User $reader */
        $reader = auth()->user();

        if ($current === null) {
            return;
        }

        $other = $current->others($reader)->first();

        if ($other === null) {
            return;
        }

        UserBlock::query()->firstOrCreate([
            'user_id' => $reader->id,
            'blocked_id' => $other->id,
        ], ['muted' => false]);

        $this->stamp($remoteId, 'archived_at', now());

        $this->blockFor = null;
        $this->closeMenu();

        $this->settle();
    }

    /** Undo a block, which is the only way the conversation comes back. */
    public function unblock(int $userId): void
    {
        /** @var User $reader */
        $reader = auth()->user();

        UserBlock::query()
            ->where('user_id', $reader->id)
            ->where('blocked_id', $userId)
            ->where('muted', false)
            ->delete();

        $this->forget();
    }

    /** Show or hide the account-wide settings popover. */
    public function toggleSettings(): void
    {
        $this->settingsOpen = ! $this->settingsOpen;
        $this->closeMenu();
    }

    /**
     * Flip one of the account-wide remote switches.
     *
     * `requests` screens first pushes into Fetched; `receipts` is whether pull
     * receipts are shared at all; `enabled` is whether remotes exist for this
     * account. All three are profile answers, so they are written to the user
     * and not to a conversation.
     */
    public function togglePreference(string $key): void
    {
        /** @var User $reader */
        $reader = auth()->user();

        $column = match ($key) {
            'requests' => 'remote_requests',
            'receipts' => 'remote_pull_receipts',
            'enabled' => 'remotes_enabled',
            default => null,
        };

        if ($column === null) {
            return;
        }

        $reader->forceFill([$column => ! $reader->getAttribute($column)])->save();

        $this->forget();
    }

    /** Expand or collapse the pinned banner. */
    public function togglePins(): void
    {
        $this->pinsOpen = ! $this->pinsOpen;
    }

    /** Close the thread and go back to the list, on a phone. */
    public function back(): void
    {
        $this->threadOpen = false;
    }

    /** Open the action menu on one conversation's row. */
    public function openMenu(int $remoteId): void
    {
        $this->menuFor = $this->menuFor === $remoteId ? null : $remoteId;
        $this->muteMenu = false;
        $this->threadMenu = false;
    }

    /** Open the action menu in the thread's own header. */
    public function openThreadMenu(): void
    {
        $this->threadMenu = ! $this->threadMenu;
        $this->menuFor = null;
        $this->muteMenu = false;
    }

    /** Close both, whichever is showing. */
    public function closeMenu(): void
    {
        $this->menuFor = null;
        $this->threadMenu = false;
        $this->muteMenu = false;
    }

    /** Swap the menu's actions for the mute durations. */
    public function showMutes(): void
    {
        $this->muteMenu = true;
    }

    /**
     * The header's bell.
     *
     * Unmuting is one press — there is only one way to do it. Muting is not:
     * the durations are the choice, so the bell opens the menu at that step
     * instead of picking "forever" on the reader's behalf.
     */
    public function muteFromHeader(): void
    {
        if ($this->remoteId === null) {
            return;
        }

        if ($this->muted) {
            $this->mute($this->remoteId, 'off');

            return;
        }

        $this->menuFor = null;
        $this->threadMenu = true;
        $this->muteMenu = true;
    }

    /**
     * Which tab a conversation belongs to.
     *
     * Derived, never stored: archiving is a filing decision and wins over
     * everything; a thread somebody else opened that this reader has never
     * answered is a request; the rest is the inbox.
     */
    private function stateOf(Remote $remote): string
    {
        /** @var User $reader */
        $reader = auth()->user();

        if ($remote->archivedFor($reader)) {
            return 'stashed';
        }

        if ($reader->remote_requests && $this->isRequest($remote, $reader)) {
            return 'fetched';
        }

        return 'open';
    }

    /**
     * Whether the thread was opened by the other side and never answered.
     *
     * A request is a first *push*, so an empty thread is not one: there is
     * nothing waiting to be fetched, and a conversation nobody has written in
     * belongs in the inbox where its first line will land.
     */
    private function isRequest(Remote $remote, User $reader): bool
    {
        return $remote->created_by !== $reader->id
            && $remote->latestMessage !== null
            && (int) $remote->getAttribute('mine_count') === 0;
    }

    /** Whether the other side of a conversation is blocked. */
    private function isBlocked(Remote $remote): bool
    {
        /** @var User $reader */
        $reader = auth()->user();

        return $remote->others($reader)->contains(
            fn (User $other): bool => in_array($other->id, $this->blocked, true),
        );
    }

    /**
     * Keep the selection inside the list that is showing.
     *
     * Called after anything that can move a row between tabs, because a thread
     * with no row beside it is a pane the reader cannot get back to.
     */
    private function settle(): void
    {
        if ($this->visible->doesntContain('id', $this->remoteId)) {
            $this->remoteId = $this->visible->first()?->id;
            $this->threadOpen = false;
            $this->replyTo = null;
        }

        $this->forget();
    }

    /**
     * The push with this id, but only if it belongs to the open conversation.
     *
     * The client sends an id; this is the check that turns it into a fact.
     */
    private function messageInThread(int $messageId): ?RemoteMessage
    {
        return $this->messages->firstWhere('id', $messageId);
    }

    /**
     * Stamp or clear one of this reader's own participant columns.
     *
     * `updateExistingPivot` only touches the reader's row, which is the whole
     * point: mute, pin and archive are decisions about your copy of the thread.
     */
    private function stamp(int $remoteId, string $column, ?\DateTimeInterface $at): void
    {
        /** @var User $reader */
        $reader = auth()->user();

        $reader->remotes()->updateExistingPivot($remoteId, [
            $column => $at,
        ]);

        $this->forget();
    }

    /**
     * Group a push's reaction rows into chips.
     *
     * @return array<int, array{emoji: string, count: int, mine: bool}>
     */
    private function chips(RemoteMessage $message, User $reader): array
    {
        $chips = [];

        foreach (self::REACTIONS as $emoji) {
            $rows = $message->reactions->where('emoji', $emoji);

            if ($rows->isEmpty()) {
                continue;
            }

            $chips[] = [
                'emoji' => $emoji,
                'count' => $rows->count(),
                'mine' => $rows->contains('user_id', $reader->id),
            ];
        }

        return $chips;
    }

    /** Move this reader's marker past the newest push. */
    private function markRead(): void
    {
        if ($this->remoteId === null) {
            return;
        }

        /** @var User $reader */
        $reader = auth()->user();

        $reader->remotes()->updateExistingPivot($this->remoteId, [
            'last_read_at' => now(),
        ]);

        unset($this->ahead);
    }

    /** Drop every derived value that the last write could have changed. */
    private function forget(): void
    {
        unset(
            $this->conversations,
            $this->blocked,
            $this->visible,
            $this->matches,
            $this->ahead,
            $this->counts,
            $this->current,
            $this->partner,
            $this->messages,
            $this->pinned,
            $this->seenAt,
            $this->muted,
            $this->pinnedConversation,
            $this->archived,
            $this->reactionSet,
            $this->thread,
            $this->rows,
            $this->candidates,
            $this->blockedAccounts,
        );
    }

    /**
     * The conversations this account may open.
     *
     * @return Collection<int, Remote>
     */
    private function mine(): Collection
    {
        /** @var User $reader */
        $reader = auth()->user();

        return Remote::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $reader->id))
            ->get();
    }
}; ?>

@php
    /** @var App\Models\User $me */
    $me = auth()->user();
    $current = $this->current;
    $partner = $this->partner;
@endphp

<x-log.frame active="remotes" data-test="remotes">
    @if (! $me->remotes_enabled)
        {{-- Remotes are off for this account: the history is still there, but
             nobody can push into it, so the page says so instead of showing an
             inbox that cannot receive. --}}
        <div class="dn-empty" data-test="remotes-off">
            <p class="dn-empty-command">fatal: remotes are off</p>
            <p class="dn-empty-text">
                {{ __('Nobody can push to you, and your conversations are quiet. Turning them back on restores the inbox exactly as you left it.') }}
            </p>
            <button type="button" class="dn-btn dn-btn-sm" wire:click="togglePreference('enabled')" style="margin-top: 12px;">
                {{ __('Turn remotes back on') }}
            </button>
        </div>
    @else
        <div class="dn-remotes {{ $threadOpen ? 'is-open' : '' }}" data-test="remotes-shell">
            {{-- The inbox ------------------------------------------------- --}}
            <section class="dn-remote-pane" aria-label="{{ __('Conversations') }}">
                <header class="dn-pane-head">
                    <h1 class="dn-pane-title">{{ __('Remotes') }}</h1>

                    <div class="dn-pane-tools" x-data>
                        <button
                            type="button"
                            class="dn-pane-btn {{ $settingsOpen ? 'is-on' : '' }}"
                            wire:click="toggleSettings"
                            aria-label="{{ __('Remote settings') }}"
                            aria-expanded="{{ $settingsOpen ? 'true' : 'false' }}"
                            data-test="remote-settings"
                        >
                            <x-feed.icon name="settings" :size="17" />
                        </button>

                        <button
                            type="button"
                            class="dn-pane-btn {{ $picking ? 'is-on' : '' }}"
                            wire:click="pick"
                            aria-label="{{ __('New remote') }}"
                            data-test="remote-new"
                        >
                            <x-feed.icon name="pen" :size="17" />
                        </button>

                        @if ($settingsOpen)
                            <div class="dn-pop dn-pop-settings" role="dialog" aria-label="{{ __('Remote settings') }}" x-on:click.outside="$wire.toggleSettings()" x-on:keydown.escape.window="$wire.toggleSettings()">
                                <p class="dn-pop-label">{{ __('what lands in your inbox') }}</p>

                                <label class="dn-pop-check">
                                    <input
                                        type="checkbox"
                                        @checked($me->remote_requests)
                                        wire:click="togglePreference('requests')"
                                        data-test="pref-requests"
                                    />
                                    <span>
                                        <span class="dn-pop-check-name">{{ __('Fetched requests') }}</span>
                                        <span class="dn-pop-check-note">
                                            {{ $me->remote_requests
                                                ? __('A first push from someone you have never answered waits in Fetched until you reply.')
                                                : __('Anyone who pushes to you lands straight in Open.') }}
                                        </span>
                                    </span>
                                </label>

                                <label class="dn-pop-check">
                                    <input
                                        type="checkbox"
                                        @checked($me->remote_pull_receipts)
                                        wire:click="togglePreference('receipts')"
                                        data-test="pref-receipts"
                                    />
                                    <span>
                                        <span class="dn-pop-check-name">{{ __('Share pulls') }}</span>
                                        <span class="dn-pop-check-note">
                                            {{ __('When off, nobody sees when you pull their pushes, and you do not see when they pull yours.') }}
                                        </span>
                                    </span>
                                </label>

                                <hr class="dn-pop-rule" />

                                <button type="button" class="dn-pop-item" wire:click="togglePreference('enabled')" data-test="pref-enabled">
                                    <x-feed.icon name="bell-off" :size="14" />
                                    {{ __('Turn remotes off') }}
                                </button>
                            </div>
                        @endif
                    </div>
                </header>

                <div class="dn-remote-search">
                    <div class="dn-search-field">
                        <x-feed.icon name="search" :size="14" />
                        <label for="remote-search" class="sr-only">{{ __('Search remotes') }}</label>
                        <input
                            id="remote-search"
                            type="search"
                            wire:model.live.debounce.250ms="search"
                            placeholder="{{ $picking ? __('Search developers…') : __('Search names or @handles') }}"
                            maxlength="100"
                            autocomplete="off"
                            spellcheck="false"
                            x-on:keydown.escape="$wire.set('search', '')"
                            data-test="remote-search"
                        />
                    </div>
                </div>

                <div class="dn-remote-tabs" role="tablist" aria-label="{{ __('Conversations') }}">
                    @foreach (['open' => __('Open'), 'fetched' => __('Fetched'), 'stashed' => __('Stashed')] as $key => $label)
                        <button
                            type="button"
                            role="tab"
                            class="dn-remote-tab {{ $tab === $key ? 'is-active' : '' }}"
                            wire:click="showTab('{{ $key }}')"
                            @if ($tab === $key) aria-selected="true" @endif
                            data-test="remotes-tab-{{ $key }}"
                        >
                            <span class="dn-remote-tab-label">
                                {{ $label }}
                                @if ($key === 'fetched' && $this->counts['fetched'] > 0)
                                    <span class="dn-remote-tab-count" aria-hidden="true">
                                        {{ $this->counts['fetched'] > 99 ? '99+' : $this->counts['fetched'] }}
                                    </span>
                                    <span class="sr-only">{{ __(':count waiting', ['count' => $this->counts['fetched']]) }}</span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>

                <div class="dn-remote-scroll" role="tabpanel">
                    {{-- The picker replaces the inbox rather than sitting under
                         it: choosing a person to write to is a different
                         question from which conversation to read. --}}
                    @if (! $picking && $this->rows !== [])
                        <ul class="dn-remote-rows" aria-label="{{ __('Conversations') }}">
                            @foreach ($this->rows as $row)
                                <li class="dn-remote-row {{ $row['id'] === $remoteId ? 'is-active' : '' }}" x-data>
                                    @if ($row['id'] === $remoteId)
                                        <span class="dn-row-bar" aria-hidden="true"></span>
                                    @endif

                                    <button type="button" class="dn-remote-open" wire:click="open({{ $row['id'] }})" data-test="remote-row">
                                        <span class="dn-avatar dn-remote-avatar" style="--dn-lane: {{ $row['lane'] }};">
                                            {{ $row['initials'] }}
                                        </span>

                                        <span class="dn-remote-body">
                                            <span class="dn-remote-top">
                                                <span class="dn-remote-name" title="{{ $row['title'] }}">{{ $row['title'] }}</span>
                                                @if ($row['handle'] !== '')
                                                    <span class="dn-remote-handle">{{ '@'.$row['handle'] }}</span>
                                                @endif
                                            </span>

                                            <span class="dn-remote-preview {{ $row['ahead'] > 0 ? 'is-ahead' : '' }}">
                                                @if ($row['status'] !== null)
                                                    <span class="dn-status dn-status-{{ $row['status'] }}" aria-hidden="true">
                                                        <svg width="{{ $row['status'] === 'pulled' ? 18 : 10 }}" height="10" viewBox="0 0 18 10" fill="none">
                                                            <circle cx="4" cy="5" r="3.4" fill="currentColor" />
                                                            @if ($row['status'] === 'pulled')
                                                                <path d="M4 5h10" stroke="var(--dn-rail)" stroke-width="1.5" />
                                                                <circle cx="14" cy="5" r="3.4" fill="var(--dn-primary)" />
                                                            @endif
                                                        </svg>
                                                    </span>
                                                @endif
                                                <span class="dn-remote-preview-text">{{ $row['preview'] }}</span>
                                            </span>
                                        </span>

                                        <span class="dn-remote-meta">
                                            @if ($row['pinned'])
                                                <span class="dn-remote-pin" title="{{ __('Pinned') }}">
                                                    <x-feed.icon name="pin" :size="12" />
                                                </span>
                                            @endif

                                            @if ($row['ahead'] > 0)
                                                <span class="dn-remote-ahead">{{ $row['ahead'] > 99 ? '99+' : $row['ahead'] }}</span>
                                            @elseif ($row['muted'])
                                                <span class="dn-remote-muted" title="{{ __('Muted') }}">
                                                    <x-feed.icon name="bell-off" :size="13" />
                                                </span>
                                            @elseif ($row['time'] !== '')
                                                <time class="dn-remote-time">{{ $row['time'] }}</time>
                                            @endif
                                        </span>
                                    </button>

                                    <button
                                        type="button"
                                        class="dn-remote-more"
                                        wire:click="openMenu({{ $row['id'] }})"
                                        aria-label="{{ __('More options for :title', ['title' => $row['title']]) }}"
                                        data-test="remote-actions"
                                    >
                                        <x-feed.icon name="ellipsis" :size="16" />
                                    </button>

                                    @if ($menuFor === $row['id'])
                                        <x-log.remote-menu
                                            :remote-id="$row['id']"
                                            :title="$row['title']"
                                            :pinned="$row['pinned']"
                                            :archived="$tab === 'stashed'"
                                            :muted="$row['muted']"
                                            :direct="$row['handle'] !== ''"
                                            :handle="$row['handle'] !== '' ? $row['handle'] : null"
                                            :mute-menu="$muteMenu"
                                            :blocking="$blockFor === $row['id']"
                                            class="dn-pop-row"
                                        />
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @elseif (! $picking)
                        <p class="dn-remote-none" data-test="remotes-none">
                            @if ($search !== '')
                                fatal: nothing matches '{{ $search }}'
                            @elseif ($tab === 'stashed')
                                nothing stashed
                            @elseif ($tab === 'fetched')
                                no requests fetched
                            @else
                                no conversations yet
                            @endif
                        </p>
                    @endif

                    {{-- People the term could start a conversation with. Drawn
                         under the results while searching, and as the whole
                         panel while picking. --}}
                    @if ($this->candidates !== [])
                        <section class="dn-remote-candidates" aria-label="{{ __('Start a conversation') }}">
                            <h2 class="dn-remote-candidates-title">{{ __('Start a conversation') }}</h2>

                            <ul>
                                @foreach ($this->candidates as $candidate)
                                    <li>
                                        <button type="button" class="dn-candidate" wire:click="startWith({{ $candidate['id'] }})" data-test="remote-candidate">
                                            <span class="dn-avatar dn-candidate-avatar" style="--dn-lane: {{ $candidate['lane'] }};">
                                                {{ $candidate['initials'] }}
                                            </span>
                                            <span class="dn-candidate-body">
                                                <span class="dn-candidate-name">{{ $candidate['name'] }}</span>
                                                <span class="dn-candidate-handle">{{ '@'.$candidate['handle'] }}</span>
                                            </span>
                                            <span class="dn-candidate-go" aria-hidden="true">
                                                <x-feed.icon name="pen" :size="14" />
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @elseif ($picking)
                        <p class="dn-remote-none" data-test="remotes-no-candidates">
                            @if ($search !== '')
                                fatal: nothing matches '{{ $search }}'
                            @else
                                everyone you can push to is already here
                            @endif
                        </p>
                    @endif

                    {{-- Blocking is reversible or it is a trap: the conversation
                         leaves every list, so this is the only way back. --}}
                    @if ($this->blockedAccounts !== [])
                        <footer class="dn-remote-blocked" data-test="remotes-blocked">
                            <p class="dn-remote-blocked-label">{{ trans_choice(':count blocked|:count blocked', count($this->blockedAccounts), ['count' => count($this->blockedAccounts)]) }}</p>

                            @foreach ($this->blockedAccounts as $blocked)
                                <p class="dn-remote-blocked-row">
                                    <span class="dn-remote-blocked-name">{{ $blocked['name'] }}</span>
                                    <button type="button" class="dn-link" wire:click="unblock({{ $blocked['id'] }})" data-test="remote-unblock">
                                        {{ __('Unblock') }}
                                    </button>
                                </p>
                            @endforeach
                        </footer>
                    @endif
                </div>
            </section>

            {{-- The thread ------------------------------------------------ --}}
            <section class="dn-remote-thread" aria-label="{{ __('Conversation') }}">
                @if ($current !== null)
                    <header class="dn-thread-head">
                        <button type="button" class="dn-thread-back" wire:click="back" aria-label="{{ __('Back to conversations') }}">
                            <x-feed.icon name="chevron-left" :size="18" />
                        </button>

                        @if ($partner !== null)
                            <a class="dn-thread-who" href="{{ route('profile.show', ['handle' => App\Support\LogIdentity::handleFor($partner)]) }}" wire:navigate>
                                <span class="dn-avatar dn-thread-avatar" style="--dn-lane: {{ App\Support\LogIdentity::lane(App\Support\LogIdentity::handleFor($partner)) }};">
                                    {{ App\Support\LogIdentity::initials((string) $partner->name) }}
                                </span>
                                <span class="dn-thread-who-body">
                                    <span class="dn-thread-title" title="{{ $partner->name }}">{{ $partner->name }}</span>
                                    <span class="dn-thread-handle">{{ '@'.App\Support\LogIdentity::handleFor($partner) }}</span>
                                </span>
                            </a>
                        @else
                            <span class="dn-thread-who">
                                <span class="dn-avatar dn-thread-avatar" style="--dn-lane: var(--dn-primary);">
                                    <x-feed.icon name="users" :size="16" />
                                </span>
                                <span class="dn-thread-who-body">
                                    <span class="dn-thread-title">{{ $current->displayTitle($me) }}</span>
                                    <span class="dn-thread-handle">{{ trans_choice(':count member|:count members', $current->participants->count(), ['count' => $current->participants->count()]) }}</span>
                                </span>
                            </span>
                        @endif

                        <div class="dn-thread-actions" x-data>
                            <button
                                type="button"
                                class="dn-thread-action {{ $this->pinnedConversation ? 'is-on' : '' }}"
                                wire:click="pinRemote({{ $current->id }})"
                                aria-pressed="{{ $this->pinnedConversation ? 'true' : 'false' }}"
                                aria-label="{{ $this->pinnedConversation ? __('Unpin this remote') : __('Pin this remote') }}"
                                title="{{ $this->pinnedConversation ? __('Unpin') : __('Pin') }}"
                                data-test="remote-thread-pin"
                            >
                                <x-feed.icon name="pin" :size="15" />
                            </button>

                            <button
                                type="button"
                                class="dn-thread-action {{ $this->muted ? 'is-on' : '' }}"
                                wire:click="muteFromHeader"
                                aria-pressed="{{ $this->muted ? 'true' : 'false' }}"
                                aria-label="{{ $this->muted ? __('Unmute this remote') : __('Mute this remote') }}"
                                title="{{ $this->muted ? __('Unmute') : __('Mute') }}"
                                data-test="remote-mute"
                            >
                                <x-feed.icon :name="$this->muted ? 'bell-off' : 'bell'" :size="15" />
                            </button>

                            <button
                                type="button"
                                class="dn-thread-action"
                                wire:click="openThreadMenu"
                                aria-label="{{ __('More options') }}"
                                aria-expanded="{{ $threadMenu ? 'true' : 'false' }}"
                                data-test="remote-thread-actions"
                            >
                                <x-feed.icon name="ellipsis" :size="16" />
                            </button>

                            @if ($threadMenu)
                                <x-log.remote-menu
                                    :remote-id="$current->id"
                                    :title="$partner !== null ? '@'.App\Support\LogIdentity::handleFor($partner) : (string) $current->displayTitle($me)"
                                    :pinned="$this->pinnedConversation"
                                    :archived="$this->archived"
                                    :muted="$this->muted"
                                    :direct="$partner !== null"
                                    :handle="$partner !== null ? App\Support\LogIdentity::handleFor($partner) : null"
                                    :mute-menu="$muteMenu"
                                    :blocking="$blockFor === $current->id"
                                    class="dn-pop-thread"
                                />
                            @endif
                        </div>
                    </header>

                    {{-- Pins sit over the thread rather than in it: they are what
                         the conversation is about, so they are readable from
                         anywhere in it, and collapsed they cost one line. --}}
                    @if ($this->pinned->isNotEmpty())
                        <div class="dn-pins {{ $pinsOpen ? 'is-open' : '' }}" data-test="remote-pinned">
                            <button type="button" class="dn-pins-bar" wire:click="togglePins" aria-expanded="{{ $pinsOpen ? 'true' : 'false' }}">
                                <x-feed.icon name="pin" :size="13" />
                                <span class="dn-pins-count">{{ trans_choice(':count pinned|:count pinned', $this->pinned->count(), ['count' => $this->pinned->count()]) }}</span>
                                <span class="dn-pins-peek">{{ Str::limit($this->pinned->first()->body, 70) }}</span>
                                <x-feed.icon name="chevron-down" :size="13" class="dn-pins-caret" />
                            </button>

                            @if ($pinsOpen)
                                <ul class="dn-pins-list">
                                    @foreach ($this->pinned as $pin)
                                        <li class="dn-pins-row">
                                            <span class="dn-pins-author">{{ $pin->user?->name }}</span>
                                            <span class="dn-pins-body">{{ $pin->body }}</span>
                                            <button type="button" class="dn-pins-action" wire:click="reply({{ $pin->id }})" aria-label="{{ __('Quote this push') }}">
                                                <x-feed.icon name="reply" :size="13" />
                                            </button>
                                            <button type="button" class="dn-pins-action" wire:click="pin({{ $pin->id }})" aria-label="{{ __('Unpin this push') }}">
                                                <x-feed.icon name="close" :size="13" />
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif

                    <div
                        class="dn-thread-body"
                        data-test="remote-messages"
                        {{-- A conversation is read from the bottom. `margin-top:
                             auto` in the stylesheet keeps a short thread on the
                             composer; this keeps a long one scrolled to the same
                             place, on load, on open and after a push. --}}
                        x-data
                        x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                        x-on:remote-scrolled.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                    >
                        @if ($this->thread === [])
                            <p class="dn-thread-empty">
                                empty history.
                                {{ $partner !== null ? __('say hi to @:handle', ['handle' => App\Support\LogIdentity::handleFor($partner)]) : __('say hi to the group') }}
                            </p>
                        @endif

                        @foreach ($this->thread as $message)
                            @if ($message['day'] !== null)
                                <p class="dn-day"><span>{{ $message['day'] }}</span></p>
                            @endif

                            <div class="dn-msg {{ $message['isMine'] ? 'is-mine' : '' }}" data-test="remote-message">
                                @if ($message['author'] && ! $message['isMine'])
                                    <span class="dn-msg-author">
                                        <span class="dn-avatar dn-msg-author-avatar" style="--dn-lane: {{ $message['lane'] }};">
                                            {{ $message['initials'] }}
                                        </span>
                                        <span class="dn-msg-author-name">{{ $message['name'] }}</span>
                                    </span>
                                @endif

                                <div class="dn-msg-col">
                                    @if ($message['reply'] !== null)
                                        <button type="button" class="dn-quote" wire:click="reply({{ $message['reply']['id'] }})" title="{{ __('Jump to the quoted push') }}">
                                            <span class="dn-quote-name">{{ $message['reply']['name'] }}</span>
                                            <span class="dn-quote-body">{{ $message['reply']['body'] }}</span>
                                        </button>
                                    @endif

                                    <span class="dn-bubble-wrap">
                                        <div class="dn-bubble {{ $message['isMine'] ? 'is-mine' : '' }} {{ $message['pinned'] ? 'is-pinned' : '' }}">
                                            <p class="dn-bubble-text">{{ $message['body'] }}</p>
                                        </div>

                                        {{-- Beside the bubble, not inside it: the
                                             text stays whole, and a menu that
                                             never covers a word is a menu you can
                                             use while reading. --}}
                                        <div class="dn-bubble-tools" x-data>
                                            <button type="button" class="dn-bubble-tool" wire:click="reply({{ $message['id'] }})" aria-label="{{ __('Quote this push') }}" data-test="remote-reply">
                                                <x-feed.icon name="reply" :size="13" />
                                            </button>
                                            <button type="button" class="dn-bubble-tool" wire:click="tray({{ $message['id'] }})" aria-label="{{ __('React to this push') }}" aria-expanded="{{ $trayFor === $message['id'] ? 'true' : 'false' }}" data-test="remote-react">
                                                <span aria-hidden="true">☺</span>
                                            </button>
                                            <button type="button" class="dn-bubble-tool {{ $message['pinned'] ? 'is-on' : '' }}" wire:click="pin({{ $message['id'] }})" aria-pressed="{{ $message['pinned'] ? 'true' : 'false' }}" aria-label="{{ $message['pinned'] ? __('Unpin this push') : __('Pin this push') }}" data-test="remote-pin">
                                                <x-feed.icon name="pin" :size="13" />
                                            </button>
                                        </div>
                                    </span>

                                    @if ($trayFor === $message['id'])
                                        <div class="dn-tray" role="group" aria-label="{{ __('Reactions') }}">
                                            @foreach ($this->reactionSet as $emoji)
                                                <button type="button" class="dn-tray-emoji" wire:click="react({{ $message['id'] }}, '{{ $emoji }}')" aria-label="{{ __('React with :emoji', ['emoji' => $emoji]) }}">
                                                    {{ $emoji }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($message['reactions'] !== [])
                                        <div class="dn-reactions" data-test="remote-reactions">
                                            @foreach ($message['reactions'] as $chip)
                                                <button
                                                    type="button"
                                                    class="dn-reaction {{ $chip['mine'] ? 'is-mine' : '' }}"
                                                    wire:click="react({{ $message['id'] }}, '{{ $chip['emoji'] }}')"
                                                    aria-pressed="{{ $chip['mine'] ? 'true' : 'false' }}"
                                                    aria-label="{{ $chip['emoji'] }} {{ $chip['count'] }}"
                                                >{{ $chip['emoji'] }}<span class="dn-reaction-count">{{ $chip['count'] }}</span></button>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($message['status'] !== null)
                                        <p class="dn-seen" data-test="remote-seen">
                                            <span class="dn-status dn-status-{{ $message['status'] }}" aria-hidden="true">
                                                <svg width="{{ $message['status'] === 'pulled' ? 18 : 10 }}" height="10" viewBox="0 0 18 10" fill="none">
                                                    <circle cx="4" cy="5" r="3.4" fill="currentColor" />
                                                    @if ($message['status'] === 'pulled')
                                                        <path d="M4 5h10" stroke="var(--dn-rail)" stroke-width="1.5" />
                                                        <circle cx="14" cy="5" r="3.4" fill="var(--dn-primary)" />
                                                    @endif
                                                </svg>
                                            </span>
                                            {{-- The receipt is one moment, not two: once it has
                                                 been pulled, the interesting time is when, so
                                                 that is what the line prints. --}}
                                            @if ($message['status'] === 'pulled' && $this->seenLabel !== '')
                                                <span>{{ __('pulled') }}</span>
                                                <time>{{ $this->seenLabel }}</time>
                                            @else
                                                <span>{{ __('pushed') }}</span>
                                                <time>{{ $message['time'] }}</time>
                                            @endif
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <form class="dn-remote-composer" wire:submit="push">
                        @if ($replyTo !== null)
                            @php $quoted = collect($this->thread)->firstWhere('id', $replyTo); @endphp

                            @if ($quoted !== null)
                                <div class="dn-composer-quote" data-test="remote-reply-banner">
                                    <span class="dn-composer-quote-name">{{ __('Replying to :name', ['name' => $quoted['name']]) }}</span>
                                    <span class="dn-composer-quote-body">{{ Str::limit($quoted['body'], 90) }}</span>
                                    <button type="button" class="dn-composer-quote-x" wire:click="cancelReply" aria-label="{{ __('Cancel the quote') }}">
                                        <x-feed.icon name="close" :size="13" />
                                    </button>
                                </div>
                            @endif
                        @endif

                        <label for="remote-draft" class="sr-only">{{ __('Message') }}</label>
                        <textarea
                            id="remote-draft"
                            wire:model="draft"
                            rows="1"
                            maxlength="2000"
                            placeholder="{{ $partner !== null ? __('Push to @:handle', ['handle' => App\Support\LogIdentity::handleFor($partner)]) : __('Push a message') }}"
                            x-on:keydown.meta.enter.prevent="$el.form.requestSubmit()"
                            x-on:keydown.ctrl.enter.prevent="$el.form.requestSubmit()"
                            data-test="remote-draft"
                        ></textarea>

                        @error('draft')
                            <p class="dn-form-error">{{ $message }}</p>
                        @enderror

                        <div class="dn-remote-actions">
                            <span class="dn-counter">⌘↵</span>
                            <button type="submit" class="dn-btn dn-btn-sm" wire:loading.attr="disabled">
                                {{ __('Push') }}
                            </button>
                        </div>
                    </form>
                @else
                    <div class="dn-thread-blank">
                        <p class="dn-thread-blank-line">no conversation checked out</p>
                        <button type="button" class="dn-btn dn-btn-sm" wire:click="pick">
                            <x-feed.icon name="pen" :size="14" />
                            {{ __('New remote') }}
                        </button>
                    </div>
                @endif
            </section>
        </div>
    @endif
</x-log.frame>
