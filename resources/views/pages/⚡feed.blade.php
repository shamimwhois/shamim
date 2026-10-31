<?php

use App\Actions\Feed\AttachFeedMedia;
use App\Actions\Feed\ComposeFeedPost;
use App\Actions\Feed\DetachFeedMedia;
use App\Actions\Feed\ScheduleFeedPost;
use App\Concerns\ReportsCommits;
use App\Concerns\SharesCommits;
use App\Concerns\VotesInPolls;
use App\Enums\FeedPostAudience;
use App\Models\FeedPost;
use App\Models\FeedReply;
use App\Models\FeedStar;
use App\Models\User;
use App\Support\CommitBody;
use App\Support\Seo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The Log — the /feed surface, drawn as a git log.
 *
 * A post is a **commit**, the timeline is a **log**, and the only engagement
 * verb is a **star**. The surface reads one collection ({@see commits()}) and
 * every widget on the page derives from it: the rail's lanes, the day markers,
 * the root node, the aside's active developers and the search palette all come
 * from the rows this component already loaded, so a render costs one query
 * regardless of how many widgets paint.
 *
 * Three logs, one URL: `?log=following|latest|trending`. The tab is URL state
 * rather than a second page, so a log is linkable and the back button moves
 * between logs the way the tabs do.
 *
 * Everything that writes is gated. A guest reads every public commit; starring,
 * replying and committing each check for an account and send the visitor to the
 * sign-in dialog instead of failing silently.
 */
new #[Title('The Log')] #[Layout('layouts::feed')] class extends Component
{
    use ReportsCommits;
    use SharesCommits;
    use VotesInPolls;
    use WithFileUploads;

    /** How many authors share the six lane colours. */
    private const LANES = 6;

    /** Commits rendered before "Load older commits" is offered. */
    private const PAGE_SIZE = 12;

    /** The three logs, in tab order. */
    private const LOGS = ['following', 'latest', 'trending'];

    /** A commit's body limit, in characters. */
    private const MAX_BODY = 4000;

    /** A reply's body limit, in characters. */
    private const MAX_REPLY = 1000;

    /** After how many commits the "who to follow" interlude is placed. */
    private const INTERLUDE_AFTER = 5;

    /** Which log is showing. Bound to `?log=`. */
    #[Url(as: 'log')]
    public string $log = 'latest';

    /** Full-text filter across author, handle, body and tags. Bound to `?q=`. */
    #[Url]
    public string $q = '';

    /** Tag filter; null = every tag. Bound to `?tag=`. */
    #[Url]
    public ?string $tag = null;

    /** Commit type filter; `all` = every type. Bound to `?type=`. */
    #[Url(as: 'type')]
    public string $type = 'all';

    /** Topic filter; `all` = every topic. Bound to `?topic=`. */
    #[Url(as: 'topic')]
    public string $topic = 'all';

    /** The composer's unsent body. */
    public string $draft = '';

    /**
     * The images the composer is holding, still in Livewire's staging area.
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>|\Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null
     */
    public $uploads = null;

    /** Ownership key for images staged before the commit exists. */
    public string $tempKey = '';

    /** The composer is set to hand the commit to the scheduler, not to the log. */
    public bool $scheduling = false;

    /** The moment a scheduled commit goes live. */
    public string $scheduledAt = '';

    /** The composer is writing a poll instead of a plain commit. */
    public bool $askingPoll = false;

    /** The choices the poll offers, as the author typed them. */
    public array $pollOptions = ['', ''];

    /** When the poll closes, in whatever words the author chose. */
    public string $pollCloses = '';

    /** The commit whose thread is expanded, if any. */
    public ?int $threadId = null;

    /** The commit whose reply composer is open, if any. */
    public ?int $replyTo = null;

    /** The reply being written. */
    public string $replyDraft = '';

    /**
     * The commit being edited inline, if any.
     *
     * Only one row edits at a time. A log where three rows stood open would
     * lose track of which body belongs to which commit, and the reader would
     * have to close the others by hand.
     */
    public ?int $editingId = null;

    /** The body as the reader is rewriting it. */
    public string $editDraft = '';

    /** How many commits the stream currently renders. */
    public int $visible = self::PAGE_SIZE;

    /** The palette's query. */
    public string $search = '';

    /** Whether the palette is open. */
    public bool $searchOpen = false;

    /** A transient mono status line, printed the way `git commit` reports. */
    public ?string $toast = null;

    /** Ids of commits that arrived from the live poll, so they can animate once. */
    public array $freshIds = [];

    /** The newest commit id the stream has already shown. */
    public int $seenThroughId = 0;

    /** Commits waiting behind the "new commits" pill. */
    public int $pendingNew = 0;

    /** The commit this reader last saw, so the log can print `HEAD@{last visit}`. */
    public ?int $lastVisitId = null;

    /** The commit the last delete removed, while it can still be restored. */
    public ?int $undoableId = null;

    public function mount(): void
    {
        // The head partial reads its metadata from the per-request Seo
        // singleton; the #[Title] attribute alone never reaches it.
        app(Seo::class)->set([
            'title' => 'The Log',
            'description' => __('The community log: what developers shipped, in the order they shipped it.'),
        ]);

        if (! in_array($this->log, self::LOGS, true)) {
            $this->log = 'latest';
        }

        /*
         * A filter that no longer matches anything is a dead URL somebody
         * bookmarked, not an error: it falls back to `all` rather than
         * rendering an empty log with no explanation. The vocabulary is read
         * from the corpus, so a new commit type needs no code change here.
         */
        $this->type = $this->known($this->type, 'type');
        $this->topic = $this->known($this->topic, 'topic');

        // The stream starts caught up: everything already in the corpus is
        // visible, and only commits arriving from here on are held back.
        $this->seenThroughId = (int) FeedPost::query()->visible()->max('id');

        // Where the reader left off last time, then remember where they are
        // now. Reading the marker before writing it is what makes the line
        // mean "since you were last here" rather than "since this render".
        $seen = (int) session()->get('feed.last_visit_id', 0);
        $this->lastVisitId = $seen > 0 && $seen < $this->seenThroughId ? $seen : null;

        session()->put('feed.last_visit_id', $this->seenThroughId);
    }

    /**
     * Narrow a URL filter to a value the corpus actually carries.
     */
    private function known(string $value, string $column): string
    {
        if ($value === 'all') {
            return 'all';
        }

        $exists = FeedPost::query()->visible()->where($column, $value)->exists();

        return $exists ? $value : 'all';
    }

    /**
     * Poll for commits that arrived since the page opened.
     *
     * They are counted, not inserted: a stream that moves under the reader's
     * scroll position is worse than one that is a few seconds stale.
     */
    public function syncFeed(): void
    {
        $latest = (int) FeedPost::query()->visible()->max('id');

        if ($latest <= $this->seenThroughId) {
            $this->pendingNew = 0;

            return;
        }

        $this->pendingNew = $this->commits
            ->filter(fn (FeedPost $post): bool => $post->id > $this->seenThroughId)
            ->count();
    }

    /**
     * Fold the announced commits into the stream, once, with an entry animation.
     */
    public function showNewCommits(): void
    {
        $this->freshIds = FeedPost::query()
            ->visible()
            ->where('id', '>', $this->seenThroughId)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $this->seenThroughId = (int) FeedPost::query()->visible()->max('id');
        $this->pendingNew = 0;
        $this->visible += count($this->freshIds);

        unset($this->commits, $this->starredIds);
    }

    /**
     * A commit the reader just published joins the stream at once.
     *
     * The author pressed Commit, so the row appearing at the top is the
     * confirmation — it is not held behind the pill.
     */
    #[On('feed-post-published')]
    public function onCommitPublished(int $id): void
    {
        unset($this->commits, $this->starredIds, $this->activeAuthors, $this->stats);

        $this->freshIds = [$id];
        $this->seenThroughId = max($this->seenThroughId, $id);
        $this->pendingNew = 0;
        $this->visible += 1;
        $this->draft = '';
        $this->log = 'latest';
    }

    /**
     * Any filter change restarts paging from the top of the new set.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['log', 'q', 'tag', 'type', 'topic'], true)) {
            $this->visible = self::PAGE_SIZE;
        }
    }

    /** Reveal the next page of commits. */
    public function loadMore(): void
    {
        $this->visible += self::PAGE_SIZE;
    }

    /**
     * Publish the composer's body as a commit.
     *
     * The write goes through {@see ComposeFeedPost} — the same action the
     * composer dialog uses — so the rate limit, the publish gate and the
     * byline snapshot are identical no matter which composer started it.
     * When the composer is holding images, their staging key travels with
     * the write and the action claims them for the row it creates.
     *
     * When the composer is set to schedule instead, the commit goes to the
     * scheduler: the same content write, then a publication moment. The
     * staged images are claimed afterwards, because the scheduler returns
     * the row rather than taking a key.
     */
    public function push(): void
    {
        $reader = $this->reader();

        if ($reader === null) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        $this->validate([
            'draft' => ['required', 'string', 'max:'.self::MAX_BODY],
        ], attributes: ['draft' => __('post')]);

        $data = [
            'body' => trim($this->draft),
            'title' => null,
            'type' => $this->askingPoll ? 'poll' : 'discussion',
            'topic' => 'community',
            'tags' => [],
            'audience' => FeedPostAudience::Public,
            'community_id' => null,
            'ai_label' => null,
            'code' => null,
            'code_file' => null,
            // The write path shapes the poll (two distinct choices minimum,
            // votes re-summed, empties dropped), so the composer hands over
            // what the author typed and lets the action decide what survives.
            'poll' => $this->askingPoll ? [
                'options' => $this->pollOptions,
                'closes' => trim($this->pollCloses),
            ] : null,
            'activity' => null,
        ];

        if ($this->scheduling) {
            $this->validate([
                'scheduledAt' => ['required', 'date', 'after:now'],
            ], attributes: ['scheduledAt' => __('schedule')]);
        }

        try {
            $post = $this->scheduling
                ? app(ScheduleFeedPost::class)->handle($reader, $data, $this->scheduledAt)
                : app(ComposeFeedPost::class)->handle($reader, $data, null, $this->tempKey);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            // The scheduler refuses a moment in the past under its own key, so
            // the message is relayed rather than lost to a validator nobody
            // asked about.
            if ($exception->errors()['scheduled_at'] ?? false) {
                $this->addError('scheduledAt', $exception->errors()['scheduled_at'][0]);
            }

            throw $exception;
        }

        if ($this->scheduling && $this->tempKey !== '') {
            app(AttachFeedMedia::class)->claim($post, $this->tempKey, $reader);
        }

        $this->resetComposer();

        if (! $this->scheduling) {
            // A scheduled commit is not in the log yet — no fresh row to
            // animate, no pill to raise. `commit-published` still goes out:
            // it only clears the composer's client-side draft.
            $this->onCommitPublished($post->id);
        }

        $this->toast = $this->scheduling
            ? '[scheduled] '.Str::limit(Str::squish($post->body), 48)
            : '[log '.CommitBody::shortHash($post->slug).'] '.Str::limit(
                Str::squish($post->body),
                48,
            );

        $this->dispatch('commit-published');
    }

    /**
     * Put the composer away after a write.
     *
     * The words and the images are spent; the schedule choice is a preference
     * about the *next* commit and stays where the reader left it.
     *
     * The poll flag closes with the poll. Leaving it open was the quiet bug: the
     * choices were cleared but the mode was not, so the next post — written as
     * an ordinary thought — was filed as a poll and refused by the write path
     * with "a poll needs two choices that differ". The author saw a poll error
     * for a post that was never meant to be one. A mode without its fields is
     * not a neutral state; it is a half-open editor.
     */
    private function resetComposer(): void
    {
        $this->draft = '';
        $this->uploads = null;
        $this->tempKey = '';
        $this->scheduledAt = '';
        $this->askingPoll = false;
        $this->pollOptions = ['', ''];
        $this->pollCloses = '';

        unset($this->stagedMedia);
    }

    /**
     * The images staged under this composer's key.
     *
     * Only evaluated once the composer is holding something: a reader who
     * never opens the picker pays nothing for the grid.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\FeedAttachment>
     */
    #[Computed]
    public function stagedMedia(): \Illuminate\Support\Collection
    {
        $reader = $this->reader();

        if ($reader === null || $this->tempKey === '') {
            return \Illuminate\Support\Collection::make();
        }

        return \App\Models\FeedAttachment::query()
            ->where('user_id', $reader->id)
            ->where('temp_key', $this->tempKey)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The images already on the row the editor has open.
     *
     * A separate reading from {@see stagedMedia()} on purpose. A staged file is
     * keyed by the composer's temp key and has no post; an edit's file belongs
     * to a post that already exists, and the two lists are never both live — the
     * editor and the composer cannot be open at once. Reading them through one
     * accessor would mean guessing which one the caller meant from whatever
     * happened to be set.
     *
     * Only read while the editor is open, so the query is paid for by the one
     * row being edited and not by every row on the page.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\FeedAttachment>
     */
    #[Computed]
    public function editingMedia(): \Illuminate\Support\Collection
    {
        $reader = $this->reader();

        if ($reader === null || $this->editingId === null) {
            return \Illuminate\Support\Collection::make();
        }

        return \App\Models\FeedAttachment::query()
            ->where('feed_post_id', $this->editingId)
            ->where('user_id', $reader->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Store what Livewire has just finished transferring.
     *
     * Files are staged before any row exists — that is what the temp key is
     * for — and claimed by the write. A file the disk refuses is reported by
     * the action and never reaches the grid.
     *
     * The same property feeds two surfaces, because there is only ever one real
     * file field on the page: with the editor open a pick lands on the row being
     * edited, and otherwise it stages for the next commit. Both go through
     * {@see AttachFeedMedia}, so the bytes are read back from the disk once, in
     * one place, whichever destination they are heading for.
     */
    public function updatedUploads(): void
    {
        $reader = $this->reader();

        if ($reader === null) {
            $this->uploads = null;

            return;
        }

        /*
         * Everything the hook is handed has to be dropped by the time it
         * returns, whatever the outcome — and `validate()` throws on a refusal,
         * so the drop belongs in a `finally` rather than at the end.
         *
         * Livewire's staging copies are single-use: they are moved onto the
         * media disk on success and gone by the end of the request either way,
         * so a survivor is a live object pointing at a file that is no longer
         * there. That is not a harmless leak, because `validate()` reads the
         * whole property: the dead entries were re-decided on every later pick,
         * so one undersized image drew its error again and again and each try
         * appended a key instead of replacing the last. A reader who picked a
         * bad file once could not get a clean error list back.
         *
         * The batch is deliberately *left on the property* until validation has
         * run, so Laravel can still key each failure by its own index
         * (`uploads.0`, not a single `uploads`). Only then is it cleared.
         */
        $files = collect($this->uploads ?? [])->filter()->values()->all();

        // Which row is the destination. An open editor takes the pick; with no
        // editor this is a fresh post and the file stages under the temp key.
        $post = $this->editingId !== null ? $this->editablePost() : null;

        try {
            if ($files === []) {
                return;
            }

            /** @var int $cap */
            $cap = (int) config('feed.composer.max_attachments', 8);

            /*
             * The cap belongs to the post, not to one turn of the file picker.
             * `max:` on the incoming array only ever sees this batch, so eight
             * staged images plus eight more would sail through as two legal
             * batches and publish sixteen. The modal composer has always
             * checked the total; this path did not.
             *
             * The count is whatever the destination already holds: staged rows
             * for a new post, the row's own attachments for an edit. Reading
             * only the staged list would let an edit take a post already at the
             * cap up to the cap *again*.
             */
            $existing = $post !== null ? $this->editingMedia->count() : $this->stagedMedia->count();

            if ($existing + count($files) > $cap) {
                $this->addError('uploads', __('A post can carry at most :count images.', ['count' => $cap]));

                return;
            }

            $this->validate([
                'uploads' => ['array', 'max:'.$cap],
                'uploads.*' => [
                    'image',
                    'mimes:jpg,jpeg,png,webp,gif',
                    'max:'.(int) config('feed.composer.max_upload_kilobytes', 8192),
                    // A 1×1 transparent PNG is a valid image and a useless one,
                    // and a 12000 px panorama will blow the card layout. The
                    // modal has always refused both; this path accepted them.
                    'dimensions:min_width=16,min_height=16,max_width=6000,max_height=6000',
                ],
            ], [
                'uploads.*.image' => __('Only image files can be attached to a post.'),
                'uploads.*.dimensions' => __('That image is too small or too large to attach.'),
            ]);

            if ($post !== null) {
                app(AttachFeedMedia::class)->handle($post, $reader, $files);
            } else {
                if ($this->tempKey === '') {
                    $this->tempKey = (string) Str::uuid();
                }

                app(AttachFeedMedia::class)->handle(null, $reader, $files, $this->tempKey);
            }
        } finally {
            $this->uploads = null;
        }

        unset($this->stagedMedia, $this->editingMedia);

        // The row carries the images it renders, and that list was read with the
        // page. An edit that changes the count has to drop it or the card shows
        // the images it had before the pick landed.
        if ($post !== null) {
            unset($this->commits, $this->rows);
        }
    }

    /**
     * The row the open editor is allowed to write to, or nothing.
     *
     * Ownership is re-established here rather than trusted from `editingId`,
     * for the same reason every other wire action does it: the id arrives from
     * the browser, so a caller who is not the author must not be able to reach
     * the row by naming it. `openEdit()` already checks this, but an edit left
     * open while the page re-renders is not a guarantee about the request that
     * is arriving now.
     */
    private function editablePost(): ?FeedPost
    {
        $reader = $this->reader();

        if ($reader === null || $this->editingId === null) {
            return null;
        }

        return FeedPost::query()->visible()->where('user_id', $reader->id)->find($this->editingId);
    }

    /**
     * Tell the reader an attach failed on the wire.
     *
     * The picker path reports through `uploads` because a validation failure is
     * something the server decides. A transfer that never arrives is different:
     * no file reached the server, so nothing there can describe it, and the
     * only honest message is the one the browser can raise. Both land on the
     * same error slot so the panel needs one place to look.
     */
    public function reportAttachFailure(): void
    {
        $this->addError('uploads', __('That upload did not finish. Try attaching it again.'));
    }

    /**
     * Delete one staged image and the bytes under it.
     */
    public function removeMedia(int $id): void
    {
        $reader = $this->reader();

        if ($reader === null || $this->tempKey === '') {
            return;
        }

        $attachment = \App\Models\FeedAttachment::query()
            ->where('user_id', $reader->id)
            ->where('temp_key', $this->tempKey)
            ->find($id);

        if ($attachment === null) {
            return;
        }

        app(DetachFeedMedia::class)->handle($attachment);

        unset($this->stagedMedia);
    }

    /**
     * Delete one image from the row the editor has open.
     *
     * The sibling of {@see removeMedia()}, and deliberately its own method: that
     * one is scoped to the composer's `temp_key` and cannot reach a file that
     * belongs to a post, which is the whole point of the split. The scope here
     * is the *edited post*, re-read through {@see editablePost()} so the id the
     * browser sent is checked against ownership before anything is deleted —
     * and an attachment id is not trusted to imply its own parent either, which
     * is why the query filters on the post rather than looking the row up by id
     * and checking `feed_post_id` afterwards.
     *
     * The file is gone from the disk as well as the row: an image the author
     * removed must not stay reachable at the URL it used to have.
     */
    public function removeEditMedia(int $id): void
    {
        $post = $this->editablePost();

        if ($post === null) {
            return;
        }

        $attachment = \App\Models\FeedAttachment::query()
            ->where('feed_post_id', $post->id)
            ->find($id);

        if ($attachment === null) {
            return;
        }

        app(DetachFeedMedia::class)->handle($attachment);

        unset($this->editingMedia, $this->commits, $this->rows);
    }

    /**
     * Flip the composer between committing now and handing the commit to the
     * scheduler.
     */
    public function toggleScheduling(): void
    {
        $this->scheduling = ! $this->scheduling;
    }

    /** How many choices a poll may offer. */
    #[Computed]
    public function pollMaxOptions(): int
    {
        return (int) config('feed.composer.poll_max_options', 6);
    }

    /**
     * Open or close the poll editor.
     *
     * Closing throws the choices away: a poll half-written is not a draft of
     * anything, and a reopen that resurrected yesterday's options would be a
     * surprise, not a convenience.
     */
    public function togglePoll(): void
    {
        $this->askingPoll = ! $this->askingPoll;

        if (! $this->askingPoll) {
            $this->pollOptions = ['', ''];
            $this->pollCloses = '';
        }
    }

    /** Offer one more choice, up to the poll's ceiling. */
    public function addPollOption(): void
    {
        if (count($this->pollOptions) >= $this->pollMaxOptions) {
            return;
        }

        $this->pollOptions[] = '';
    }

    /** Take a choice back, but never below the two that make a poll a poll. */
    public function removePollOption(int $index): void
    {
        if (count($this->pollOptions) <= 2 || ! isset($this->pollOptions[$index])) {
            return;
        }

        unset($this->pollOptions[$index]);

        $this->pollOptions = array_values($this->pollOptions);
    }

    /**
     * Star or unstar a commit.
     *
     * The row is the state, so toggling is a delete or an insert against the
     * unique pair; the count follows from it and cannot drift.
     */
    public function toggleStar(int $id): void
    {
        $reader = $this->reader();

        if ($reader === null) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        if (! FeedPost::query()->visible()->whereKey($id)->exists()) {
            return;
        }

        $star = FeedStar::query()
            ->where('feed_post_id', $id)
            ->where('user_id', $reader->id)
            ->first();

        if ($star !== null) {
            $star->delete();
        } else {
            FeedStar::query()->create([
                'feed_post_id' => $id,
                'user_id' => $reader->id,
            ]);

            $post = FeedPost::query()->find($id);

            if ($post !== null) {
                app(\App\Actions\Feed\NotifyCommitActivity::class)->starred($post, $reader);
            }
        }

        unset($this->commits, $this->starredIds, $this->stats);
    }

    /** Expand or collapse a commit's thread. */
    public function toggleThread(int $id): void
    {
        $this->threadId = $this->threadId === $id ? null : $id;
        $this->replyTo = null;
    }

    /** Open the inline reply composer under a commit. */
    public function openReply(int $id): void
    {
        $this->threadId = $id;
        $this->replyTo = $id;
        $this->replyDraft = '';
    }

    /** Close the reply composer without discarding the thread. */
    public function cancelReply(): void
    {
        $this->replyTo = null;
        $this->replyDraft = '';
    }

    /** Add a reply to a commit's thread. */
    public function postReply(int $id): void
    {
        $reader = $this->reader();

        if ($reader === null) {
            $this->redirectRoute('feed.index', ['signin' => 'required']);

            return;
        }

        $this->validate([
            'replyDraft' => ['required', 'string', 'max:'.self::MAX_REPLY],
        ], attributes: ['replyDraft' => __('reply')]);

        $post = FeedPost::query()->visible()->find($id);

        if ($post === null) {
            return;
        }

        $reply = FeedReply::query()->create([
            'feed_post_id' => $post->id,
            'user_id' => $reader->id,
            'body' => trim($this->replyDraft),
        ]);

        app(\App\Actions\Feed\NotifyCommitActivity::class)->replied($post, $reply, $reader);

        $this->threadId = $post->id;
        $this->replyTo = null;
        $this->replyDraft = '';

        unset($this->commits, $this->stats);
    }

    /**
     * Open the inline editor on a commit the reader wrote.
     *
     * Gated on ownership the same way the delete is, and for the same reason:
     * the button is only drawn on an own row, but the action has to be safe on
     * its own — a wire call is a public endpoint, so the guard belongs here and
     * not in the template.
     */
    public function openEdit(int $id): void
    {
        $reader = $this->reader();

        if ($reader === null) {
            return;
        }

        $post = FeedPost::query()->visible()->where('user_id', $reader->id)->find($id);

        if ($post === null) {
            return;
        }

        $this->editingId = $post->id;
        $this->editDraft = (string) $post->body;

        // Editing and replying to the same row at once would put two forms in
        // the same column, and the reader would not know which one submits.
        $this->threadId = null;
        $this->replyTo = null;
        $this->replyDraft = '';

        unset($this->editingMedia);
    }

    /** Put the editor away without writing anything. */
    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editDraft = '';

        // An upload still in flight when the editor closes would otherwise land
        // on whichever row is edited next, because the destination is read from
        // `editingId` at the moment the hook runs. Drop it with the editor.
        $this->uploads = null;

        unset($this->editingMedia);
    }

    /**
     * Write the edit back.
     *
     * `UpdateFeedPost` owns the two questions an edit raises that a first write
     * does not — the publishing state is kept, and a changed audience is
     * checked against the owner-only `publish` grant — so this method only has
     * to establish that the reader may edit *this* row at all.
     */
    public function saveEdit(): void
    {
        $reader = $this->reader();

        if ($reader === null || $this->editingId === null) {
            return;
        }

        $post = FeedPost::query()->visible()->where('user_id', $reader->id)->find($this->editingId);

        if ($post === null) {
            $this->cancelEdit();

            return;
        }

        $this->validate([
            'editDraft' => ['required', 'string', 'max:'.self::MAX_BODY],
        ], attributes: ['editDraft' => __('post')]);

        app(\App\Actions\Feed\UpdateFeedPost::class)->handle($reader, [
            'body' => trim($this->editDraft),
            'title' => $post->title,
            'type' => $post->type,
            'topic' => $post->topic,
            'tags' => array_values($post->tags ?? []),
            'community_id' => $post->community_id,
            'ai_label' => $post->ai_label,
            'code' => $post->code,
            'code_file' => $post->code_file,
            'activity' => $post->activity,
        ], $post);

        $this->editingId = null;
        $this->editDraft = '';

        unset($this->commits, $this->rows, $this->stats, $this->editingMedia);

        $this->toast = 'amend '.CommitBody::shortHash($post->slug).' → HEAD';
    }

    /**
     * Delete a commit the reader wrote.
     *
     * Deletion is a tombstone, not a row disappearing: the conversation under
     * the commit has to survive. The row stays in the stream, authorless.
     */
    public function deleteCommit(int $id): void
    {
        $reader = $this->reader();

        if ($reader === null) {
            return;
        }

        $post = FeedPost::query()->visible()->where('user_id', $reader->id)->find($id);

        if ($post === null) {
            return;
        }

        $post->forceFill(['deleted' => true])->save();

        unset($this->commits, $this->starredIds, $this->stats);

        $this->undoableId = $post->id;
        $this->toast = 'rm '.CommitBody::shortHash($post->slug).' → /dev/null';
    }

    /**
     * Put back the commit the last delete removed.
     *
     * A delete is the one irreversible-looking action on the page, so it is
     * reversible for as long as the toast stands. The row is restored rather
     * than re-created, which keeps its stars, its thread and its id — the
     * conversation under it never knew it was gone.
     */
    public function undoDelete(): void
    {
        $reader = $this->reader();

        if ($this->undoableId === null || $reader === null) {
            return;
        }

        $post = FeedPost::query()
            ->where('user_id', $reader->id)
            ->find($this->undoableId);

        $this->undoableId = null;
        $this->toast = null;

        if ($post === null) {
            return;
        }

        $post->forceFill(['deleted' => false])->save();

        unset($this->commits, $this->starredIds, $this->stats);

        $this->toast = 'restore '.CommitBody::shortHash($post->slug).' → HEAD';
    }

    /**
     * The URL for a filtered view of the log.
     *
     * Filters compose, so a chip cannot build its own link by hand: changing
     * the type has to keep the topic, the tag and the query, and clearing one
     * has to leave the others alone. One builder means every chip agrees about
     * which parameters are live and which are simply `all`.
     *
     * @param  array<string, string|null>  $overrides
     */
    public function filterUrl(array $overrides = []): string
    {
        $params = array_filter([
            'log' => $this->log,
            'type' => $this->type,
            'topic' => $this->topic,
            'tag' => $this->tag,
            'q' => trim($this->q),
        ], fn (?string $value): bool => $value !== null && $value !== '' && $value !== 'all');

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '' || $value === 'all') {
                unset($params[$key]);

                continue;
            }

            $params[$key] = $value;
        }

        return route('feed.index', $params);
    }

    /** Open the palette. */
    public function openSearch(): void
    {
        $this->searchOpen = true;
    }

    /** Close the palette and forget its query. */
    public function closeSearch(): void
    {
        $this->searchOpen = false;
        $this->search = '';
    }

    /**
     * Every commit the current log shows, in order.
     *
     * One query: the visible corpus with its star and reply counts. Ordering,
     * the `following` filter and the text filter all run in memory over those
     * rows, because the aside and the rail need the same set and a second query
     * would pay twice for the same page of commits.
     *
     * @return Collection<int, FeedPost>
     */
    #[Computed]
    public function commits(): Collection
    {
        $reader = $this->reader();

        $posts = FeedPost::query()
            ->visible()
            ->withCount(['stars', 'replies'])
            ->with(['replies.user', 'attachments'])
            ->get()
            ->filter(fn (FeedPost $post): bool => $post->isVisibleTo($reader))
            ->values();

        if ($this->log === 'following') {
            $topics = $this->followedTopics();

            $posts = $posts->filter(
                fn (FeedPost $post): bool => in_array($post->topic, $topics, true),
            )->values();
        }

        if ($this->tag !== null) {
            $posts = $posts->filter(
                fn (FeedPost $post): bool => in_array($this->tag, $post->tags, true),
            )->values();
        }

        // The two category axes. Type is what the commit *is* — a question, a
        // showcase, a poll — and topic is the part of the stack it belongs to.
        // They compose with the tag and text filters rather than replacing
        // them, so `?type=question&tag=Laravel` is a real question.
        if ($this->type !== 'all') {
            $posts = $posts->filter(
                fn (FeedPost $post): bool => $post->type === $this->type,
            )->values();
        }

        if ($this->topic !== 'all') {
            $posts = $posts->filter(
                fn (FeedPost $post): bool => $post->topic === $this->topic,
            )->values();
        }

        $needle = mb_strtolower(trim($this->q));

        if ($needle !== '') {
            $posts = $posts->filter(
                fn (FeedPost $post): bool => str_contains(
                    mb_strtolower((string) $post->title.' '.$post->body.' '.$post->author.' '.$post->handle.' '.implode(' ', $post->tags)),
                    $needle,
                ),
            )->values();
        }

        return match ($this->log) {
            'trending' => $posts->sortByDesc(
                fn (FeedPost $post): int => ((int) $post->getAttribute('stars_count')) * 3
                    + $post->reactionSum()
                    + ((int) $post->getAttribute('replies_count')) * 2,
            )->values(),
            default => $posts->sortByDesc(
                fn (FeedPost $post): int => ($post->posted_at ?? $post->created_at)?->getTimestamp() ?? 0,
            )->values(),
        };
    }

    /**
     * The slice of commits the stream renders.
     *
     * @return Collection<int, FeedPost>
     */
    #[Computed]
    public function rendered(): Collection
    {
        return $this->commits->take($this->visible)->values();
    }

    /** Whether more commits exist below the rendered slice. */
    #[Computed]
    public function hasMore(): bool
    {
        return $this->commits->count() > $this->visible;
    }

    /**
     * The commits the reader has starred, as a lookup.
     *
     * @return array<int, true>
     */
    #[Computed]
    public function starredIds(): array
    {
        $reader = $this->reader();

        if ($reader === null) {
            return [];
        }

        return FeedStar::query()
            ->where('user_id', $reader->id)
            ->pluck('feed_post_id')
            ->mapWithKeys(fn (int|string $id): array => [(int) $id => true])
            ->all();
    }

    /**
     * The developers the palette offers, filtered by the query.
     *
     * Developers, not commits: the palette answers "who", the log answers
     * "what". One row per author, busiest first.
     *
     * @return array<int, array{name: string, handle: string, initials: string, lane: string, commits: int, role: string|null}>
     */
    #[Computed]
    public function results(): array
    {
        $needle = mb_strtolower(trim($this->search));

        return $this->activeAuthors
            ->filter(fn (array $author): bool => $needle === ''
                || str_contains(mb_strtolower($author['name'].' '.$author['handle']), $needle))
            ->values()
            ->all();
    }

    /**
     * Every author in the corpus, busiest first.
     *
     * A corpus handle is a display string, not a foreign key, so it can outlive
     * the account it was written against — a retired author, a row seeded
     * before the account existed. `profileUrl` is null for those, and the
     * interlude prints the name without a link rather than sending the reader
     * to a 404.
     *
     * @return \Illuminate\Support\Collection<int, array{name: string, handle: string, initials: string, lane: string, commits: int, role: string|null, profileUrl: string|null}>
     */
    #[Computed]
    public function activeAuthors(): \Illuminate\Support\Collection
    {
        $known = User::query()
            ->whereNotNull('handle')
            ->pluck('handle')
            ->map(fn (string $candidate): string => mb_strtolower($candidate))
            ->all();

        $authors = [];

        foreach ($this->commits as $post) {
            $key = mb_strtolower(ltrim($post->handle, '@'));

            if (! isset($authors[$key])) {
                $authors[$key] = [
                    'name' => $post->author,
                    'handle' => ltrim($post->handle, '@'),
                    'initials' => $post->avatar,
                    'lane' => $this->lane($post->handle),
                    'commits' => 0,
                    'role' => $post->role,
                    'profileUrl' => in_array($key, $known, true)
                        ? route('profile.show', ['handle' => $key])
                        : null,
                ];
            }

            $authors[$key]['commits']++;
        }

        return collect($authors)
            ->sortByDesc('commits')
            ->values();
    }

    /**
     * The three developers the aside suggests, excluding the reader.
     *
     * @return array<int, array{name: string, handle: string, initials: string, lane: string, commits: int, role: string|null, profileUrl: string|null}>
     */
    #[Computed]
    public function whoToFollow(): array
    {
        $reader = $this->reader();

        return $this->activeAuthors
            ->reject(fn (array $author): bool => $reader !== null
                && mb_strtolower($author['handle']) === mb_strtolower((string) $reader->handle))
            // Only authors who can actually be opened: a suggestion whose link
            // 404s is worse than a shorter list.
            ->filter(fn (array $author): bool => $author['profileUrl'] !== null)
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * The aside's three counts: commits in the log, developers in it, and the
     * reader's own stars.
     *
     * @return array{commits: int, developers: int, stars: int}
     */
    #[Computed]
    public function stats(): array
    {
        $reader = $this->reader();

        return [
            'commits' => $this->commits->count(),
            'developers' => $this->activeAuthors->count(),
            'stars' => $reader === null
                ? 0
                : FeedStar::query()->where('user_id', $reader->id)->count(),
        ];
    }

    /**
     * The topics of the communities the reader joined — what `following` shows.
     *
     * Empty for guests and non-members, which is why that log has an honest
     * empty state rather than a list of demo handles.
     *
     * @return array<int, string>
     */
    private function followedTopics(): array
    {
        $reader = $this->reader();

        if ($reader === null) {
            return [];
        }

        return \App\Models\Community::query()
            ->whereIn('id', $reader->joinedCommunityIds())
            ->pluck('topic')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** The signed-in reader, if there is one. */
    private function reader(): ?User
    {
        $reader = auth()->user();

        return $reader instanceof User ? $reader : null;
    }

    /**
     * Forget the collections a write invalidated.
     *
     * The shared commit actions ({@see ReportsCommits}) cannot know which of
     * this page's computed properties went stale, so they call this instead.
     */
    protected function commitStateChanged(): void
    {
        unset(
            $this->commits,
            $this->rendered,
            $this->starredIds,
            $this->stats,
            $this->rows,
            // Sharing is a write to a ledger the page reads back, so both the
            // "did I share this" lookup and the per-row counts are stale the
            // moment the row lands.
            $this->sharedCommitIds,
            $this->shareCounts,
            // Reporting has the same shape and the same problem.
            $this->reportedCommitIds,
        );
    }

    /**
     * The lane colour a handle is assigned.
     *
     * Stable per author and derived from the handle, so the same developer is
     * the same colour on every row and every visit. Returned as a `var()`
     * reference because the value has to flip with the theme.
     */
    private function lane(string $handle): string
    {
        $index = crc32(mb_strtolower(ltrim($handle, '@'))) % self::LANES;

        return 'var(--dn-lane-'.$index.')';
    }

    /**
     * The rows the template renders: a commit, its rail, and its thread.
     *
     * Built by {@see \App\Support\LogRow}, the same presenter a profile and the
     * starred list use, so a row cannot look different on another surface.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        return \App\Support\LogRow::rows(
            $this->rendered,
            $this->starredIds,
            $this->threadId,
            $this->replyTo,
            $this->reader(),
            $this->freshIds,
            // The marker only means something in chronological order: a ranked
            // log has no "above" for a visit line to sit under.
            $this->log === 'latest' ? $this->lastVisitId : null,
            // Who to follow goes after the fifth commit, the way the reference
            // places it — far enough down to be a suggestion, not a banner.
            self::INTERLUDE_AFTER,
            // The commits this reader has already reported.
            $this->reportedCommitIds,
            // The votes cast on this install, by poll and by option.
            $this->pollVotes,
            // The commits this reader has passed on, and how many accounts
            // have passed on each — both read from `feed_shares`, never a
            // column that could drift.
            $this->sharedCommitIds,
            $this->shareCounts,
            // The row being edited, and the text it is being edited into.
            $this->editingId,
            $this->editDraft,
        );
    }

    /**
     * The commit types the corpus carries, with how many of each.
     *
     * Read from the data rather than a constant: a type nobody has filed yet is
     * a filter that would open an empty log, and a chip with no count is a chip
     * the reader has to guess at.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function typeOptions(): array
    {
        return FeedPost::query()
            ->visible()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->orderByDesc('total')
            ->pluck('total', 'type')
            ->map(fn (int|string $total): int => (int) $total)
            ->all();
    }

    /**
     * The topics the corpus carries, with how many of each.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function topicOptions(): array
    {
        return FeedPost::query()
            ->visible()
            ->selectRaw('topic, count(*) as total')
            ->groupBy('topic')
            ->orderByDesc('total')
            ->pluck('total', 'topic')
            ->map(fn (int|string $total): int => (int) $total)
            ->all();
    }

    /** Whether any filter is narrowing the log right now. */
    #[Computed]
    public function filtered(): bool
    {
        return $this->type !== 'all'
            || $this->topic !== 'all'
            || $this->tag !== null
            || trim($this->q) !== '';
    }

    /**
     * The authors behind the commits waiting in the pill.
     *
     * Avatars, not a number: "3 new commits" is a count, "three people you
     * follow shipped something" is a reason to scroll up.
     *
     * @return array<int, array{initials: string, lane: string, name: string}>
     */
    #[Computed]
    public function pendingAuthors(): array
    {
        if ($this->pendingNew === 0) {
            return [];
        }

        return FeedPost::query()
            ->visible()
            ->where('id', '>', $this->seenThroughId)
            ->selectRaw('author, handle, avatar')
            ->distinct()
            ->limit(3)
            ->get()
            ->map(fn (FeedPost $post): array => [
                'name' => $post->author,
                'initials' => $post->avatar,
                'lane' => $this->lane($post->handle),
            ])
            ->all();
    }
}; ?>

<x-log.frame
    active="log"
    data-test="log"
    wire:poll.30s="syncFeed"
    x-on:log:star.window="$wire.toggleStar($event.detail.id)"
    x-on:log:reply.window="$wire.openReply($event.detail.id)"
    x-on:log:thread.window="$wire.toggleThread($event.detail.id)"
>
    <x-slot:header>
        <div class="dn-header">
            <h1 class="sr-only">{{ __('The Log') }}</h1>

            {{-- The page's opening rule: a monospace label with a hairline
                 running to the edge. It is the motif every page in the product
                 now opens with, and on the log it doubles as the only visible
                 statement of what the surface is — the `h1` above is for the
                 screen reader, this line is for the eye. --}}
            <div class="dn-head-rule" aria-hidden="true">
                <span class="dn-head-rule-label">{{ __('The Log') }}</span>
                <span class="dn-head-rule-line"></span>
            </div>

            <nav class="dn-tabs" aria-label="{{ __('Logs') }}">
                @foreach (['following' => __('Following'), 'latest' => __('Latest'), 'trending' => __('Trending')] as $key => $label)
                    <a
                        href="{{ route('feed.index', ['log' => $key]) }}"
                        wire:navigate
                        class="dn-tab {{ $log === $key ? 'is-active' : '' }}"
                        @if ($log === $key) aria-current="page" @endif
                        data-test="log-tab-{{ $key }}"
                    >{{ $label }}</a>
                @endforeach
            </nav>

            {{--
                The categories. Two axes, both read from the corpus: what the
                commit *is* (a question, a showcase, a poll) and what part of the
                stack it belongs to. They compose with the tag and text filters,
                which is why every chip goes through `filterUrl()` — a chip that
                built its own link would silently drop the other three.
            --}}
            <div class="dn-categories" data-test="log-filters">
                <div class="dn-filter-row" role="group" aria-label="{{ __('Post type') }}">
                    <a
                        href="{{ $this->filterUrl(['type' => 'all']) }}"
                        wire:navigate
                        class="dn-chip {{ $type === 'all' ? 'is-on' : '' }}"
                        @if ($type === 'all') aria-current="page" @endif
                        data-test="filter-type-all"
                    >{{ __('all') }}</a>

                    @foreach ($this->typeOptions as $key => $count)
                        <a
                            href="{{ $this->filterUrl(['type' => $key]) }}"
                            wire:navigate
                            class="dn-chip {{ $type === $key ? 'is-on' : '' }}"
                            @if ($type === $key) aria-current="page" @endif
                            data-test="filter-type-{{ $key }}"
                        >{{ $key }}<span class="dn-chip-count">{{ $count }}</span></a>
                    @endforeach
                </div>

                <div class="dn-filter-row" role="group" aria-label="{{ __('Topic') }}">
                    @foreach ($this->topicOptions as $key => $count)
                        <a
                            href="{{ $this->filterUrl(['topic' => $topic === $key ? 'all' : $key]) }}"
                            wire:navigate
                            class="dn-chip is-quiet {{ $topic === $key ? 'is-on' : '' }}"
                            @if ($topic === $key) aria-current="page" @endif
                            data-test="filter-topic-{{ $key }}"
                        >{{ $key }}<span class="dn-chip-count">{{ $count }}</span></a>
                    @endforeach

                    @if ($tag !== null)
                        <a
                            href="{{ $this->filterUrl(['tag' => null]) }}"
                            wire:navigate
                            class="dn-chip is-on is-tag"
                            title="{{ __('Clear the tag filter') }}"
                            data-test="filter-tag-clear"
                        >
                            #{{ $tag }}
                            <x-feed.icon name="close" :size="11" />
                        </a>
                    @endif

                    @if (trim($q) !== '')
                        <a
                            href="{{ $this->filterUrl(['q' => null]) }}"
                            wire:navigate
                            class="dn-chip is-on is-tag"
                            title="{{ __('Clear the search filter') }}"
                            data-test="filter-q-clear"
                        >
                            “{{ $q }}”
                            <x-feed.icon name="close" :size="11" />
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </x-slot:header>

    @if ($pendingNew > 0)
        <div class="dn-pill-wrap">
            {{-- The pill scrolls the reader to what it is announcing: the click
                 and the jump are one gesture, so it never becomes a button that
                 reloads the stream under the reader's scroll position. --}}
            <button
                type="button"
                class="dn-pill"
                wire:click="showNewCommits"
                x-on:click="window.scrollTo({ top: 0, behavior: 'smooth' })"
                data-test="new-commits-pill"
            >
                <x-feed.icon name="up" :size="16" />

                <span class="dn-pill-faces" aria-hidden="true">
                    @foreach ($this->pendingAuthors as $author)
                        <span class="dn-avatar" style="--dn-lane: {{ $author['lane'] }}; width: 22px; height: 22px; font-size: 9px;">{{ $author['initials'] }}</span>
                    @endforeach
                </span>

                {{ trans_choice(':count new post|:count new posts', $pendingNew, ['count' => $pendingNew]) }}
            </button>
        </div>
    @endif

    @if ($this->reader() !== null)
        @include('feed.composer', [
            'reader' => $this->reader(),
            'lane' => \App\Support\LogIdentity::lane((string) $this->reader()->handle),
            'initials' => \App\Support\LogIdentity::initials($this->reader()->name),
            'max' => self::MAX_BODY,
            'pollMaxOptions' => $this->pollMaxOptions,
        ])
    @endif

    @php
        $rows = $this->rows;
    @endphp

    @if ($rows === [])
        <div class="dn-empty" data-test="log-empty">
            @if ($log === 'following')
                <p class="dn-empty-command">fatal: your log does not have any posts yet</p>
                <p class="dn-empty-text">
                    {{ __('Your log shows the topics of the communities you joined.') }}
                    @if ($this->reader() === null)
                        <a href="{{ route('login') }}" x-on:click="$flux.modal('login').show(); $event.preventDefault()">{{ __('Sign in') }}</a>
                        {{ __('to join one.') }}
                    @else
                        {{ __('Join a community to fill it.') }}
                    @endif
                    {{ __('Until then,') }}
                    <a href="{{ route('feed.index', ['log' => 'trending']) }}" wire:navigate>{{ __('Trending') }}</a>
                    {{ __('is where the conversation is.') }}
                </p>
            @elseif ($log === 'trending')
                <p class="dn-empty-command">fatal: nothing is trending yet</p>
                <p class="dn-empty-text">
                    {{ __('Stars and replies decide what trends. Nobody has given either yet.') }}
                    <a href="{{ route('feed.index', ['log' => 'latest']) }}" wire:navigate>{{ __('Latest') }}</a>
                    {{ __('is chronological and always full.') }}
                </p>
            @else
                <p class="dn-empty-command">fatal: no posts yet</p>
                <p class="dn-empty-text">
                    {{ __('Nobody has posted anything. Be the first. It is just plain text; you will be fine.') }}
                </p>
            @endif
        </div>
    @else
        <div class="dn-feed" data-test="log-feed">
            @foreach ($rows as $row)
                {{-- Every top-level node in this loop carries a `wire:key`.
                     The loop emits up to four different elements per row — a
                     visit line, a day marker, the commit itself, and the
                     follow interlude — and which of them appear changes from
                     render to render (the visit line is claimed once, the day
                     marker only when the day turns over, the interlude only
                     after the fifth commit).

                     Without a key Livewire morphs siblings by *position*: the
                     first child on the new page is matched to the first child
                     on the old one. Remove one row in the middle and every
                     commit below it shifts up a slot, so the node that used to
                     hold post 34 gets rewritten to describe post 35 — but its
                     `wire:click="deleteCommit(34)"` and `data-commit="34"`
                     and the new `data-commit="35"` on a different attribute
                     pass land out of step. The reader then presses Delete on
                     one row and a *different post is deleted*: a data-integrity
                     bug that is invisible in a static render and that every
                     server-side test passes, because `call()` never touched
                     the DOM.

                     The key is the row's identity plus the node's kind, so a
                     marker and the commit under it can never swap places. --}}
                @if ($row['lastVisit'])
                    <div
                        class="dn-marker is-visit"
                        role="separator"
                        aria-label="{{ __('Posts above are new since your last visit') }}"
                        data-test="last-visit"
                        wire:key="visit-{{ $row['id'] }}"
                    >
                        <div class="dn-rail">
                            <span class="dn-rail-line is-down is-dashed"></span>
                        </div>
                        <div class="dn-marker-content">
                            {{-- The git ref is a literal, so it is echoed rather
                                 than written into the markup: Blade reads a bare
                                 `@` as the start of a directive, and `@@` is
                                 not an escape it performs. --}}
                            <h3 class="dn-marker-label">{{ 'HEAD@{last visit}' }}</h3>
                            <span class="dn-marker-rule"></span>
                        </div>
                    </div>
                @endif

                @if ($loop->first || $row['day'] !== $rows[$loop->index - 1]['day'])
                    <div class="dn-marker" wire:key="day-{{ $row['id'] }}">
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
                    'maxReply' => self::MAX_REPLY,
                    'maxEdit' => self::MAX_BODY,
                ])

                @if ($row['interlude'] && $this->whoToFollow !== [])
                    @include('feed.interlude', [
                        'authors' => $this->whoToFollow,
                        'keyFor' => 'interlude-'.$row['id'],
                    ])
                @endif
            @endforeach

            @if ($this->hasMore)
                {{-- The sentinel is also the button: an observer opens the next
                     page when the reader approaches it, and the button is what
                     a keyboard, a screen reader or a browser without the script
                     still gets. --}}
                <div class="dn-more" x-data="feedMore()" data-test="load-older-sentinel">
                    <div class="dn-rail">
                        <span class="dn-rail-line is-down" style="background: {{ $rows[count($rows) - 1]['lane'] }}"></span>
                    </div>

                    <div class="dn-more-body">
                        <div class="dn-skeletons" wire:loading.class="is-loading" wire:target="loadMore" aria-hidden="true">
                            @for ($i = 0; $i < 2; $i++)
                                <div class="dn-skeleton">
                                    <span class="dn-skeleton-avatar"></span>
                                    <span class="dn-skeleton-body">
                                        <span class="dn-skeleton-line" style="width: 38%"></span>
                                        <span class="dn-skeleton-line" style="width: 88%"></span>
                                        <span class="dn-skeleton-line" style="width: 64%"></span>
                                    </span>
                                </div>
                            @endfor
                        </div>

                        <button
                            type="button"
                            class="dn-btn dn-btn-outline dn-btn-sm"
                            wire:click="loadMore"
                            wire:loading.attr="disabled"
                            data-test="load-older"
                        >
                            {{ __('Load older posts') }}
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
                    <p class="dn-root-text" data-test="log-root">
                        {{ __("That's the root post. There's nothing before it.") }}
                    </p>
                </div>
            @endif
        </div>
    @endif

    @if ($toast !== null)
        <div class="dn-toast" role="status" wire:key="toast-{{ md5($toast) }}">
            <span>{{ $toast }}</span>

            @if ($undoableId !== null)
                <button
                    type="button"
                    class="dn-toast-undo"
                    wire:click="undoDelete"
                    data-test="undo-delete"
                >{{ __('Undo') }}</button>
            @endif

            <button type="button" aria-label="{{ __('Dismiss') }}" x-on:click="$el.closest('.dn-toast').remove()">
                <x-feed.icon name="check" :size="14" />
            </button>
        </div>
    @endif
</x-log.frame>
