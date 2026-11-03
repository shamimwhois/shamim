<?php

use App\Actions\Feed\AttachFeedMedia;
use App\Actions\Feed\ComposeFeedPost;
use App\Actions\Feed\DeleteFeedPost;
use App\Actions\Feed\DetachFeedMedia;
use App\Actions\Feed\ScheduleFeedPost;
use App\Actions\Feed\SaveFeedDraft;
use App\Enums\FeedPostAudience;
use App\Models\Community;
use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The post composer.
 *
 * One write surface for the feed, opened from the composer card's prompt, its
 * Post button, or the header's Create action. Everything it does is delegated:
 * content is normalised by `HydrateFeedPostContent` inside `SaveFeedDraft`,
 * media is staged by `AttachFeedMedia`, and the three endings — draft, schedule,
 * publish — are `SaveFeedDraft`, `ScheduleFeedPost` and `ComposeFeedPost`. The
 * component decides *what to ask for*; it never decides *what is allowed*, which
 * is why each path re-authorises server-side and the disabled toolbar options
 * have no handler to fake.
 *
 * `mount()` runs no queries. The identity row reads the account the page has
 * already resolved, the community list is only evaluated once the audience is
 * switched to `community`, and the draft list is only loaded when the modal is
 * actually opened — so a reader who never composes pays nothing for the
 * composer, which is what keeps the feed's pinned query budget intact.
 *
 * Closing is not discarding. The dialog can be dismissed with Esc, the backdrop
 * or the Close button, and the words stay in the component (and in a draft row
 * once autosave has run); only an explicit "Discard" clears them. That is the
 * reason `beforeunload` guards typing but the in-modal confirm guards Close: the
 * first is real data loss, the second is not.
 */
new class extends Component
{
    use WithFileUploads;

    public string $type;

    public string $topic;

    public string $title = '';

    public string $body = '';

    /** @var array<int, string> */
    public array $tags = [];

    public string $audience;

    public ?int $communityId = null;

    public bool $aiLabel = false;

    public string $code = '';

    public string $codeFile = '';

    /** @var array<int, array{label: string, votes: int}> */
    public array $pollOptions;

    public string $pollCloses = '';

    public string $activityLabel = '';

    public string $activityDetail = '';

    public string $linkUrl = '';

    public string $scheduledAt = '';

    /** The draft row this composer is writing to, once it has one. */
    public ?int $draftId = null;

    /** Ownership key for images staged before any row exists. */
    public string $tempKey = '';

    /**
     * Livewire's temporary uploads, still in the staging directory.
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>|\Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null
     */
    public $uploads = null;

    /** Alt text per already-stored attachment id. */
    public array $alts = [];

    /** True once the composer has been opened at least once this page life. */
    public bool $opened = false;

    /** The Preview step has been taken, so the next click publishes. */
    public bool $previewed = false;

    /**
     * The composer is holding at least one image.
     *
     * Kept as a flag rather than read off `stagedMedia` because the question
     * "is there media here?" is asked on every render, while the answer must not
     * cost a query before the composer has even been opened.
     */
    public bool $hasMedia = false;

    /** The close confirmation panel is showing. */
    public bool $confirmingClose = false;

    /** The "More options" popover is open. */
    public bool $showMore = false;

    /** Something has been typed or chosen since the last reset. */
    public bool $dirty = false;

    /** Last autosaved content fingerprint, for autosave idempotence. */
    public string $signature = '';

    /** Buffer for the tag field, which is not itself a post attribute. */
    public string $tagInput = '';

    /** The Link option's inline field is open. */
    public bool $showLinkField = false;

    /** The Feeling / Activity block is open. */
    public bool $showActivity = false;

    public function mount(): void
    {
        /** @var string $type */
        $type = (string) config('feed.composer.default_type', 'discussion');
        /** @var string $topic */
        $topic = (string) config('feed.composer.default_topic', 'community');

        $this->type = in_array($type, $this->availableTypes(), true) ? $type : 'discussion';
        $this->topic = in_array($topic, $this->availableTopics(), true) ? $topic : 'community';
        $this->audience = FeedPostAudience::Public->value;
        $this->pollOptions = $this->blankPoll();
    }

    /**
     * Open the composer for a type chosen on the card beneath the feed header.
     *
     * Named `open` because that is the event the chips dispatch, and it carries
     * their choice rather than discarding it. Loading the drafts here, and only
     * here, is what keeps the first render free.
     */
    public function open(string $type = ''): void
    {
        if ($type !== '' && in_array($type, $this->availableTypes(), true)) {
            $this->type = $type;
        }

        $this->opened = true;

        $this->loadDrafts();
    }

    /**
     * Re-read the draft list, which the view only asks for once the modal opens.
     */
    public function loadDrafts(): void
    {
        unset($this->drafts);
    }

    /**
     * Any property change marks the composer dirty, and a changed alt text is
     * stored against the file it describes.
     */
    public function updated(string $name): void
    {
        $this->dirty = true;

        if ($name === 'audience') {
            $this->normaliseAudience();

            return;
        }

        if (str_starts_with($name, 'alts.')) {
            $this->storeAlt((int) Str::after($name, 'alts.'));
        }
    }

    /**
     * Persist whatever Livewire has just finished uploading.
     */
    public function updatedUploads(): void
    {
        $this->stageUploads();
    }

    /**
     * Write a draft without asking the author to press anything.
     *
     * Skipped — silently — while there is nothing to save and while the body is
     * longer than the feed will ever accept, because an over-length draft is not
     * a saveable state and a validation error every four seconds would be worse
     * than the counter that already says so in red.
     */
    public function autosave(): void
    {
        if (! $this->hasContent() || $this->overBodyLimit()) {
            return;
        }

        $signature = $this->fingerprint();

        if ($signature === $this->signature && $this->draftId !== null) {
            return;
        }

        $this->writeDraft();

        $this->signature = $signature;
    }

    /**
     * Save a draft on request, so the error is worth showing.
     */
    public function saveDraft(): void
    {
        $this->validateContent();

        if ($this->bodyLength() === 0) {
            $this->addError('body', __('Write something before saving a draft.'));

            return;
        }

        $this->writeDraft();

        $this->signature = $this->fingerprint();

        $this->dispatch('feed-draft-saved');
    }

    /**
     * Put the post in front of the community.
     *
     * The Preview step is taken on the way if the post has media or an audience
     * narrower than the web; a plain text post publishes on the first click,
     * because making an author confirm something with nothing to confirm is how
     * composers become chores.
     */
    public function publish(): void
    {
        $this->validateContent();

        if ($this->bodyLength() === 0) {
            $this->addError('body', __('There is nothing to publish yet.'));

            return;
        }

        if ($this->needsPreview() && ! $this->previewed) {
            $this->previewed = true;

            return;
        }

        $post = app(ComposeFeedPost::class)->handle(
            $this->authorOrFail(),
            $this->payload(),
            $this->currentDraft(),
            $this->tempKey,
        );

        $this->resetForm();

        $this->dispatch('feed-post-published', id: $post->id, slug: $post->slug);
        $this->dispatch('composer-close');
    }

    /**
     * Hand the post to the scheduler instead of to the feed.
     */
    public function schedule(): void
    {
        $this->validateContent();

        $this->validate([
            'scheduledAt' => ['required', 'date'],
        ]);

        if ($this->bodyLength() === 0) {
            $this->addError('body', __('There is nothing to schedule yet.'));

            return;
        }

        $post = app(ScheduleFeedPost::class)->handle(
            $this->authorOrFail(),
            $this->payload(),
            $this->scheduledAt,
            $this->currentDraft(),
        );

        $this->resetForm();

        $this->dispatch('feed-post-scheduled', id: $post->id);
        $this->dispatch('composer-close');
    }

    /**
     * Load one of the author's own drafts back into the composer.
     */
    public function restoreDraft(int $id): void
    {
        /** @var FeedPost|null $draft */
        $draft = FeedPost::query()->draftsOf((int) $this->authorOrFail()->id)->find($id);

        if ($draft === null) {
            unset($this->drafts);

            $this->dispatch('composer-notice', message: __('That draft is no longer here.'));

            return;
        }

        Gate::forUser($this->authorOrFail())->authorize('update', $draft);

        /** @var array<int, string> $tags */
        $tags = is_array($draft->tags) ? $draft->tags : [];

        /** @var array<string, mixed> $poll */
        $poll = is_array($draft->poll) ? $draft->poll : [];

        /** @var array{label?: string, detail?: string}|null $activity */
        $activity = is_array($draft->activity) ? $draft->activity : null;

        /** @var array<string, mixed> $detail */
        $detail = is_array($draft->detail) ? $draft->detail : [];

        $this->draftId = $draft->id;
        $this->body = (string) $draft->body;
        $this->title = (string) ($draft->title ?? '');
        $this->type = in_array((string) $draft->type, $this->availableTypes(), true) ? (string) $draft->type : $this->type;
        $this->topic = in_array((string) $draft->topic, $this->availableTopics(), true) ? (string) $draft->topic : $this->topic;
        $this->tags = array_values(array_filter($tags, 'is_string'));
        $this->audience = $draft->audience instanceof FeedPostAudience ? $draft->audience->value : FeedPostAudience::Public->value;
        $this->communityId = $draft->community_id;
        $this->code = (string) ($draft->code ?? '');
        $this->codeFile = (string) ($draft->code_file ?? '');
        $this->pollOptions = $this->restorePoll($poll);
        $this->pollCloses = (string) ($poll['closes'] ?? '');
        $this->activityLabel = (string) ($activity['label'] ?? '');
        $this->activityDetail = (string) ($activity['detail'] ?? '');
        $this->aiLabel = (bool) ($detail['ai_label'] ?? false);
        $this->tempKey = '';
        $this->hasMedia = $draft->attachments()->exists();
        $this->previewed = false;
        $this->confirmingClose = false;
        $this->dirty = false;
        $this->signature = $this->fingerprint();

        $this->dispatch('composer-restored', id: $draft->id);
    }

    /**
     * Retire a draft the author no longer means to finish.
     */
    public function discardDraftRow(int $id): void
    {
        /** @var FeedPost|null $draft */
        $draft = FeedPost::query()->find($id);

        if ($draft === null) {
            return;
        }

        app(DeleteFeedPost::class)->handle($this->authorOrFail(), $draft);

        if ($this->draftId === $id) {
            $this->resetForm();
        }

        $this->loadDrafts();
    }

    /**
     * Add a poll row, up to the configured ceiling.
     */
    public function addPollOption(): void
    {
        /** @var int $maximum */
        $maximum = (int) config('feed.composer.poll_max_options', 6);

        if (count($this->pollOptions) >= $maximum) {
            $this->addError('poll', __('A poll can offer at most :count choices.', ['count' => $maximum]));

            return;
        }

        $this->pollOptions[] = ['label' => '', 'votes' => 0];
    }

    public function removePollOption(int $index): void
    {
        unset($this->pollOptions[$index]);

        $this->pollOptions = array_values($this->pollOptions);

        if ($this->pollOptions === []) {
            $this->pollOptions = $this->blankPoll();
        }
    }

    public function addTag(): void
    {
        /** @var int $maximum */
        $maximum = (int) config('feed.composer.max_tags', 8);

        /** @var string $tag */
        $tag = Str::slug((string) $this->tagInput);

        $this->tagInput = '';

        if ($tag === '' || in_array($tag, $this->tags, true)) {
            return;
        }

        if (count($this->tags) >= $maximum) {
            $this->addError('tags', __(':count tags is the most a post can carry.', ['count' => $maximum]));

            return;
        }

        $this->tags[] = $tag;
    }

    public function removeTag(int $index): void
    {
        unset($this->tags[$index]);

        $this->tags = array_values($this->tags);
    }

    /**
     * Append a link to the post as the author typed it.
     *
     * The URL is content, not a fetch target: nothing here reaches out to the
     * address the author pasted, which is the whole reason the link-preview
     * option below stays disabled until a Phase can do it behind a limiter.
     */
    public function addLink(): void
    {
        $this->validate([
            'linkUrl' => ['required', 'url', 'max:2048'],
        ]);

        /** @var string $url */
        $url = trim((string) $this->linkUrl);

        $this->body = $this->body === '' ? $url : rtrim($this->body)."\n\n".$url;
        $this->linkUrl = '';
        $this->dirty = true;
    }

    /**
     * Delete one staged or drafted image and the bytes under it.
     */
    public function removeMedia(int $attachmentId): void
    {
        /** @var FeedAttachment|null $attachment */
        $attachment = FeedAttachment::query()->find($attachmentId);

        if ($attachment === null) {
            return;
        }

        // Removing media is an edit, not a view: `viewAttachment` would let any
        // reader of a published post detach its pictures, so the write travels
        // the authoring axis — `removeAttachment` — instead.
        abort_unless(
            Gate::forUser($this->authorOrFail())->allows('removeAttachment', [FeedPost::class, $attachment->post, $attachment]),
            403,
        );

        app(DetachFeedMedia::class)->handle($attachment);

        unset($this->alts[$attachmentId]);
        unset($this->stagedMedia);

        $this->hasMedia = $this->stagedMedia->isNotEmpty();
    }

    /**
     * Try again after a file Livewire accepted and the disk refused.
     */
    public function retryUploads(): void
    {
        $this->stageUploads();
    }

    /**
     * The close button: a discard the author may still refuse.
     */
    public function requestClose(): void
    {
        if ($this->dirty && $this->hasContent()) {
            $this->confirmingClose = true;

            return;
        }

        $this->finishClose();
    }

    public function keepEditing(): void
    {
        $this->confirmingClose = false;
    }

    /**
     * Close without discarding — the composer keeps its words for next time.
     */
    public function finishClose(): void
    {
        $this->confirmingClose = false;

        $this->dispatch('composer-close');
    }

    /**
     * Throw the composer away and start the next post from empty.
     */
    public function discardAndClose(): void
    {
        $this->resetForm();
        $this->confirmingClose = false;

        $this->dispatch('composer-close');
    }

    public function toggleMore(): void
    {
        $this->showMore = ! $this->showMore;
    }

    public function toggleActivity(): void
    {
        $this->showActivity = ! $this->showActivity;
    }

    /**
     * Empty the composer without emptying the page: the dialog stays open, the
     * type and topic stay where the author left them, and the drafts list is
     * re-read because publishing or discarding just changed it.
     */
    private function resetForm(): void
    {
        $this->title = '';
        $this->body = '';
        $this->tags = [];
        $this->tagInput = '';
        $this->code = '';
        $this->codeFile = '';
        $this->linkUrl = '';
        $this->scheduledAt = '';
        $this->pollOptions = $this->blankPoll();
        $this->pollCloses = '';
        $this->activityLabel = '';
        $this->activityDetail = '';
        $this->aiLabel = false;
        $this->communityId = null;
        $this->audience = FeedPostAudience::Public->value;
        $this->draftId = null;
        $this->tempKey = '';
        $this->uploads = null;
        $this->alts = [];
        $this->hasMedia = false;
        $this->previewed = false;
        $this->confirmingClose = false;
        $this->showLinkField = false;
        $this->showActivity = false;
        $this->dirty = false;
        $this->signature = '';

        unset($this->stagedMedia);
        $this->loadDrafts();
    }

    /**
     * The account writing the post. The guard has already resolved it for the
     * page, so this costs nothing it did not already cost.
     */
    #[Computed]
    public function author(): ?User
    {
        /** @var User|null $author */
        $author = auth()->user();

        return $author;
    }

    /**
     * Drafts waiting in the author's name, newest first.
     *
     * @return Collection<int, FeedPost>
     */
    #[Computed]
    public function drafts(): Collection
    {
        return FeedPost::query()
            ->draftsOf((int) $this->authorOrFail()->id)
            ->limit(10)
            ->get();
    }

    /**
     * The communities this account may post into — the only ones a community
     * audience can honestly mean.
     *
     * @return Collection<int, Community>
     */
    #[Computed]
    public function joinedCommunities(): Collection
    {
        /** @var array<int, int> $ids */
        $ids = $this->authorOrFail()->joinedCommunityIds();

        return Community::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
    }

    /**
     * Everything the composer is holding: files claimed by the draft, and files
     * still staged under this session's key.
     *
     * @return Collection<int, FeedAttachment>
     */
    #[Computed]
    public function stagedMedia(): Collection
    {
        /** @var int $authorId */
        $authorId = $this->authorOrFail()->id;

        return FeedAttachment::query()
            ->where('user_id', $authorId)
            ->where(function ($query): void {
                if ($this->tempKey !== '') {
                    $query->orWhere('temp_key', $this->tempKey);
                }

                if ($this->draftId !== null) {
                    $query->orWhere('feed_post_id', $this->draftId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * True once a file has been staged or a draft claimed one — the guard that
     * keeps the media query off the first render entirely.
     *
     * Read this as "attachments are possible", not "attachments exist": a draft
     * row can own media that this composer has not looked at yet, so anything
     * asking whether the query is worth running uses this, while anything
     * showing or counting actual pictures uses `hasMedia`.
     */
    public function hasAttachments(): bool
    {
        return $this->tempKey !== '' || $this->draftId !== null;
    }

    /**
     * Whether the Preview step has anything to show.
     *
     * An autosaved draft is not a reason to preview: if it were, the ordinary
     * act of typing four seconds' worth of text would quietly add a step to a
     * plain post, which is exactly the mandatory-optional-step mistake the
     * composer exists not to make.
     */
    public function needsPreview(): bool
    {
        return $this->hasMedia || $this->audience !== FeedPostAudience::Public->value;
    }

    /**
     * The workflow rail, with the optional middle steps marked as skipped.
     *
     * `current` is derived here rather than tracked as state: it is the first
     * step that is neither finished nor inapplicable, so it can never drift
     * from the four booleans it is read from. Exactly one step carries it.
     *
     * @return array<int, array{key: string, label: string, done: bool, skipped: bool, current: bool}>
     */
    public function steps(): array
    {
        $attached = $this->hasMedia;
        $preview = $this->needsPreview();

        $steps = [
            ['key' => 'compose', 'label' => __('Compose'), 'done' => $this->bodyLength() > 0, 'skipped' => false],
            ['key' => 'attach', 'label' => __('Attach'), 'done' => $attached, 'skipped' => ! $attached],
            ['key' => 'preview', 'label' => __('Preview'), 'done' => $this->previewed, 'skipped' => ! $preview],
            ['key' => 'publish', 'label' => __('Publish'), 'done' => false, 'skipped' => false],
        ];

        $placed = false;

        foreach ($steps as $index => $step) {
            $steps[$index]['current'] = ! $placed && ! $step['done'] && ! $step['skipped'];

            $placed = $placed || $steps[$index]['current'];
        }

        return $steps;
    }

    /**
     * The content types the feed can actually filter and render.
     *
     * @return array<int, string>
     */
    public function availableTypes(): array
    {
        /** @var array<int, string> $types */
        $types = (array) config('feed.composer.types', ['discussion']);

        return $types;
    }

    /**
     * @return array<int, string>
     */
    public function availableTopics(): array
    {
        /** @var array<int, string> $topics */
        $topics = (array) config('feed.composer.topics', ['community']);

        return $topics;
    }

    /**
     * The toolbar's wired options: everything that survives into a real column.
     *
     * @return array<int, array{key: string, label: string, hint: string, path: string}>
     */
    public function wiredOptions(): array
    {
        return [
            ['key' => 'photo', 'label' => __('Photo / Video'), 'hint' => __('Images only in this release.'), 'path' => 'M2.25 15.75l5.159-5.159a2.25 2.25 0 013.185 0l5.159 5.159m-1.5-1.5l1.405-1.405a2.25 2.25 0 013.185 0l3.25 3.25M3.75 21h16.5a1.5 1.5 0 001.5-1.5V4.5a1.5 1.5 0 00-1.5-1.5H3.75a1.5 1.5 0 00-1.5 1.5v15A1.5 1.5 0 003.75 21zm2.25-3h14.25'],
            ['key' => 'snippet', 'label' => __('Code Snippet'), 'hint' => __('A block the card renders as code.'), 'path' => 'M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5'],
            ['key' => 'poll', 'label' => __('Poll'), 'hint' => __('Two to six choices, read as bars.'), 'path' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
            ['key' => 'activity', 'label' => __('Feeling / Activity'), 'hint' => __('One line beside your name.'), 'path' => 'M15.182 15.182a4.5 4.5 0 11-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 1.657 1.007 3 2.25 3s1.128-1.342 1.128-3'],
            ['key' => 'link', 'label' => __('Link'), 'hint' => __('Added to the post as a URL.'), 'path' => 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244'],
            ['key' => 'alt', 'label' => __('Alt text'), 'hint' => __('Per image, under each thumbnail.'), 'path' => 'M4.09 10.833l7.5 7.5a2.12 2.12 0 003 0l4.5-4.5a2.12 2.12 0 000-3l-7.5-7.5a2.12 2.12 0 00-3 0l-4.5 4.5a2.12 2.12 0 000 3z'],
        ];
    }

    /**
     * What the reference composer offers that this application cannot honour
     * yet. Each one is present, labelled and disabled — a control that lies
     * about what happens next is worse than a control that is missing.
     *
     * @return array<int, array{key: string, label: string, reason: string}>
     */
    public function deferredOptions(): array
    {
        return [
            ['key' => 'live-video', 'label' => __('Live Video'), 'reason' => __('No streaming ingest exists.')],
            ['key' => 'reel', 'label' => __('Reel'), 'reason' => __('No video pipeline exists.')],
            ['key' => 'gif', 'label' => __('GIF'), 'reason' => __('No GIF search provider is configured.')],
            ['key' => 'video', 'label' => __('Video'), 'reason' => __('The media disk stores images only.')],
            ['key' => 'tag-people', 'label' => __('Tag People'), 'reason' => __('Tagging needs a notification, and there is none.')],
            ['key' => 'check-in', 'label' => __('Check In / Location'), 'reason' => __('No location model exists.')],
            ['key' => 'get-calls', 'label' => __('Get Calls / Contact CTA'), 'reason' => __('No contact action to render.')],
            ['key' => 'collaborator', 'label' => __('Collaborator'), 'reason' => __('A post has exactly one author.')],
            ['key' => 'business-partnership', 'label' => __('Business Partnership'), 'reason' => __('No paid-partnership disclosure model.')],
            ['key' => 'event', 'label' => __('Event'), 'reason' => __('No event model exists.')],
            ['key' => 'product-attachment', 'label' => __('Product / Project Attachment'), 'reason' => __('Would duplicate the marketplace listing you can already link.')],
            ['key' => 'life-update', 'label' => __('Life Update'), 'reason' => __('No structured update types to choose from.')],
            ['key' => 'content-warning', 'label' => __('Content Warning'), 'reason' => __('Nothing downstream reads a warning flag.')],
            ['key' => 'emoji-picker', 'label' => __('Emoji Picker'), 'reason' => __('Type emoji directly; a picker is not built.')],
            ['key' => 'formatting', 'label' => __('Formatting Controls'), 'reason' => __('The feed renders escaped text, never HTML.')],
            ['key' => 'mention-autocomplete', 'label' => __('Mention / Hashtag Autocomplete'), 'reason' => __('Mentions resolve to nobody without a follow graph.')],
            ['key' => 'link-preview', 'label' => __('Link Preview Metadata'), 'reason' => __('Fetching a URL you typed is an SSRF surface, deferred on purpose.')],
        ];
    }

    /**
     * Stage every file Livewire has finished transferring.
     */
    private function stageUploads(): void
    {
        $files = $this->uploadFiles();

        if ($files === []) {
            return;
        }

        /** @var int $cap */
        $cap = (int) config('feed.composer.max_attachments', 8);

        if ($this->hasAttachments() && $this->stagedMedia->count() + count($files) > $cap) {
            $this->uploads = null;

            $this->addError('uploads', __('A post can carry at most :count images.', ['count' => $cap]));

            return;
        }

        $this->validate([
            'uploads' => ['array', 'max:'.$cap],
            'uploads.*' => [
                'image',
                'mimes:jpg,jpeg,png,webp,gif',
                'max:'.(int) config('feed.composer.max_upload_kilobytes', 8192),
                'dimensions:min_width=16,min_height=16,max_width=6000,max_height=6000',
            ],
        ], [
            'uploads.*.image' => __('Only image files can be attached to a post.'),
            'uploads.*.dimensions' => __('That image is too small or too large to attach.'),
        ]);

        if ($this->tempKey === '') {
            $this->tempKey = (string) Str::uuid();
        }

        app(AttachFeedMedia::class)->handle($this->currentDraft(), $this->authorOrFail(), $files, $this->tempKey);

        $this->uploads = null;
        $this->hasMedia = true;

        unset($this->stagedMedia);
    }

    /**
     * @return array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    private function uploadFiles(): array
    {
        if ($this->uploads === null || $this->uploads === '' || $this->uploads === []) {
            return [];
        }

        return is_array($this->uploads) ? array_values(array_filter($this->uploads)) : [$this->uploads];
    }

    private function storeAlt(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        /** @var FeedAttachment|null $attachment */
        $attachment = FeedAttachment::query()->find($attachmentId);

        if ($attachment === null) {
            return;
        }

        if (! Gate::forUser($this->authorOrFail())->allows('viewAttachment', [FeedPost::class, $attachment->post, $attachment])) {
            return;
        }

        /** @var string $text */
        $text = trim((string) ($this->alts[$attachmentId] ?? ''));

        $attachment->update(['alt_text' => $text === '' ? null : Str::limit($text, 500, '')]);

        unset($this->stagedMedia);
    }

    private function normaliseAudience(): void
    {
        $case = FeedPostAudience::tryFrom($this->audience) ?? FeedPostAudience::Public;

        $this->audience = $case->value;

        if ($case !== FeedPostAudience::Public) {
            $this->communityId = $case === FeedPostAudience::Community ? $this->communityId : null;

            if ($case === FeedPostAudience::Community && $this->joinedCommunities->isEmpty()) {
                $this->addError('audience', __('Join a community before sharing a post into it.'));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        /** @var FeedPostAudience $audience */
        $audience = FeedPostAudience::tryFrom($this->audience) ?? FeedPostAudience::Public;

        return [
            'body' => $this->body,
            'title' => $this->title === '' ? null : $this->title,
            'type' => $this->type,
            'topic' => $this->topic,
            'tags' => $this->tags,
            'audience' => $audience,
            'community_id' => $audience === FeedPostAudience::Community ? $this->communityId : null,
            'ai_label' => $this->aiLabel,
            'code' => $this->code,
            'code_file' => $this->codeFile,
            'poll' => ['options' => $this->pollOptions, 'closes' => $this->pollCloses],
            'activity' => ['label' => $this->activityLabel, 'detail' => $this->activityDetail],
        ];
    }

    private function writeDraft(): FeedPost
    {
        $post = app(SaveFeedDraft::class)->handle(
            $this->authorOrFail(),
            $this->payload(),
            $this->currentDraft(),
        );

        $this->draftId = $post->id;

        $this->loadDrafts();

        return $post;
    }

    private function currentDraft(): ?FeedPost
    {
        if ($this->draftId === null) {
            return null;
        }

        /** @var FeedPost|null $post */
        $post = FeedPost::query()->find($this->draftId);

        if ($post === null) {
            $this->draftId = null;
        }

        return $post;
    }

    private function fingerprint(): string
    {
        return hash('sha256', (string) json_encode($this->payload()));
    }

    private function bodyLength(): int
    {
        return Str::length(trim($this->body));
    }

    private function overBodyLimit(): bool
    {
        /** @var int $limit */
        $limit = (int) config('feed.composer.max_body_length', 5000);

        return Str::length($this->body) > $limit;
    }

    private function hasContent(): bool
    {
        return trim($this->body) !== ''
            || trim($this->title) !== ''
            || $this->tags !== []
            || $this->hasAttachments();
    }

    private function validateContent(): void
    {
        /** @var int $maxBody */
        $maxBody = (int) config('feed.composer.max_body_length', 5000);
        /** @var int $maxTitle */
        $maxTitle = (int) config('feed.composer.max_title_length', 200);

        $this->validate([
            'body' => ['required', 'string', 'max:'.$maxBody],
            'title' => ['nullable', 'string', 'max:'.$maxTitle],
            'type' => ['required', Rule::in($this->availableTypes())],
            'topic' => ['required', Rule::in($this->availableTopics())],
            'audience' => ['required', Rule::enum(FeedPostAudience::class)],
            'communityId' => ['nullable', 'integer', 'exists:communities,id'],
            'tags' => ['array', 'max:'.(int) config('feed.composer.max_tags', 8)],
            'tags.*' => ['string', 'max:40'],
        ], [
            'body.max' => __('That is longer than the feed can publish (:max characters).', [
                'max' => (int) config('feed.composer.max_body_length', 5000),
            ]),
        ]);
    }

    private function authorOrFail(): User
    {
        $author = $this->author;

        abort_if($author === null, 403, __('Sign in to write a post.'));

        /** @var User $author */
        return $author;
    }

    /**
     * @return array<int, array{label: string, votes: int}>
     */
    private function blankPoll(): array
    {
        /** @var int $minimum */
        $minimum = max(2, (int) config('feed.composer.poll_min_options', 2));

        return array_map(fn (): array => ['label' => '', 'votes' => 0], array_fill(0, $minimum, null));
    }

    /**
     * Keep an existing option's vote count where it is, so editing a poll cannot
     * quietly erase the votes it already has.
     *
     * @param  array<string, mixed>  $poll
     * @return array<int, array{label: string, votes: int}>
     */
    private function restorePoll(array $poll): array
    {
        /** @var array<int, array<string, mixed>> $options */
        $options = is_array($poll['options'] ?? null) ? $poll['options'] : [];

        /** @var array<int, array{label: string, votes: int}> $restored */
        $restored = [];

        foreach ($options as $option) {
            $restored[] = [
                'label' => (string) ($option['label'] ?? ''),
                'votes' => max(0, (int) ($option['votes'] ?? 0)),
            ];
        }

        return $restored === [] ? $this->blankPoll() : $restored;
    }
}; ?>

<div
    x-data="{
        composerDirty: @js($dirty),
        dragging: false,
        addUploads(source) {
            const list = source instanceof DataTransfer
                ? Array.from(source.files)
                : Array.from(source ?? []);

            if (list.length === 0) {
                return;
            }

            const transfer = new DataTransfer();

            list.forEach((file) => transfer.items.add(file));

            $refs.files.files = transfer.files;
            $refs.files.dispatchEvent(new Event('change', { bubbles: true }));
        },
    }"
    x-on:feed-composer-open.window="$wire.call('open', $event.detail?.type ?? ''); $dispatch('modal-show', { name: 'feed-composer-modal' })"
    x-on:composer-close.window="$dispatch('modal-close', { name: 'feed-composer-modal' })"
    x-on:input="composerDirty = true"
    x-on:change="composerDirty = true"
    x-on:input.debounce.4000ms="$wire.call('autosave')"
    x-on:change.debounce.4000ms="$wire.call('autosave')"
    x-on:paste="if ($event.clipboardData?.files?.length) { $event.preventDefault(); addUploads($event.clipboardData) }"
    x-on:beforeunload.window="if (composerDirty) { $event.preventDefault(); $event.returnValue = ''; }"
>
    @php
        $maxBody = (int) config('feed.composer.max_body_length', 5000);
        $maxAttachments = (int) config('feed.composer.max_attachments', 8);
        $bodyLength = mb_strlen($this->body);
        $author = $this->author;
        $handle = $author?->handle !== null ? '@'.ltrim($author->handle, '@') : null;
        $initials = Str::of((string) $author?->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('') ?: '··';
    @endphp

    <x-app.ui.modal
        name="feed-composer-modal"
        :title="__('Create post')"
        maxWidth="2xl"
        :closable="false"
        class="max-sm:w-[calc(100%-1.5rem)] max-sm:max-h-[calc(100dvh-2rem)] sm:max-h-[88dvh] sm:max-w-3xl overflow-y-auto"
    >
        {{-- Header: the byline and Close.

             The dialog's own heading already says "Create post", so this row
             only has to answer *who* is writing — an eyebrow that repeated the
             heading, under a pulsing dot, was decoration (and a pulse means
             "live" everywhere else on the feed, where something actually is).

             The dialog is the scroll container at every size, which is what
             lets the two bars below be sticky. On a phone the sheet is nearly
             the whole screen and the editor is taller than it, so an author who
             scrolls to attach a screenshot can still reach Close above and
             Publish below — a composer that hides its own publish button behind
             a scroll is a composer that gets abandoned. `max-sm:` rather than an
             override of the desktop width: below the breakpoint the sheet is
             sized, above it the dialog keeps its centred measure. --}}
        <div class="flex flex-wrap items-start justify-between gap-3 max-sm:sticky max-sm:top-0 max-sm:z-10 max-sm:-mx-6 max-sm:bg-feed-canvas max-sm:px-6 max-sm:py-3">
            <div class="flex min-w-0 items-center gap-3">
                <x-feed.avatar :initials="$initials" tone="mint" />
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-feed-ink">{{ $author?->name ?? __('You') }}</p>
                    <p class="truncate font-mono text-[11px] text-feed-ink-3">
                        {{ $handle ?? $author?->email }}
                        @if ($this->draftId !== null)
                            · <span class="text-feed-mint" data-test="composer-draft-badge">{{ __('Draft saved') }}</span>
                        @endif
                    </p>
                </div>
            </div>

            <button
                type="button"
                wire:click="requestClose"
                class="feed-filter shrink-0"
                aria-label="{{ __('Close the composer') }}"
                data-test="composer-close"
            >
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                {{ __('Close') }}
            </button>
        </div>

        @if ($confirmingClose)
            <div class="feed-well p-3" role="alertdialog" aria-labelledby="composer-discard-heading" data-test="composer-discard-confirm">
                <p id="composer-discard-heading" class="text-sm font-medium text-feed-ink">{{ __('Discard this post?') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-feed-ink-2">{{ __('Closing the composer normally keeps what you wrote. Discarding clears it.') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" wire:click="keepEditing" class="feed-action" data-test="composer-keep-editing">{{ __('Keep editing') }}</button>
                    <button type="button" wire:click="discardAndClose" class="feed-filter !text-feed-error" data-test="composer-discard">{{ __('Discard') }}</button>
                </div>
            </div>
        @endif

        {{-- Workflow rail: the chip carries the step's state. Done is mint, the
             step being worked is azure and declares `aria-current="step"`, and
             an optional step with nothing in it reads quiet — the old
             line-through struck two steps out on the very first open, which
             reads as cancelled rather than "nothing attached yet". --}}
        <ol class="feed-steps" aria-label="{{ __('Publishing steps') }}">
            @foreach ($this->steps() as $index => $step)
                <li @if ($step['current']) aria-current="step" @endif>
                    <span
                        class="feed-chip {{ $step['done'] ? 'is-mint' : '' }} {{ $step['current'] ? 'is-azure' : '' }} {{ $step['skipped'] && ! $step['done'] ? 'is-quiet' : '' }}"
                        @if ($step['skipped'] && ! $step['done'])
                            title="{{ __('Skipped — nothing to ' . strtolower($step['label']) . ' here') }}"
                        @endif
                    >
                        <span class="font-mono tabular-nums opacity-70">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        {{ $step['label'] }}
                        @if ($step['done'])
                            <span class="sr-only">{{ __('— done') }}</span>
                        @elseif ($step['skipped'])
                            <span class="sr-only">{{ __('— nothing to do here') }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>

        <form wire:submit="publish" class="space-y-5">
            {{-- ============ Editor column ============ --}}
            <div class="grid gap-5 sm:grid-cols-5">
                <div class="space-y-4 sm:col-span-3">
                    <div>
                        <label for="composer-title" class="sr-only">{{ __('Headline (optional)') }}</label>
                        <input
                            id="composer-title"
                            type="text"
                            wire:model.live.debounce.600ms="title"
                            placeholder="{{ __('Add a headline — leave it blank and the first line becomes one') }}"
                            class="feed-input is-headline"
                            data-test="composer-title"
                        />
                    </div>

                    {{-- Drop zone, paste target and picker all funnel into addUploads(). --}}
                    <div
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="dragging = false; addUploads($event.dataTransfer)"
                        :class="dragging ? 'is-dragging' : ''"
                        class="feed-dropzone relative p-0.5"
                        data-test="composer-dropzone"
                    >
                        <label for="composer-body" class="sr-only">{{ __('Post body') }}</label>
                        <textarea
                            id="composer-body"
                            rows="7"
                            wire:model.live.debounce.600ms="body"
                            x-on:keydown.meta.enter.prevent="$wire.call('publish')"
                            x-on:keydown.ctrl.enter.prevent="$wire.call('publish')"
                            placeholder="{{ __('Share what you are building, ask what you are stuck on…') }}"
                            class="relative w-full resize-y bg-transparent px-3 py-2.5 text-sm leading-relaxed text-feed-ink placeholder:text-feed-ink-3 focus:outline-none"
                            data-test="composer-body"
                        >{{ $body }}</textarea>

                        <div class="relative flex items-center justify-between gap-3 border-t border-feed-line px-3 py-1.5">
                            <p class="feed-meta">
                                {{ __('Drop, paste or pick an image') }}
                            </p>
                            <p
                                class="feed-meta {{ $bodyLength > $maxBody ? '!text-feed-error' : ($bodyLength > $maxBody * 0.9 ? '!text-warning' : '') }}"
                                data-test="composer-counter"
                            >{{ number_format($bodyLength) }} / {{ number_format($maxBody) }}</p>
                        </div>

                        @error('body')
                            <p class="px-3 pb-2 text-xs text-feed-error" data-test="composer-body-error">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- ============ Add to post toolbar ============ --}}
                    <div class="flex flex-wrap items-center gap-1.5" role="toolbar" aria-label="{{ __('Add to post') }}">
                        <input
                            type="file"
                            x-ref="files"
                            wire:model="uploads"
                            multiple
                            accept="image/jpeg,image/png,image/webp,image/gif"
                            class="hidden"
                            aria-label="{{ __('Attach images') }}"
                            data-test="composer-file-input"
                        />

                        @foreach ($this->wiredOptions() as $option)
                            @continue($option['key'] === 'alt' && ! $this->hasMedia)
                            <button
                                type="button"
                                @switch($option['key'])
                                    @case('photo')
                                        x-on:click="$refs.files.click()"
                                        @break
                                    @case('snippet')
                                        wire:click="$set('type', 'snippet')"
                                        @break
                                    @case('poll')
                                        wire:click="$set('type', 'poll')"
                                        @break
                                    @case('activity')
                                        wire:click="toggleActivity"
                                        @break
                                    @case('link')
                                        wire:click="$toggle('showLinkField')"
                                        @break
                                    @case('alt')
                                        x-on:click="$el.closest('form').querySelector('[data-test=\"composer-alt-text\"]')?.focus()"
                                        @break
                                    @default
                                        x-on:click="$focus.wrap().first()"
                                @endswitch
                                title="{{ $option['hint'] }}"
                                class="feed-filter"
                                data-test="composer-option-{{ $option['key'] }}"
                            >
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $option['path'] }}" /></svg>
                                {{ $option['label'] }}
                            </button>
                        @endforeach

                        <button
                            type="button"
                            wire:click="toggleMore"
                            aria-expanded="{{ $showMore ? 'true' : 'false' }}"
                            aria-controls="composer-more-panel"
                            class="feed-filter"
                            data-test="composer-more"
                        >
                            {{ __('More options') }}
                        </button>
                    </div>

                    @if ($showLinkField ?? false)
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-0 flex-1">
                                <label for="composer-link" class="feed-eyebrow">{{ __('Link') }}</label>
                                <input
                                    id="composer-link"
                                    type="url"
                                    wire:model.blur="linkUrl"
                                    placeholder="https://"
                                    class="feed-input is-mono mt-1"
                                />
                            </div>
                            <button type="button" wire:click="addLink" class="feed-action">{{ __('Add to post') }}</button>
                        </div>
                        @error('linkUrl')
                            <p class="text-xs text-feed-error">{{ $message }}</p>
                        @enderror
                    @endif

                    {{-- Uploading state: Livewire 4 emits no per-file byte progress,
                         so the honest indicator is "this file is in flight". --}}
                    <div wire:loading wire:target="uploads" class="space-y-1" data-test="composer-uploading">
                        <p class="feed-eyebrow">{{ __('Uploading images…') }}</p>
                        <div class="h-1 w-full overflow-hidden rounded-full bg-feed-raised">
                            <div class="h-full w-1/3 animate-pulse rounded-full bg-feed-azure"></div>
                        </div>
                    </div>

                    @error('uploads')
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-feed-error/45 px-3 py-2" role="alert">
                            <p class="text-xs text-feed-error">{{ $message }}</p>
                            @if (! empty($uploads))
                                <button type="button" wire:click="retryUploads" class="feed-filter !text-feed-error">{{ __('Try again') }}</button>
                            @endif
                        </div>
                    @enderror

                    {{-- ============ Staged media ============ --}}
                    @if ($this->hasMedia)
                        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3" data-test="composer-media-list">
                            @foreach ($this->stagedMedia as $attachment)
                                <li class="feed-well space-y-1.5 p-1.5">
                                    <div class="relative">
                                        <img
                                            src="{{ $attachment->url() }}"
                                            alt="{{ $attachment->alt() }}"
                                            class="aspect-square w-full rounded-md object-cover"
                                            loading="lazy"
                                        />
                                        <button
                                            type="button"
                                            wire:click="removeMedia({{ $attachment->id }})"
                                            class="absolute end-1 top-1 grid size-6 place-items-center rounded-full bg-black/60 text-white hover:bg-black/80"
                                            aria-label="{{ __('Remove this image') }}"
                                            data-test="composer-remove-media"
                                        >
                                            <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                    <label class="sr-only" for="alt-{{ $attachment->id }}">{{ __('Alt text for this image') }}</label>
                                    <input
                                        id="alt-{{ $attachment->id }}"
                                        type="text"
                                        wire:model.blur="alts.{{ $attachment->id }}"
                                        value="{{ $attachment->alt_text }}"
                                        placeholder="{{ __('Describe this image') }}"
                                        class="feed-input !px-2 !py-1 text-[11px]"
                                        data-test="composer-alt-text"
                                    />
                                    <p class="font-mono text-[10px] text-feed-ink-3">{{ $attachment->humanSize() }} · {{ $attachment->mime }}</p>
                                </li>
                            @endforeach
                        </ul>
                        <p class="feed-eyebrow">
                            {{ __(':used of :cap images', ['used' => $this->stagedMedia->count(), 'cap' => $maxAttachments]) }}
                        </p>
                    @endif

                    {{-- ============ Type-dependent structure ============ --}}
                    @if ($type === 'snippet')
                        <div class="space-y-2">
                            <label for="composer-code-file" class="feed-eyebrow">{{ __('Code') }}</label>
                            <input
                                id="composer-code-file"
                                type="text"
                                wire:model.blur="codeFile"
                                placeholder="{{ __('filename, e.g. Queue::afterCommit.php') }}"
                                class="feed-input is-mono"
                                data-test="composer-code-file"
                            />
                            <textarea
                                rows="6"
                                wire:model.blur="code"
                                placeholder="{{ __('Paste the snippet') }}"
                                class="feed-input is-mono resize-y leading-relaxed"
                                aria-label="{{ __('Code snippet') }}"
                                data-test="composer-code"
                            >{{ $code }}</textarea>
                        </div>
                    @endif

                    @if ($type === 'poll')
                        <div class="space-y-2" data-test="composer-poll">
                            <p class="feed-eyebrow">{{ __('Poll choices') }}</p>
                            @foreach ($pollOptions as $index => $option)
                                <div class="flex items-center gap-2">
                                    <input
                                        type="text"
                                        wire:model.live="pollOptions.{{ $index }}.label"
                                        placeholder="{{ __('Choice :n', ['n' => $index + 1]) }}"
                                        class="feed-input min-w-0 flex-1"
                                        aria-label="{{ __('Poll choice :n', ['n' => $index + 1]) }}"
                                        data-test="composer-poll-option"
                                    />
                                    @if ($option['votes'] > 0)
                                        <span class="feed-chip font-mono">{{ $option['votes'] }} {{ __('votes') }}</span>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="removePollOption({{ $index }})"
                                        class="feed-filter shrink-0"
                                        aria-label="{{ __('Remove this choice') }}"
                                    >×</button>
                                </div>
                            @endforeach
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="addPollOption" class="feed-filter">{{ __('Add a choice') }}</button>
                                <input
                                    type="text"
                                    wire:model.blur="pollCloses"
                                    placeholder="{{ __('Closes — e.g. Friday') }}"
                                    class="feed-input min-w-0 flex-1"
                                    aria-label="{{ __('When the poll closes') }}"
                                />
                            </div>
                            @error('poll')
                                <p class="text-xs text-feed-error">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    @if ($showActivity)
                        <div class="grid gap-2 sm:grid-cols-2" data-test="composer-activity">
                            <div>
                                <label for="composer-activity-label" class="feed-eyebrow">{{ __('Feeling / activity') }}</label>
                                <input
                                    id="composer-activity-label"
                                    type="text"
                                    wire:model.blur="activityLabel"
                                    placeholder="{{ __('shipping, learning, stuck…') }}"
                                    class="feed-input mt-1"
                                />
                            </div>
                            <div>
                                <label for="composer-activity-detail" class="feed-eyebrow">{{ __('Detail (optional)') }}</label>
                                <input
                                    id="composer-activity-detail"
                                    type="text"
                                    wire:model.blur="activityDetail"
                                    class="feed-input mt-1"
                                />
                            </div>
                        </div>
                    @endif
                </div>

                {{-- ============ Settings column ============ --}}
                <div class="space-y-4 sm:col-span-2">
                    <fieldset class="space-y-2">
                        <legend class="feed-eyebrow">{{ __('Who can see it') }}</legend>
                        <div class="space-y-1.5" role="radiogroup" aria-label="{{ __('Audience') }}">
                            @foreach (\App\Enums\FeedPostAudience::cases() as $choice)
                                <label
                                    class="flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2 transition-colors focus-within:border-feed-azure {{ $audience === $choice->value ? 'border-feed-azure bg-feed-well' : 'border-feed-line hover:border-feed-line-strong' }}"
                                >
                                    <input
                                        type="radio"
                                        name="audience"
                                        value="{{ $choice->value }}"
                                        wire:model.live="audience"
                                        class="mt-0.5 size-3.5 accent-[var(--color-feed-azure)]"
                                        data-test="composer-audience-{{ $choice->value }}"
                                    />
                                    <span class="min-w-0">
                                        <span class="block text-xs font-medium text-feed-ink">{{ $choice->label() }}</span>
                                        <span class="block text-[11px] leading-snug text-feed-ink-3">{{ $choice->hint() }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        @if ($audience === \App\Enums\FeedPostAudience::Community->value)
                            <label for="composer-community" class="feed-eyebrow mt-2 block">{{ __('Community') }}</label>
                            <select
                                id="composer-community"
                                wire:model.live="communityId"
                                class="feed-input mt-1"
                                data-test="composer-community"
                            >
                                <option value="">{{ __('Choose a community you have joined') }}</option>
                                @foreach ($this->joinedCommunities as $community)
                                    <option value="{{ $community->id }}">{{ $community->name }}</option>
                                @endforeach
                            </select>
                        @endif

                        @error('audience')
                            <p class="text-xs text-feed-error">{{ $message }}</p>
                        @enderror
                        @error('communityId')
                            <p class="text-xs text-feed-error">{{ $message }}</p>
                        @enderror
                        @error('community_id')
                            <p class="text-xs text-feed-error">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <fieldset class="space-y-2">
                        <legend class="feed-eyebrow">{{__('Format')}}</legend>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($this->availableTypes() as $choice)
                                <button
                                    type="button"
                                    wire:click="$set('type', '{{ $choice }}')"
                                    aria-pressed="{{ $type === $choice ? 'true' : 'false' }}"
                                    class="feed-type-button"
                                    data-test="composer-type-{{ $choice }}"
                                >{{ Str::ucfirst($choice) }}</button>
                            @endforeach
                        </div>
                        <label for="composer-topic" class="feed-eyebrow mt-2 block">{{ __('Topic') }}</label>
                        <select
                            id="composer-topic"
                            wire:model.live="topic"
                            class="feed-input mt-1"
                            data-test="composer-topic"
                        >
                            @foreach ($this->availableTopics() as $choice)
                                <option value="{{ $choice }}">{{ Str::ucfirst($choice) }}</option>
                            @endforeach
                        </select>
                    </fieldset>

                    <fieldset class="space-y-2">
                        <legend class="feed-eyebrow">{{ __('Tags') }}</legend>
                        @if ($tags !== [])
                            <ul class="flex flex-wrap gap-1.5" data-test="composer-tags">
                                @foreach ($tags as $index => $tag)
                                    <li>
                                        <button type="button" wire:click="removeTag({{ $index }})" class="feed-chip" aria-label="{{ __('Remove tag :tag', ['tag' => $tag]) }}">
                                            #{{ $tag }} <span aria-hidden="true">×</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                wire:model.blur="tagInput"
                                wire:keydown.enter="addTag"
                                placeholder="{{ __('Add a tag') }}"
                                class="feed-input min-w-0 flex-1"
                                aria-label="{{ __('Add a tag') }}"
                            />
                            <button type="button" wire:click="addTag" class="feed-filter">{{ __('Add') }}</button>
                        </div>
                        @error('tags')
                            <p class="text-xs text-feed-error">{{ $message }}</p>
                        @enderror
                        @error('tagInput')
                            <p class="text-xs text-feed-error">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-feed-line px-3 py-2">
                        <input
                            type="checkbox"
                            wire:model.live="aiLabel"
                            class="mt-0.5 size-3.5 accent-[var(--color-feed-azure)]"
                            data-test="composer-ai-label"
                        />
                        <span>
                            <span class="block text-xs font-medium text-feed-ink">{{ __('This used AI') }}</span>
                            <span class="block text-[11px] leading-snug text-feed-ink-3">{{ __('Cards show an AI-content label readers can see.') }}</span>
                        </span>
                    </label>

                    {{-- ============ Drafts ============ --}}
                    @if ($opened)
                        <fieldset class="space-y-2" data-test="composer-drafts">
                            <legend class="feed-eyebrow">{{ __('Your drafts') }}</legend>
                            @forelse ($this->drafts as $draft)
                                <div class="feed-inset flex items-start gap-2 px-2.5 py-2">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-xs font-medium text-feed-ink">{{ $draft->title ?: Str::limit($draft->body, 48) }}</p>
                                        <p class="feed-meta">{{ $draft->updated_at?->diffForHumans() }}</p>
                                    </div>
                                    <button type="button" wire:click="restoreDraft({{ $draft->id }})" class="feed-filter shrink-0" data-test="composer-restore-draft">{{ __('Edit') }}</button>
                                    <button type="button" wire:click="discardDraftRow({{ $draft->id }})" class="feed-filter shrink-0" aria-label="{{ __('Delete this draft') }}" data-test="composer-delete-draft">{{ __('Delete') }}</button>
                                </div>
                            @empty
                                <p class="text-[11px] text-feed-ink-3">{{ __('Nothing saved yet. Keep typing — a draft appears after a few seconds.') }}</p>
                            @endforelse
                        </fieldset>
                    @endif
                </div>
            </div>

            {{-- ============ Preview ============ --}}
            @if ($previewed)
                <section class="feed-preview space-y-3" aria-label="{{ __('Preview') }}" data-test="composer-preview">
                    <p class="feed-eyebrow">{{ __('Preview — as the feed will show it') }}</p>
                    <p class="feed-preview-title">{{ $title !== '' ? $title : Str::limit(Str::before(trim($body)."\n", "\n"), 120) }}</p>
                    <p class="feed-meta">
                        {{ __('by :author · :audience', ['author' => $author?->name ?? __('you'), 'audience' => mb_strtolower(\App\Enums\FeedPostAudience::tryFrom($audience)?->label() ?? __('Public'))]) }}
                    </p>
                    <p class="whitespace-pre-wrap break-words text-sm leading-relaxed text-feed-ink">{{ $body }}</p>

                    @if ($type === 'snippet' && trim($code) !== '')
                        {{-- The artefact renders as the card's terminal panel will. --}}
                        <div class="feed-code">
                            @if (trim($codeFile) !== '')
                                <p class="feed-meta mb-2 border-b border-ink-800 pb-2 !text-ink-500">{{ $codeFile }}</p>
                            @endif
                            <pre class="whitespace-pre"><code>{{ $code }}</code></pre>
                        </div>
                    @endif

                    @if ($type === 'poll')
                        <ul class="space-y-1.5">
                            @foreach ($pollOptions as $option)
                                @if (trim($option['label']) !== '')
                                    <li class="feed-chip w-full justify-start text-left">{{ $option['label'] }}</li>
                                @endif
                            @endforeach
                        </ul>
                    @endif

                    @if ($this->hasMedia)
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->stagedMedia as $attachment)
                                <img src="{{ $attachment->url() }}" alt="{{ $attachment->alt() }}" class="size-16 rounded-md border border-feed-line object-cover" />
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            {{-- ============ Deferred toolbar matrix ============ --}}
            @if ($showMore)
                <section id="composer-more-panel" class="feed-well space-y-2 p-3" aria-label="{{ __('Options not available yet') }}" data-test="composer-more-panel">
                    <p class="feed-eyebrow">{{ __('Not available yet') }}</p>
                    <ul class="grid gap-1.5 sm:grid-cols-2">
                        @foreach ($this->deferredOptions() as $option)
                            <li>
                                <button
                                    type="button"
                                    aria-disabled="true"
                                    tabindex="-1"
                                    disabled
                                    title="{{ $option['reason'] }}"
                                    class="flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-1.5 text-left text-xs text-feed-ink-3 opacity-60"
                                    data-test="composer-deferred-{{ $option['key'] }}"
                                >
                                    <span>{{ $option['label'] }}</span>
                                    <span class="feed-chip shrink-0 text-[10px]">{{ __('Not available yet') }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- ============ Publish bar ============ --}}
            <div class="feed-composer-rule flex flex-wrap items-center justify-between gap-3 pt-4 max-sm:sticky max-sm:bottom-0 max-sm:z-10 max-sm:-mx-6 max-sm:-mb-6 max-sm:bg-feed-canvas max-sm:px-6 max-sm:pb-6">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="saveDraft" class="feed-filter" data-test="composer-save-draft">{{ __('Save draft') }}</button>

                    <label for="composer-schedule" class="sr-only">{{ __('Schedule for later') }}</label>
                    <input
                        id="composer-schedule"
                        type="datetime-local"
                        wire:model.blur="scheduledAt"
                        class="feed-input is-mono !w-auto !px-2.5 !py-1.5"
                        data-test="composer-schedule-input"
                    />
                    <button type="button" wire:click="schedule" class="feed-filter" data-test="composer-schedule">{{ __('Schedule') }}</button>
                </div>

                <div class="flex items-center gap-2">
                    @if ($this->needsPreview() && ! $previewed)
                        <span class="feed-eyebrow hidden sm:inline">{{ __('Preview first') }}</span>
                    @endif

                    {{-- The shortcut the textarea already obeys, shown where it
                         pays off. Hidden on a phone: fingers do not have a
                         Command key. --}}
                    <span class="feed-meta hidden sm:inline" aria-hidden="true">⌘ + ↵</span>

                    <button type="submit" class="feed-action" data-test="composer-publish">
                        {{ $previewed || ! $this->needsPreview() ? __('Publish') : __('Preview') }}
                    </button>
                </div>
            </div>

            @error('scheduled_at')
                <p class="text-xs text-feed-error" data-test="composer-schedule-error">{{ $message }}</p>
            @enderror
        </form>
    </x-app.ui.modal>
</div>
