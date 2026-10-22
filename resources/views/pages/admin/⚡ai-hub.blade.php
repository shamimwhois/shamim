<?php

use App\Actions\AiHub\DeleteAiArticle;
use App\Actions\AiHub\DeleteAiLearningPath;
use App\Actions\AiHub\DeleteAiResource;
use App\Actions\AiHub\DeleteAiTopic;
use App\Actions\AiHub\MoveAiTopic;
use App\Actions\AiHub\ReorderAiLearningPathItem;
use App\Actions\AiHub\ReorderAiResource;
use App\Actions\AiHub\ReorderAiTopic;
use App\Actions\AiHub\SaveAiArticle;
use App\Actions\AiHub\SaveAiLearningPath;
use App\Actions\AiHub\SaveAiResource;
use App\Actions\AiHub\SaveAiTopic;
use App\Actions\AiHub\SyncAiPublicationStatus;
use App\Enums\AccountPermission;
use App\Enums\AiArticleFormat;
use App\Enums\AiDifficulty;
use App\Enums\AiPublicationStatus;
use App\Enums\AiResourceKind;
use App\Models\AiArticle;
use App\Models\AiLearningPath;
use App\Models\AiResource;
use App\Models\AiTopic;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * AI Knowledge Hub console.
 *
 * Four shelves behind one page — articles, topics, learning paths, and resources —
 * because an operator curating a subject touches all four at once: a new article
 * needs a topic to live in, a path needs the article as a lesson, and the shelf
 * needs the reference the article cites. Splitting them across four routes would
 * mean the operator remembers to visit all four.
 *
 * ## Authorisation
 *
 * The route requires `ai-hub:manage`. Every mutating method re-checks it rather
 * than trusting the page guard, because `wire:click` is client input — the guard
 * on the route proves the operator could open the console, not that they may act
 * on a particular row. (Same convention as the marketplace and about consoles.)
 *
 * ## Every write goes through an Action
 *
 * Nothing here saves a model directly. `SaveAi*`, `DeleteAi*`, `MoveAiTopic`,
 * `ReorderAi*`, and `SyncAiPublicationStatus` are the same entry points the rest
 * of the app uses, so the public pages and this console can never disagree about
 * what a valid row is — and the rules that live inside those actions (a slug
 * never moves on a rename, a path cannot publish with an unpublished lesson)
 * cannot be bypassed from the UI.
 *
 * ## Saving never publishes
 *
 * New hub content is filed as a draft whatever the form submitted; the
 * transition is a separate, deliberate act. The status buttons on each row are
 * generated from `AiPublicationStatus::allowedTransitions()`, so the console can
 * only ever offer a move the workflow permits, and containers are further
 * limited to `consoleCases()` because a path or a shelf is not a document under
 * technical review.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('AI Knowledge Hub')] class extends Component
{
    use WithPagination;

    /** The four shelves this page owns. */
    private const array TABS = ['articles', 'topics', 'paths', 'resources'];

    /** How many lessons the lesson picker offers per unfiled search. */
    private const int LESSON_OPTIONS = 150;

    #[Url(as: 'tab', except: 'articles')]
    public string $tab = 'articles';

    /** Article queue filters. */
    #[Url(as: 'q', except: '')]
    public string $articleSearch = '';

    #[Url(as: 'status', except: 'all')]
    public string $articleStatus = 'all';

    /** Resource shelf filters. */
    #[Url(as: 'rq', except: '')]
    public string $resourceSearch = '';

    #[Url(as: 'rkind', except: 'all')]
    public string $resourceKind = 'all';

    /**
     * Article form (create or edit).
     */
    public ?int $editingArticleId = null;

    #[Validate('required|string|max:200')]
    public string $articleTitle = '';

    #[Validate('nullable|string|max:200')]
    public string $articleSlug = '';

    #[Validate('nullable|string|max:500')]
    public string $articleSummary = '';

    #[Validate('required|string|max:100000')]
    public string $articleBody = '';

    #[Validate('required|string')]
    public string $articleFormat = AiArticleFormat::Article->value;

    #[Validate('required|string')]
    public string $articleDifficulty = AiDifficulty::Beginner->value;

    #[Validate('nullable|string|max:2000')]
    public string $articlePrerequisites = '';

    #[Validate('nullable|string|max:255')]
    public string $articleCoverImage = '';

    #[Validate('nullable|string|max:160')]
    public string $articleMetaTitle = '';

    #[Validate('nullable|string|max:300')]
    public string $articleMetaDescription = '';

    #[Validate('nullable|string')]
    public string $articleStatusRequested = '';

    /** @var array<int, int> */
    #[Validate('array')]
    public array $articleTopics = [];

    /** @var array<int, int> */
    #[Validate('array')]
    public array $articleRelated = [];

    /** @var array<int, array{url: string, label: string, kind: string, note: string}> */
    #[Validate('array|max:12')]
    public array $articleLinks = [];

    /**
     * Topic form (create or edit).
     */
    public ?int $editingTopicId = null;

    #[Validate('required|string|max:120')]
    public string $topicName = '';

    #[Validate('nullable|string|max:200')]
    public string $topicSlug = '';

    #[Validate('nullable|integer')]
    public ?int $topicParentId = null;

    #[Validate('nullable|string|max:500')]
    public string $topicDescription = '';

    #[Validate('nullable|string|max:64')]
    public string $topicIcon = '';

    #[Validate('nullable|integer|min:0|max:999')]
    public ?int $topicSortOrder = null;

    public bool $topicActive = true;

    /**
     * Learning path form (create or edit).
     */
    public ?int $editingPathId = null;

    #[Validate('required|string|max:200')]
    public string $pathTitle = '';

    #[Validate('nullable|string|max:200')]
    public string $pathSlug = '';

    #[Validate('nullable|string|max:1000')]
    public string $pathDescription = '';

    #[Validate('nullable|integer')]
    public ?int $pathTopicId = null;

    #[Validate('required|string')]
    public string $pathDifficulty = AiDifficulty::Beginner->value;

    #[Validate('nullable|string|max:2000')]
    public string $pathPrerequisites = '';

    #[Validate('nullable|string|max:160')]
    public string $pathMetaTitle = '';

    #[Validate('nullable|string|max:300')]
    public string $pathMetaDescription = '';

    #[Validate('nullable|string')]
    public string $pathStatusRequested = '';

    public bool $pathFeatured = false;

    /**
     * The lesson slots being assembled. Submitted to `SaveAiLearningPath` in
     * array order, which is the order readers will meet them in.
     *
     * @var array<int, array<string, mixed>>
     */
    #[Validate('array|max:40')]
    public array $pathLessons = [];

    #[Validate('nullable|integer')]
    public ?int $lessonChoice = null;

    /**
     * Resource form (create or edit).
     */
    public ?int $editingResourceId = null;

    #[Validate('required|string|max:200')]
    public string $resourceTitle = '';

    #[Validate('nullable|string|max:1000')]
    public string $resourceDescription = '';

    #[Validate('required|string|max:500')]
    public string $resourceUrl = '';

    #[Validate('required|string')]
    public string $resourceKindValue = AiResourceKind::Documentation->value;

    #[Validate('nullable|string|max:120')]
    public string $resourceProvider = '';

    #[Validate('nullable|integer')]
    public ?int $resourceTopicId = null;

    #[Validate('nullable|string')]
    public string $resourceStatusRequested = '';

    public bool $resourceFeatured = false;

    #[Validate('nullable|integer|min:0|max:999')]
    public ?int $resourceSortOrder = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::ManageAiHub), 403);
    }

    public function mount(): void
    {
        abort_unless(in_array($this->tab, self::TABS, true), 404);

        app(Seo::class)->set([
            'title' => 'AI Knowledge Hub',
            'description' => 'Manage the articles, topics, learning paths, and resources of the AI Knowledge Hub.',
            'noindex' => true,
        ]);
    }

    /**
     * Reset both paginated shelves when a filter changes the result set.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['articleSearch', 'articleStatus'], true)) {
            $this->resetPage('articlesPage');
        }

        if (in_array($property, ['resourceSearch', 'resourceKind'], true)) {
            $this->resetPage('resourcesPage');
        }
    }

    /**
     * Switch shelf. Re-checks the whitelist because `wire:click` is client input.
     */
    public function showTab(string $tab): void
    {
        abort_unless(in_array($tab, self::TABS, true), 404);

        $this->tab = $tab;
    }

    /*
    |--------------------------------------------------------------------------
    | Articles
    |--------------------------------------------------------------------------
    */

    /**
     * Load an article into the form for editing.
     */
    public function editArticle(int $articleId): void
    {
        $article = AiArticle::query()->with(['topics', 'links', 'relatedArticles'])->findOrFail($articleId);

        $this->editingArticleId = $article->id;
        $this->articleTitle = $article->title;
        $this->articleSlug = $article->slug;
        $this->articleSummary = $article->summary ?? '';
        $this->articleBody = (string) $article->body;
        $this->articleFormat = $article->format->value;
        $this->articleDifficulty = $article->difficulty->value;
        $this->articlePrerequisites = $article->prerequisites ?? '';
        $this->articleCoverImage = $article->cover_image ?? '';
        $this->articleMetaTitle = $article->meta_title ?? '';
        $this->articleMetaDescription = $article->meta_description ?? '';
        $this->articleStatusRequested = $article->status->value;
        $this->articleTopics = $article->topics->pluck('id')->map(fn (int $id): int => $id)->all();
        $this->articleRelated = $article->relatedArticles->pluck('id')->map(fn (int $id): int => $id)->all();

        $this->articleLinks = $article->links
            ->map(fn ($link): array => [
                'id' => $link->id,
                'url' => $link->url,
                'label' => $link->label,
                'kind' => $link->kind->value,
                'note' => $link->note ?? '',
            ])
            ->values()
            ->all();

        $this->tab = 'articles';
    }

    /**
     * Clear the article form back to "create".
     */
    public function resetArticleForm(): void
    {
        $this->reset(
            'editingArticleId', 'articleTitle', 'articleSlug', 'articleSummary', 'articleBody',
            'articlePrerequisites', 'articleCoverImage', 'articleMetaTitle', 'articleMetaDescription',
            'articleStatusRequested', 'articleTopics', 'articleRelated', 'articleLinks',
        );

        $this->articleFormat = AiArticleFormat::Article->value;
        $this->articleDifficulty = AiDifficulty::Beginner->value;

        $this->resetValidation();
    }

    /**
     * Create or update the article.
     */
    public function saveArticle(): void
    {
        $this->authorizeManage();

        $this->validateForm([
            'articleTitle', 'articleSlug', 'articleSummary', 'articleBody',
            'articleFormat', 'articleDifficulty', 'articlePrerequisites', 'articleCoverImage',
            'articleMetaTitle', 'articleMetaDescription', 'articleStatusRequested',
            'articleTopics', 'articleRelated', 'articleLinks',
        ]);

        $author = auth()->user();

        abort_if($author === null, 403);

        $article = $this->editingArticleId !== null
            ? AiArticle::query()->findOrFail($this->editingArticleId)
            : null;

        try {
            $saved = app(SaveAiArticle::class)->handle(
                $author,
                [
                    'title' => trim($this->articleTitle),
                    'slug' => trim($this->articleSlug) !== '' ? trim($this->articleSlug) : null,
                    'summary' => trim($this->articleSummary) !== '' ? trim($this->articleSummary) : null,
                    'body' => $this->articleBody,
                    'format' => $this->articleFormat,
                    'difficulty' => $this->articleDifficulty,
                    'prerequisites' => trim($this->articlePrerequisites) !== '' ? trim($this->articlePrerequisites) : null,
                    'cover_image' => trim($this->articleCoverImage) !== '' ? trim($this->articleCoverImage) : null,
                    'meta_title' => trim($this->articleMetaTitle) !== '' ? trim($this->articleMetaTitle) : null,
                    'meta_description' => trim($this->articleMetaDescription) !== '' ? trim($this->articleMetaDescription) : null,
                    'status' => $this->articleStatusRequested,
                ],
                $article,
                $this->articleTopics,
                $this->normalisedLinks(),
                $this->articleRelated,
            );
        } catch (InvalidArgumentException $exception) {
            $this->addError('articleStatusRequested', $exception->getMessage());

            return;
        }

        $this->resetArticleForm();
        $this->bustCaches();

        Flux::toast(variant: 'success', text: __(':name saved as :status.', [
            'name' => $saved->title,
            'status' => $saved->status->label(),
        ]));
    }

    /**
     * Delete an article, after the operator has confirmed what it will cost.
     */
    public function deleteArticle(int $articleId): void
    {
        $this->authorizeManage();

        $article = AiArticle::query()->findOrFail($articleId);
        $action = app(DeleteAiArticle::class);
        $paths = $action->pathCount($article);

        $action->handle($article);

        if ($this->editingArticleId === $articleId) {
            $this->resetArticleForm();
        }

        $this->bustCaches();

        Flux::toast(variant: 'success', text: $paths > 0
            ? __('Article deleted. :count learning path(s) lost a lesson.', ['count' => $paths])
            : __('Article deleted.'));
    }

    /**
     * Move a single reference-link row.
     */
    public function addArticleLink(): void
    {
        $this->articleLinks[] = ['url' => '', 'label' => '', 'kind' => 'reference', 'note' => ''];
    }

    public function removeArticleLink(int $index): void
    {
        unset($this->articleLinks[$index]);

        $this->articleLinks = array_values($this->articleLinks);
    }

    /*
    |--------------------------------------------------------------------------
    | Topics
    |--------------------------------------------------------------------------
    */

    /**
     * Load a topic into the form for editing.
     */
    public function editTopic(int $topicId): void
    {
        $topic = AiTopic::query()->findOrFail($topicId);

        $this->editingTopicId = $topic->id;
        $this->topicName = $topic->name;
        $this->topicSlug = $topic->slug;
        $this->topicParentId = $topic->parent_id;
        $this->topicDescription = $topic->description ?? '';
        $this->topicIcon = $topic->icon ?? '';
        $this->topicSortOrder = $topic->sort_order;
        $this->topicActive = $topic->is_active;
    }

    /**
     * Clear the topic form back to "create".
     */
    public function resetTopicForm(): void
    {
        $this->reset(
            'editingTopicId', 'topicName', 'topicSlug', 'topicParentId',
            'topicDescription', 'topicIcon', 'topicSortOrder', 'topicActive',
        );

        $this->topicActive = true;
        $this->resetValidation();
    }

    /**
     * Create or update the topic, re-parenting through `MoveAiTopic` when the
     * parent changed so the new position is appended rather than inherited.
     */
    public function saveTopic(): void
    {
        $this->authorizeManage();

        $this->validateForm([
            'topicName', 'topicSlug', 'topicParentId',
            'topicDescription', 'topicIcon', 'topicSortOrder',
        ]);

        $topic = $this->editingTopicId !== null
            ? AiTopic::query()->findOrFail($this->editingTopicId)
            : null;

        $data = [
            'name' => trim($this->topicName),
            'slug' => trim($this->topicSlug) !== '' ? trim($this->topicSlug) : null,
            'parent_id' => $this->topicParentId,
            'description' => trim($this->topicDescription) !== '' ? trim($this->topicDescription) : null,
            'icon' => trim($this->topicIcon) !== '' ? trim($this->topicIcon) : null,
            'sort_order' => $this->topicSortOrder,
            'is_active' => $this->topicActive,
        ];

        try {
            if ($topic !== null && $topic->parent_id !== $this->topicParentId) {
                app(MoveAiTopic::class)->handle($topic, $this->topicParentId);
            }

            $saved = app(SaveAiTopic::class)->handle($data, $topic);
        } catch (InvalidArgumentException $exception) {
            $this->addError('topicParentId', $exception->getMessage());

            return;
        }

        $this->resetTopicForm();
        $this->bustCaches();

        Flux::toast(variant: 'success', text: __('Topic ":name" saved.', ['name' => $saved->name]));
    }

    /**
     * Delete a topic without deleting anything it filed.
     */
    public function deleteTopic(int $topicId): void
    {
        $this->authorizeManage();

        $topic = AiTopic::query()->findOrFail($topicId);
        $action = app(DeleteAiTopic::class);
        $articles = $action->articleCount($topic);
        $children = $action->childCount($topic);

        $action->handle($topic);

        if ($this->editingTopicId === $topicId) {
            $this->resetTopicForm();
        }

        $this->bustCaches();

        Flux::toast(variant: 'success', text: trans_choice(
            'Topic deleted.|Topic deleted — :articles article filing(s) unfiled, :children sub-topic(s) promoted.',
            1,
            ['articles' => $articles, 'children' => $children],
        ));
    }

    /**
     * Switch a topic off without deleting its filings.
     */
    public function toggleTopicActive(int $topicId): void
    {
        $this->authorizeManage();

        $topic = AiTopic::query()->findOrFail($topicId);

        app(SaveAiTopic::class)->handle(
            ['name' => $topic->name, 'is_active' => ! $topic->is_active],
            $topic,
        );

        $this->bustCaches();

        Flux::toast(variant: 'success', text: $topic->is_active
            ? __('Topic ":name" is now visible.', ['name' => $topic->name])
            : __('Topic ":name" is now retired.', ['name' => $topic->name]));
    }

    /**
     * Exchange a topic's position with the sibling above or below it.
     */
    public function reorderTopic(int $topicId, string $direction): void
    {
        $this->authorizeManage();

        $action = app(ReorderAiTopic::class);
        $topic = AiTopic::query()->findOrFail($topicId);
        $sibling = $action->neighbour($topic, $direction);

        if ($sibling === null) {
            Flux::toast(variant: 'error', text: __('That is already the end of the list.'));

            return;
        }

        $action->handle($topic, $sibling);

        $this->bustCaches();
    }

    /*
    |--------------------------------------------------------------------------
    | Learning paths
    |--------------------------------------------------------------------------
    */

    /**
     * Load a path and its ordered lesson slots into the form.
     */
    public function editPath(int $pathId): void
    {
        $path = AiLearningPath::query()->with('items.article')->findOrFail($pathId);

        $this->editingPathId = $path->id;
        $this->pathTitle = $path->title;
        $this->pathSlug = $path->slug;
        $this->pathDescription = $path->description ?? '';
        $this->pathTopicId = $path->ai_topic_id;
        $this->pathDifficulty = $path->difficulty->value;
        $this->pathPrerequisites = $path->prerequisites ?? '';
        $this->pathMetaTitle = $path->meta_title ?? '';
        $this->pathMetaDescription = $path->meta_description ?? '';
        $this->pathStatusRequested = $path->status->value;
        $this->pathFeatured = $path->is_featured;

        $this->pathLessons = $path->items
            ->map(fn ($item): array => [
                'id' => $item->id,
                'ai_article_id' => $item->ai_article_id,
                'title' => $item->title ?? '',
                'note' => $item->note ?? '',
            ])
            ->values()
            ->all();

        $this->tab = 'paths';
    }

    /**
     * Clear the path form back to "create".
     */
    public function resetPathForm(): void
    {
        $this->reset(
            'editingPathId', 'pathTitle', 'pathSlug', 'pathDescription', 'pathTopicId',
            'pathPrerequisites', 'pathMetaTitle', 'pathMetaDescription', 'pathStatusRequested',
            'pathLessons', 'lessonChoice',
        );

        $this->pathDifficulty = AiDifficulty::Beginner->value;
        $this->pathFeatured = false;
        $this->resetValidation();
    }

    /**
     * Create or update the path together with the order of its lessons.
     */
    public function savePath(): void
    {
        $this->authorizeManage();

        $this->validateForm([
            'pathTitle', 'pathSlug', 'pathDescription', 'pathTopicId',
            'pathDifficulty', 'pathPrerequisites', 'pathMetaTitle', 'pathMetaDescription',
            'pathStatusRequested', 'pathLessons',
        ]);

        $author = auth()->user();

        abort_if($author === null, 403);

        $path = $this->editingPathId !== null
            ? AiLearningPath::query()->findOrFail($this->editingPathId)
            : null;

        try {
            $saved = app(SaveAiLearningPath::class)->handle(
                $author,
                [
                    'title' => trim($this->pathTitle),
                    'slug' => trim($this->pathSlug) !== '' ? trim($this->pathSlug) : null,
                    'description' => trim($this->pathDescription) !== '' ? trim($this->pathDescription) : null,
                    'ai_topic_id' => $this->pathTopicId,
                    'difficulty' => $this->pathDifficulty,
                    'is_featured' => $this->pathFeatured,
                    'prerequisites' => trim($this->pathPrerequisites) !== '' ? trim($this->pathPrerequisites) : null,
                    'meta_title' => trim($this->pathMetaTitle) !== '' ? trim($this->pathMetaTitle) : null,
                    'meta_description' => trim($this->pathMetaDescription) !== '' ? trim($this->pathMetaDescription) : null,
                    'status' => $this->pathStatusRequested,
                ],
                $path,
                $this->pathLessons,
            );
        } catch (InvalidArgumentException $exception) {
            $this->addError('pathStatusRequested', $exception->getMessage());

            return;
        }

        $this->resetPathForm();
        $this->bustCaches();

        Flux::toast(variant: 'success', text: __(':name saved as :status.', [
            'name' => $saved->title,
            'status' => $saved->status->label(),
        ]));
    }

    /**
     * Delete a path. Its lesson slots go with it; the articles stay published.
     */
    public function deletePath(int $pathId): void
    {
        $this->authorizeManage();

        $path = AiLearningPath::query()->findOrFail($pathId);
        $action = app(DeleteAiLearningPath::class);
        $lessons = $action->lessonCount($path);

        $action->handle($path);

        if ($this->editingPathId === $pathId) {
            $this->resetPathForm();
        }

        $this->bustCaches();

        Flux::toast(variant: 'success', text: __('Path deleted. :count lesson slot(s) released; the articles were not touched.', [
            'count' => $lessons,
        ]));
    }

    /**
     * Append the chosen article to the end of the lesson list.
     */
    public function addLesson(): void
    {
        if ($this->lessonChoice === null) {
            $this->addError('lessonChoice', __('Pick an article to add.'));

            return;
        }

        $already = collect($this->pathLessons)->contains(
            fn (array $lesson): bool => (int) ($lesson['ai_article_id'] ?? 0) === $this->lessonChoice
        );

        if ($already) {
            $this->addError('lessonChoice', __('That lesson is already in the path.'));

            return;
        }

        $article = AiArticle::query()->find($this->lessonChoice);

        if ($article === null) {
            $this->addError('lessonChoice', __('Unknown article.'));

            return;
        }

        $this->pathLessons[] = [
            'ai_article_id' => $article->id,
            'title' => '',
            'note' => '',
        ];

        $this->lessonChoice = null;
        $this->resetValidation('lessonChoice');
    }

    /**
     * Drop a lesson slot by article id. The slot is only removed from the
     * database on the next save, which submits the whole ordered list.
     */
    public function removeLesson(int $articleId): void
    {
        $this->pathLessons = collect($this->pathLessons)
            ->reject(fn (array $lesson): bool => (int) ($lesson['ai_article_id'] ?? 0) === $articleId)
            ->values()
            ->all();
    }

    /**
     * Exchange two adjacent lesson slots in the pending list.
     */
    public function moveLesson(int $articleId, string $direction): void
    {
        $index = collect($this->pathLessons)->search(
            fn (array $lesson): bool => (int) ($lesson['ai_article_id'] ?? 0) === $articleId
        );

        if ($index === false) {
            return;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($target < 0 || $target >= count($this->pathLessons)) {
            return;
        }

        $lessons = $this->pathLessons;
        $lessons[$index] = $lessons[$target];
        $lessons[$target] = $this->pathLessons[$index];

        $this->pathLessons = array_values($lessons);
    }

    /**
     * Reorder a saved path's lessons directly, without going through the form.
     */
    public function reorderLesson(int $itemId, string $direction): void
    {
        $this->authorizeManage();

        $action = app(ReorderAiLearningPathItem::class);
        $item = \App\Models\AiLearningPathItem::query()->findOrFail($itemId);
        $sibling = $action->neighbour($item, $direction);

        if ($sibling === null) {
            Flux::toast(variant: 'error', text: __('That is already the end of the route.'));

            return;
        }

        $action->handle($item, $sibling);

        if ($this->editingPathId === $item->ai_learning_path_id) {
            $this->resetPathForm();
        }

        $this->bustCaches();
    }

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    */

    /**
     * Load a resource into the form for editing.
     */
    public function editResource(int $resourceId): void
    {
        $resource = AiResource::query()->findOrFail($resourceId);

        $this->editingResourceId = $resource->id;
        $this->resourceTitle = $resource->title;
        $this->resourceDescription = $resource->description ?? '';
        $this->resourceUrl = $resource->url;
        $this->resourceKindValue = $resource->kind->value;
        $this->resourceProvider = $resource->provider ?? '';
        $this->resourceTopicId = $resource->ai_topic_id;
        $this->resourceStatusRequested = $resource->status->value;
        $this->resourceFeatured = $resource->is_featured;
        $this->resourceSortOrder = $resource->sort_order;
    }

    /**
     * Clear the resource form back to "create".
     */
    public function resetResourceForm(): void
    {
        $this->reset(
            'editingResourceId', 'resourceTitle', 'resourceDescription', 'resourceUrl',
            'resourceProvider', 'resourceTopicId', 'resourceStatusRequested',
            'resourceFeatured', 'resourceSortOrder',
        );

        $this->resourceKindValue = AiResourceKind::Documentation->value;
        $this->resetValidation();
    }

    /**
     * Create or update the resource.
     */
    public function saveResource(): void
    {
        $this->authorizeManage();

        $this->validateForm([
            'resourceTitle', 'resourceDescription', 'resourceUrl', 'resourceKindValue',
            'resourceProvider', 'resourceTopicId', 'resourceStatusRequested', 'resourceSortOrder',
        ]);

        $author = auth()->user();

        abort_if($author === null, 403);

        $resource = $this->editingResourceId !== null
            ? AiResource::query()->findOrFail($this->editingResourceId)
            : null;

        try {
            $saved = app(SaveAiResource::class)->handle(
                $author,
                [
                    'title' => trim($this->resourceTitle),
                    'description' => trim($this->resourceDescription) !== '' ? trim($this->resourceDescription) : null,
                    'url' => trim($this->resourceUrl),
                    'kind' => $this->resourceKindValue,
                    'provider' => trim($this->resourceProvider) !== '' ? trim($this->resourceProvider) : null,
                    'ai_topic_id' => $this->resourceTopicId,
                    'is_featured' => $this->resourceFeatured,
                    'sort_order' => $this->resourceSortOrder,
                    'status' => $this->resourceStatusRequested,
                ],
                $resource,
            );
        } catch (InvalidArgumentException $exception) {
            $field = str_starts_with($exception->getMessage(), 'A resource needs') ||
                str_starts_with($exception->getMessage(), 'Resources can only')
                    ? 'resourceUrl'
                    : (str_contains($exception->getMessage(), 'topic') ? 'resourceTopicId' : 'resourceStatusRequested');

            $this->addError($field, $exception->getMessage());

            return;
        }

        $this->resetResourceForm();
        $this->bustCaches();

        Flux::toast(variant: 'success', text: __(':name saved as :status.', [
            'name' => $saved->title,
            'status' => $saved->status->label(),
        ]));
    }

    public function deleteResource(int $resourceId): void
    {
        $this->authorizeManage();

        $resource = AiResource::query()->findOrFail($resourceId);

        app(DeleteAiResource::class)->handle($resource);

        if ($this->editingResourceId === $resourceId) {
            $this->resetResourceForm();
        }

        $this->bustCaches();

        Flux::toast(variant: 'success', text: __('Resource deleted.'));
    }

    /**
     * Exchange a resource's position with its neighbour on the same shelf.
     */
    public function reorderResource(int $resourceId, string $direction): void
    {
        $this->authorizeManage();

        $action = app(ReorderAiResource::class);
        $resource = AiResource::query()->findOrFail($resourceId);
        $sibling = $action->neighbour($resource, $direction);

        if ($sibling === null) {
            Flux::toast(variant: 'error', text: __('That is already the end of the shelf.'));

            return;
        }

        $action->handle($resource, $sibling);

        $this->bustCaches();
    }

    /*
    |--------------------------------------------------------------------------
    | Publication transitions
    |--------------------------------------------------------------------------
    */

    /**
     * Move hub content to another status.
     *
     * One handler for all four shelves, because the workflow is one workflow. The
     * target is checked against `allowedTransitions()` before the action runs, so
     * a hand-built request cannot skip a step the buttons do not offer.
     */
    public function changeStatus(string $type, int $id, string $status): void
    {
        $this->authorizeManage();

        $content = $this->findForTransition($type, $id);
        $target = AiPublicationStatus::tryFrom($status);

        abort_if($content === null || $target === null, 404);

        if (! $this->allowedTransitionsFor($content)->contains($target)) {
            Flux::toast(variant: 'error', text: __('That move is not allowed from :status.', [
                'status' => $content->status->label(),
            ]));

            return;
        }

        try {
            app(SyncAiPublicationStatus::class)->handle($content, $target);
        } catch (InvalidArgumentException $exception) {
            Flux::toast(variant: 'error', text: $exception->getMessage());

            return;
        }

        $content->save();
        $this->bustCaches();

        Flux::toast(variant: 'success', text: __(':name is now :status.', [
            'name' => $content->title ?? $content->name,
            'status' => $target->label(),
        ]));
    }

    /**
     * Clear the article filters.
     */
    public function clearArticleFilters(): void
    {
        $this->reset('articleSearch', 'articleStatus');
        $this->resetPage('articlesPage');
    }

    /**
     * Clear the resource filters.
     */
    public function clearResourceFilters(): void
    {
        $this->reset('resourceSearch', 'resourceKind');
        $this->resetPage('resourcesPage');
    }

    /*
    |--------------------------------------------------------------------------
    | Computed
    |--------------------------------------------------------------------------
    */

    /**
     * Shelf totals. Every figure is a count of stored columns.
     *
     * @return array{articles: int, drafts: int, topics: int, paths: int, resources: int}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'articles' => AiArticle::query()->count(),
            'drafts' => AiArticle::query()->draft()->count(),
            'topics' => AiTopic::query()->count(),
            'paths' => AiLearningPath::query()->count(),
            'resources' => AiResource::query()->count(),
        ];
    }

    /**
     * The filtered article queue.
     *
     * @return LengthAwarePaginator<int, AiArticle>
     */
    #[Computed]
    public function articles(): LengthAwarePaginator
    {
        return AiArticle::query()
            ->with(['topics', 'user'])
            ->when($this->articleSearch !== '', fn (Builder $query) => $query->search($this->articleSearch))
            ->when(
                AiPublicationStatus::tryFrom($this->articleStatus) !== null,
                fn (Builder $query) => $query->where('status', AiPublicationStatus::from($this->articleStatus)),
            )
            ->orderByRaw("case when status = 'draft' then 0 else 1 end")
            ->latest('updated_at')
            ->paginate(10, pageName: 'articlesPage')
            ->withQueryString();
    }

    /**
     * The whole taxonomy, roots with their children attached, in operator order.
     *
     * @return Collection<int, AiTopic>
     */
    #[Computed]
    public function topics(): Collection
    {
        return AiTopic::query()
            ->with(['children.articles', 'articles'])
            ->withCount('learningPaths')
            ->rootLevel()
            ->ordered()
            ->get();
    }

    /**
     * Paths, newest edit first. A taxonomy-sized list, so it is not paginated.
     *
     * @return Collection<int, AiLearningPath>
     */
    #[Computed]
    public function paths(): Collection
    {
        return AiLearningPath::query()
            ->with(['topic', 'items.article'])
            ->withCount('items')
            ->latest('updated_at')
            ->get();
    }

    /**
     * The filtered resource shelf.
     *
     * @return LengthAwarePaginator<int, AiResource>
     */
    #[Computed]
    public function resources(): LengthAwarePaginator
    {
        return AiResource::query()
            ->with('topic')
            ->when($this->resourceSearch !== '', fn (Builder $query) => $query->search($this->resourceSearch))
            ->when(
                AiResourceKind::tryFrom($this->resourceKind) !== null,
                fn (Builder $query) => $query->ofKind(AiResourceKind::from($this->resourceKind)),
            )
            ->orderBy('status')
            ->ordered()
            ->paginate(10, pageName: 'resourcesPage')
            ->withQueryString();
    }

    /**
     * Active topics as id => label pairs, for the filing selects.
     *
     * @return Collection<int, AiTopic>
     */
    #[Computed]
    public function topicOptions(): Collection
    {
        return AiTopic::query()->active()->ordered()->get(['id', 'name', 'parent_id', 'slug']);
    }

    /**
     * Articles available as lessons, published first, for the picker.
     *
     * @return Collection<int, AiArticle>
     */
    #[Computed]
    public function lessonOptions(): Collection
    {
        return AiArticle::query()
            ->orderByRaw("case when status = 'published' then 0 else 1 end")
            ->orderBy('title')
            ->limit(self::LESSON_OPTIONS)
            ->get(['id', 'title', 'status', 'reading_time']);
    }

    /**
     * Articles offered as "read next" candidates.
     *
     * @return Collection<int, AiArticle>
     */
    #[Computed]
    public function relatedOptions(): Collection
    {
        return AiArticle::query()
            ->when($this->editingArticleId !== null, fn (Builder $query) => $query->whereKeyNot($this->editingArticleId))
            ->orderBy('title')
            ->limit(self::LESSON_OPTIONS)
            ->get(['id', 'title', 'status']);
    }

    /**
     * Topics that may become the parent of the topic in the form.
     *
     * A topic being edited cannot be filed under one of its own children, so
     * that whole subtree is dropped from the choices rather than left for the
     * action to reject after the operator has filled in the form.
     *
     * @return Collection<int, AiTopic>
     */
    #[Computed]
    public function parentOptions(): Collection
    {
        $topics = AiTopic::query()->get(['id', 'name', 'parent_id', 'slug']);

        if ($this->editingTopicId === null) {
            return $topics->sortBy(fn (AiTopic $topic) => $topic->name)->values();
        }

        $excluded = $this->descendantIds($topics, $this->editingTopicId);

        return $topics
            ->reject(fn (AiTopic $topic) => in_array($topic->id, $excluded, true))
            ->sortBy(fn (AiTopic $topic) => $topic->name)
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Re-check the permission every mutating method relies on.
     */
    private function authorizeManage(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(AccountPermission::ManageAiHub) ?? false,
            403,
        );
    }

    /**
     * Validate every field of the form being saved. The component carries four
     * independent forms, so the request rules are narrowed to this form before
     * they run — one validator means every failing field is reported at once.
     *
     * @param  array<int, string>  $fields
     */
    private function validateForm(array $fields): void
    {
        $rules = collect($this->getRules())->only($fields)->all();

        $this->validate($rules);
    }

    /**
     * Resolve the model a status transition targets.
     */
    private function findForTransition(string $type, int $id): AiArticle|AiLearningPath|AiResource|null
    {
        return match ($type) {
            'article' => AiArticle::query()->find($id),
            'path' => AiLearningPath::query()->find($id),
            'resource' => AiResource::query()->find($id),
            default => null,
        };
    }

    /**
     * The statuses this piece of content may move to from where it sits.
     *
     * Containers only see `consoleCases()`; an article may also be held in review.
     *
     * @return Collection<int, AiPublicationStatus>
     */
    public function allowedTransitionsFor(AiArticle|AiLearningPath|AiResource $content): Collection
    {
        $allowed = collect($content->status->allowedTransitions());

        if (! $content instanceof AiArticle) {
            $allowed = $allowed->filter(fn (AiPublicationStatus $status): bool => $status->isContainerStatus());
        }

        return $allowed->values();
    }

    /**
     * Strip empty reference-link rows before they reach the sync action.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalisedLinks(): array
    {
        return collect($this->articleLinks)
            ->filter(fn (array $link): bool => trim((string) ($link['url'] ?? '')) !== '')
            ->values()
            ->all();
    }

    /**
     * Every id in the topic's own subtree, including itself.
     *
     * @param  Collection<int, AiTopic>  $topics
     * @return array<int, int>
     */
    private function descendantIds(Collection $topics, int $topicId): array
    {
        $ids = [$topicId];
        $frontier = [$topicId];

        while ($frontier !== []) {
            $children = $topics
                ->filter(fn (AiTopic $topic) => in_array($topic->parent_id, $frontier, true))
                ->pluck('id')
                ->diff($ids)
                ->values()
                ->all();

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    /**
     * Drop the memoised computed properties so the next render re-queries.
     */
    private function bustCaches(): void
    {
        unset(
            $this->stats, $this->articles, $this->topics, $this->paths,
            $this->resources, $this->topicOptions, $this->lessonOptions,
            $this->relatedOptions, $this->parentOptions,
        );
    }
}; ?>


@php
    $formatOptions = \App\Enums\AiArticleFormat::options();
    $difficultyOptions = \App\Enums\AiDifficulty::options();
    $kindOptions = \App\Enums\AiResourceKind::options();
    $linkKinds = \App\Enums\AiLinkKind::options();
    $statusFilter = \App\Enums\AiPublicationStatus::options();
@endphp

<div data-test="admin-ai-hub">
    <x-app.ui.header
        eyebrow="Knowledge"
        :title="__('AI Knowledge Hub')"
        :description="__('Curate the articles, topics, learning paths, and resources readers meet on the public hub. New work stays a draft until you publish it from its row.')"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
            ['label' => __('AI Knowledge Hub')],
        ]"
    >
        <x-slot:actions>
            <a
                href="{{ route('ai-hub.index') }}"
                wire:navigate
                class="sui-focus inline-flex items-center gap-1.5 rounded-lg border border-sui-hairline bg-sui-surface px-3 py-2 text-xs font-semibold text-ink-600 transition duration-150 ease-soft hover:text-sui-ink"
            >
                <flux:icon name="arrow-top-right-on-square" class="size-4" />
                {{ __('View public hub') }}
            </a>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.body>
        {{-- Metrics --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-app.ui.stat
                :label="__('Articles')"
                :value="$this->stats['articles']"
                icon="newspaper"
                tone="primary"
                :hint="$this->stats['drafts'] > 0
                    ? __(':count in draft', ['count' => $this->stats['drafts']])
                    : __('Everything is published')"
            />
            <x-app.ui.stat :label="__('Topics')" :value="$this->stats['topics']" icon="bookmark" tone="info" :hint="__('The filing taxonomy')" />
            <x-app.ui.stat :label="__('Learning paths')" :value="$this->stats['paths']" icon="map" tone="success" :hint="__('Ordered routes')" />
            <x-app.ui.stat :label="__('Resources')" :value="$this->stats['resources']" icon="link" tone="secondary" :hint="__('Outbound references')" />
        </div>

        <x-app.ui.section :padded="false">
            {{-- Tab band --}}
            <nav class="border-b border-sui-hairline px-5" aria-label="{{ __('Hub shelves') }}">
                <div class="-mb-px flex gap-1 overflow-x-auto">
                    @foreach (['articles' => __('Articles'), 'topics' => __('Topics'), 'paths' => __('Paths'), 'resources' => __('Resources')] as $key => $label)
                        <button
                            type="button"
                            wire:click="showTab('{{ $key }}')"
                            aria-current="{{ $tab === $key ? 'page' : 'false' }}"
                            @class([
                                '-mb-px flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-4 text-sm sui-focus transition duration-150 ease-soft',
                                'border-sui-accent font-semibold text-sui-ink' => $tab === $key,
                                'border-transparent text-ink-500 hover:text-sui-ink' => $tab !== $key,
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </nav>

            {{-- ================================================================ --}}
            {{-- Articles                                                          --}}
            {{-- ================================================================ --}}
            @if ($tab === 'articles')
                <div class="grid gap-6 p-5 lg:grid-cols-12">
                    {{-- Form pane --}}
                    <div class="lg:col-span-5">
                        <div class="sui-card rounded-xl border border-sui-hairline p-5">
                            <h2 class="text-sm font-semibold tracking-tight text-ink-900">
                                {{ $editingArticleId ? __('Edit article') : __('New article') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-500">{{ __('Markdown body. Saving never publishes — use the row buttons.)') }}</p>

                            <div class="mt-4 space-y-4">
                                <div>
                                    <label for="articleTitle" class="sui-control-label">{{ __('Title') }}</label>
                                    <input id="articleTitle" type="text" wire:model="articleTitle" class="sui-control mt-2 w-full text-xs" />
                                    @error('articleTitle') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="articleSlug" class="sui-control-label">{{ __('Slug') }}</label>
                                        <input id="articleSlug" type="text" wire:model="articleSlug" class="sui-control mt-2 w-full text-xs" :placeholder="__('auto')" />
                                        @error('articleSlug') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="articleFormat" class="sui-control-label">{{ __('Format') }}</label>
                                        <select id="articleFormat" wire:model.live="articleFormat" class="sui-control mt-2 w-full text-xs">
                                            @foreach ($formatOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label for="articleDifficulty" class="sui-control-label">{{ __('Difficulty') }}</label>
                                    <select id="articleDifficulty" wire:model.live="articleDifficulty" class="sui-control mt-2 w-full text-xs">
                                        @foreach ($difficultyOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label for="articleSummary" class="sui-control-label">{{ __('Summary') }}</label>
                                    <textarea id="articleSummary" rows="2" wire:model="articleSummary" class="sui-control mt-2 w-full text-xs"></textarea>
                                    @error('articleSummary') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label for="articleBody" class="sui-control-label">{{ __('Body (Markdown)') }}</label>
                                    <textarea id="articleBody" rows="8" wire:model="articleBody" class="sui-control mt-2 w-full font-mono text-xs"></textarea>
                                    @error('articleBody') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label for="articlePrerequisites" class="sui-control-label">{{ __('Prerequisites') }}</label>
                                    <textarea id="articlePrerequisites" rows="2" wire:model="articlePrerequisites" class="sui-control mt-2 w-full text-xs"></textarea>
                                </div>

                                {{-- Topics filing --}}
                                <div>
                                    <span class="sui-control-label">{{ __('Topics') }}</span>
                                    @php $filing = $this->topicOptions; @endphp
                                    @if ($filing->isEmpty())
                                        <p class="mt-2 text-xs text-ink-500">{{ __('No topics yet — add some on the Topics shelf.') }}</p>
                                    @else
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($filing as $option)
                                                <label class="inline-flex items-center gap-1.5 rounded-lg border border-sui-hairline bg-sui-well px-2.5 py-1.5 text-xs">
                                                    <input type="checkbox" wire:model.live="articleTopics" value="{{ $option->id }}" class="sui-focus size-4 shrink-0 accent-sui-accent" />
                                                    <span class="text-ink-600">{{ $option->name }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                {{-- Reference links --}}
                                <div>
                                    <div class="flex items-center justify-between">
                                        <span class="sui-control-label">{{ __('Reference links') }}</span>
                                        <button type="button" wire:click="addArticleLink" wire:loading.attr="disabled" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-ink-600 hover:text-sui-ink">
                                            {{ __('Add link') }}
                                        </button>
                                    </div>
                                    <div class="mt-2 space-y-3">
                                        @foreach ($articleLinks as $index => $link)
                                            <div wire:key="article-link-{{ $index }}" class="rounded-lg border border-sui-hairline bg-sui-well p-3">
                                                <div class="grid gap-2 sm:grid-cols-2">
                                                    <input type="text" wire:model="articleLinks.{{ $index }}.url" placeholder="{{ __('https://…') }}" class="sui-control w-full text-xs" />
                                                    <input type="text" wire:model="articleLinks.{{ $index }}.label" placeholder="{{ __('Label') }}" class="sui-control w-full text-xs" />
                                                </div>
                                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                    <select wire:model="articleLinks.{{ $index }}.kind" class="sui-control w-full text-xs">
                                                        @foreach ($linkKinds as $value => $label)
                                                            <option value="{{ $value }}">{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    <div class="flex gap-2">
                                                        <input type="text" wire:model="articleLinks.{{ $index }}.note" placeholder="{{ __('Note') }}" class="sui-control w-full text-xs" />
                                                        <button type="button" wire:click="removeArticleLink({{ $index }})" wire:loading.attr="disabled" class="sui-focus shrink-0 rounded-md border border-sui-hairline px-2 text-xs text-sui-danger" aria-label="{{ __('Remove link') }}">
                                                            <flux:icon name="x-mark" class="size-4" />
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- Related --}}
                                <div>
                                    <label for="articleRelated" class="sui-control-label">{{ __('Read next (optional)') }}</label>
                                    @php $related = $this->relatedOptions; @endphp
                                    <select id="articleRelated" wire:model="articleRelated" multiple size="5" class="sui-control mt-2 w-full text-xs">
                                        @foreach ($related as $option)
                                            <option value="{{ $option->id }}">{{ $option->title }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1.5 text-2xs text-ink-500">{{ __('Ctrl/Cmd-click to select several.') }}</p>
                                </div>

                                {{-- SEO --}}
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="articleMetaTitle" class="sui-control-label">{{ __('Meta title') }}</label>
                                        <input id="articleMetaTitle" type="text" wire:model="articleMetaTitle" class="sui-control mt-2 w-full text-xs" />
                                    </div>
                                    <div>
                                        <label for="articleCoverImage" class="sui-control-label">{{ __('Cover image') }}</label>
                                        <input id="articleCoverImage" type="text" wire:model="articleCoverImage" class="sui-control mt-2 w-full text-xs" />
                                    </div>
                                </div>
                                <div>
                                    <label for="articleMetaDescription" class="sui-control-label">{{ __('Meta description') }}</label>
                                    <textarea id="articleMetaDescription" rows="2" wire:model="articleMetaDescription" class="sui-control mt-2 w-full text-xs"></textarea>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 pt-1">
                                    <flux:button variant="primary" size="sm" wire:click="saveArticle" icon="check" wire:loading.attr="disabled">
                                        {{ $editingArticleId ? __('Save changes') : __('Create article') }}
                                    </flux:button>
                                    @if ($editingArticleId)
                                        <flux:button size="sm" wire:click="resetArticleForm">{{ __('Cancel') }}</flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Queue pane --}}
                    <div class="lg:col-span-7">
                        <div class="mb-4 flex flex-wrap items-center gap-2">
                            <input type="search" wire:model.live.debounce.400ms="articleSearch" placeholder="{{ __('Search articles…') }}" class="sui-control w-full text-xs sm:w-64" />
                            <select wire:model.live="articleStatus" class="sui-control w-full text-xs sm:w-40">
                                <option value="all">{{ __('All statuses') }}</option>
                                @foreach ($statusFilter as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                            @if ($articleSearch !== '' || $articleStatus !== 'all')
                                <button type="button" wire:click="clearArticleFilters" class="sui-focus rounded-md border border-sui-hairline px-2.5 py-2 text-2xs font-semibold text-ink-600 hover:text-sui-ink">
                                    {{ __('Clear') }}
                                </button>
                            @endif
                        </div>

                        @if ($this->articles->isEmpty())
                            <x-app.ui.empty-state icon="newspaper" :title="__('No articles match')" :description="__('Adjust the filters or write the first piece.')" />
                        @else
                            <div class="overflow-x-auto rounded-xl border border-sui-hairline">
                                <table class="w-full text-left">
                                    <thead class="sui-table-head bg-sui-well">
                                        <tr>
                                            <th class="px-4 py-3 text-2xs font-semibold uppercase tracking-wide text-ink-500">{{ __('Article') }}</th>
                                            <th class="px-4 py-3 text-2xs font-semibold uppercase tracking-wide text-ink-500">{{ __('Status') }}</th>
                                            <th class="px-4 py-3 text-right text-2xs font-semibold uppercase tracking-wide text-ink-500">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-sui-hairline">
                                        @foreach ($this->articles as $article)
                                            <tr wire:key="article-row-{{ $article->id }}" class="transition duration-150 ease-soft hover:bg-sui-hover">
                                                <td class="px-4 py-3">
                                                    <p class="text-sm font-semibold text-sui-ink">{{ $article->title }}</p>
                                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs text-ink-500">
                                                        <span>{{ $article->format->label() }}</span>
                                                        <span aria-hidden="true">·</span>
                                                        <span>{{ $article->difficulty->label() }}</span>
                                                        <span aria-hidden="true">·</span>
                                                        <span>{{ $article->reading_time }} {{ __('min') }}</span>
                                                        @if ($article->topics->isNotEmpty())
                                                            <span aria-hidden="true">·</span>
                                                            <span>{{ $article->topics->pluck('name')->take(2)->implode(', ') }}</span>
                                                        @endif
                                                    </p>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <flux:badge :color="$article->status->badgeColor()" size="sm">{{ $article->status->label() }}</flux:badge>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                                                        @foreach ($this->allowedTransitionsFor($article) as $target)
                                                            <button
                                                                type="button"
                                                                wire:click="changeStatus('article', {{ $article->id }}, '{{ $target->value }}')"
                                                                wire:loading.attr="disabled"
                                                                class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-ink-600 transition hover:text-sui-ink"
                                                            >
                                                                {{ $target->label() }}
                                                            </button>
                                                        @endforeach
                                                        <button type="button" wire:click="editArticle({{ $article->id }})" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-ink-600 hover:text-sui-ink">
                                                            {{ __('Edit') }}
                                                        </button>
                                                        <div x-data="{ confirming: false }" class="inline-flex">
                                                            <button type="button" x-show="! confirming" x-on:click="confirming = true" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-sui-danger">
                                                                {{ __('Delete') }}
                                                            </button>
                                                            <span x-show="confirming" x-cloak class="inline-flex items-center gap-1">
                                                                <button type="button" wire:click="deleteArticle({{ $article->id }})" wire:loading.attr="disabled" class="sui-focus rounded-md bg-sui-danger px-2 py-1 text-2xs font-semibold text-white">
                                                                    {{ __('Confirm') }}
                                                                </button>
                                                                <button type="button" x-on:click="confirming = false" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs text-ink-600">
                                                                    {{ __('No') }}
                                                                </button>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4">
                                <x-app.ui.pagination :paginator="$this->articles" :label="__('Articles')" />
                            </div>
                        @endif
                    </div>
                </div>
            @endif


            {{-- ================================================================ --}}
            {{-- Topics                                                            --}}
            {{-- ================================================================ --}}
            @if ($tab === 'topics')
                <div class="grid gap-6 p-5 lg:grid-cols-12">
                    <div class="lg:col-span-5">
                        <div class="sui-card rounded-xl border border-sui-hairline p-5">
                            <h2 class="text-sm font-semibold tracking-tight text-ink-900">
                                {{ $editingTopicId ? __('Edit topic') : __('New topic') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-500">{{ __('Topics are the taxonomy readers browse. Retire one to hide it without deleting filings.)') }}</p>

                            <div class="mt-4 space-y-4">
                                <div>
                                    <label for="topicName" class="sui-control-label">{{ __('Name') }}</label>
                                    <input id="topicName" type="text" wire:model="topicName" class="sui-control mt-2 w-full text-xs" />
                                    @error('topicName') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="topicSlug" class="sui-control-label">{{ __('Slug') }}</label>
                                        <input id="topicSlug" type="text" wire:model="topicSlug" class="sui-control mt-2 w-full text-xs" :placeholder="__('auto')" />
                                        @error('topicSlug') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="topicIcon" class="sui-control-label">{{ __('Icon') }}</label>
                                        <input id="topicIcon" type="text" wire:model="topicIcon" class="sui-control mt-2 w-full text-xs" :placeholder="__('bookmark')" />
                                        @error('topicIcon') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                    </div>
                                </div>

                                <div>
                                    <label for="topicParentId" class="sui-control-label">{{ __('Parent') }}</label>
                                    <select id="topicParentId" wire:model.live="topicParentId" class="sui-control mt-2 w-full text-xs">
                                        <option value="">{{ __('— Top level —') }}</option>
                                        @foreach ($this->parentOptions as $option)
                                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('topicParentId') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label for="topicDescription" class="sui-control-label">{{ __('Description') }}</label>
                                    <textarea id="topicDescription" rows="3" wire:model="topicDescription" class="sui-control mt-2 w-full text-xs"></textarea>
                                    @error('topicDescription') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="topicSortOrder" class="sui-control-label">{{ __('Sort order') }}</label>
                                        <input id="topicSortOrder" type="number" min="0" max="999" wire:model="topicSortOrder" class="sui-control mt-2 w-full text-xs" :placeholder="__('auto')" />
                                    </div>
                                    <label class="mt-6 flex items-center gap-2 text-xs text-ink-600">
                                        <input type="checkbox" wire:model.live="topicActive" class="sui-focus size-4 shrink-0 accent-sui-accent" />
                                        {{ __('Active (visible on the hub)') }}
                                    </label>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 pt-1">
                                    <flux:button variant="primary" size="sm" wire:click="saveTopic" icon="check" wire:loading.attr="disabled">
                                        {{ $editingTopicId ? __('Save changes') : __('Create topic') }}
                                    </flux:button>
                                    @if ($editingTopicId)
                                        <flux:button size="sm" wire:click="resetTopicForm">{{ __('Cancel') }}</flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-7">
                        @if ($this->topics->isEmpty())
                            <x-app.ui.empty-state icon="bookmark" :title="__('No topics yet')" :description="__('Create the first branch of your taxonomy.')" />
                        @else
                            <ul class="space-y-3">
                                @foreach ($this->topics as $topic)
                                    <li wire:key="topic-{{ $topic->id }}" class="sui-card rounded-xl border border-sui-hairline p-4">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <p class="text-sm font-semibold text-sui-ink">{{ $topic->name }}</p>
                                                    @unless ($topic->is_active)
                                                        <flux:badge color="zinc" size="sm">{{ __('Retired') }}</flux:badge>
                                                    @endunless
                                                </div>
                                                <p class="mt-0.5 text-2xs text-ink-500">
                                                    {{ trans_choice(':count article(s) · :paths path(s)', $topic->articles->count(), ['count' => $topic->articles->count(), 'paths' => $topic->learning_paths_count]) }}
                                                </p>
                                            </div>
                                            <div class="flex flex-wrap items-center justify-end gap-1.5">
                                                <div class="flex items-center rounded-md border border-sui-hairline">
                                                    <button type="button" wire:click="reorderTopic({{ $topic->id }}, 'up')" wire:loading.attr="disabled" class="sui-focus px-1.5 py-1 text-ink-500 hover:text-sui-ink" aria-label="{{ __('Move up') }}">
                                                        <flux:icon name="chevron-up" class="size-4" />
                                                    </button>
                                                    <button type="button" wire:click="reorderTopic({{ $topic->id }}, 'down')" wire:loading.attr="disabled" class="sui-focus border-l border-sui-hairline px-1.5 py-1 text-ink-500 hover:text-sui-ink" aria-label="{{ __('Move down') }}">
                                                        <flux:icon name="chevron-down" class="size-4" />
                                                    </button>
                                                </div>
                                                <button type="button" wire:click="toggleTopicActive({{ $topic->id }})" wire:loading.attr="disabled" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-ink-600 hover:text-sui-ink">
                                                    {{ $topic->is_active ? __('Retire') : __('Activate') }}
                                                </button>
                                                <button type="button" wire:click="editTopic({{ $topic->id }})" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-ink-600 hover:text-sui-ink">{{ __('Edit') }}</button>
                                                <div x-data="{ confirming: false }" class="inline-flex">
                                                    <button type="button" x-show="! confirming" x-on:click="confirming = true" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-sui-danger">{{ __('Delete') }}</button>
                                                    <span x-show="confirming" x-cloak class="inline-flex items-center gap-1">
                                                        <button type="button" wire:click="deleteTopic({{ $topic->id }})" wire:loading.attr="disabled" class="sui-focus rounded-md bg-sui-danger px-2 py-1 text-2xs font-semibold text-white">{{ __('Confirm') }}</button>
                                                        <button type="button" x-on:click="confirming = false" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs text-ink-600">{{ __('No') }}</button>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        @if ($topic->children->isNotEmpty())
                                            <ul class="mt-3 space-y-2 border-l border-sui-hairline pl-4">
                                                @foreach ($topic->children as $child)
                                                    <li wire:key="topic-{{ $topic->id }}-child-{{ $child->id }}" class="flex flex-wrap items-center justify-between gap-2">
                                                        <div class="min-w-0">
                                                            <span class="text-xs font-medium text-ink-700">{{ $child->name }}</span>
                                                            @unless ($child->is_active)
                                                                <flux:badge color="zinc" size="sm">{{ __('Retired') }}</flux:badge>
                                                            @endunless
                                                            <span class="ml-1 text-2xs text-ink-500">· {{ $child->articles->count() }} {{ __('articles') }}</span>
                                                        </div>
                                                        <div class="flex items-center gap-1.5">
                                                            <div class="flex items-center rounded-md border border-sui-hairline">
                                                                <button type="button" wire:click="reorderTopic({{ $child->id }}, 'up')" wire:loading.attr="disabled" class="sui-focus px-1.5 py-1 text-ink-500 hover:text-sui-ink" aria-label="{{ __('Move up') }}"><flux:icon name="chevron-up" class="size-3.5" /></button>
                                                                <button type="button" wire:click="reorderTopic({{ $child->id }}, 'down')" wire:loading.attr="disabled" class="sui-focus border-l border-sui-hairline px-1.5 py-1 text-ink-500 hover:text-sui-ink" aria-label="{{ __('Move down') }}"><flux:icon name="chevron-down" class="size-3.5" /></button>
                                                            </div>
                                                            <button type="button" wire:click="editTopic({{ $child->id }})" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-ink-600 hover:text-sui-ink">{{ __('Edit') }}</button>
                                                            <div x-data="{ confirming: false }" class="inline-flex">
                                                                <button type="button" x-show="! confirming" x-on:click="confirming = true" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-sui-danger">{{ __('Delete') }}</button>
                                                                <span x-show="confirming" x-cloak class="inline-flex items-center gap-1">
                                                                    <button type="button" wire:click="deleteTopic({{ $child->id }})" wire:loading.attr="disabled" class="sui-focus rounded-md bg-sui-danger px-2 py-1 text-2xs font-semibold text-white">{{ __('Confirm') }}</button>
                                                                    <button type="button" x-on:click="confirming = false" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs text-ink-600">{{ __('No') }}</button>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif

            {{-- ================================================================ --}}
            {{-- Learning paths                                                    --}}
            {{-- ================================================================ --}}
            @if ($tab === 'paths')
                <div class="grid gap-6 p-5 lg:grid-cols-12">
                    <div class="lg:col-span-5">
                        <div class="sui-card rounded-xl border border-sui-hairline p-5">
                            <h2 class="text-sm font-semibold tracking-tight text-ink-900">
                                {{ $editingPathId ? __('Edit path') : __('New learning path') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-500">{{ __('A path publishes only once every lesson is published.)') }}</p>

                            <div class="mt-4 space-y-4">
                                <div>
                                    <label for="pathTitle" class="sui-control-label">{{ __('Title') }}</label>
                                    <input id="pathTitle" type="text" wire:model="pathTitle" class="sui-control mt-2 w-full text-xs" />
                                    @error('pathTitle') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="pathSlug" class="sui-control-label">{{ __('Slug') }}</label>
                                        <input id="pathSlug" type="text" wire:model="pathSlug" class="sui-control mt-2 w-full text-xs" :placeholder="__('auto')" />
                                    </div>
                                    <div>
                                        <label for="pathDifficulty" class="sui-control-label">{{ __('Difficulty') }}</label>
                                        <select id="pathDifficulty" wire:model.live="pathDifficulty" class="sui-control mt-2 w-full text-xs">
                                            @foreach ($difficultyOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label for="pathTopicId" class="sui-control-label">{{ __('Topic') }}</label>
                                    <select id="pathTopicId" wire:model.live="pathTopicId" class="sui-control mt-2 w-full text-xs">
                                        <option value="">{{ __('— None —') }}</option>
                                        @foreach ($this->topicOptions as $option)
                                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label for="pathDescription" class="sui-control-label">{{ __('Description') }}</label>
                                    <textarea id="pathDescription" rows="3" wire:model="pathDescription" class="sui-control mt-2 w-full text-xs"></textarea>
                                </div>

                                <div>
                                    <label for="pathPrerequisites" class="sui-control-label">{{ __('Prerequisites') }}</label>
                                    <textarea id="pathPrerequisites" rows="2" wire:model="pathPrerequisites" class="sui-control mt-2 w-full text-xs"></textarea>
                                </div>

                                {{-- Lesson builder --}}
                                <div>
                                    <span class="sui-control-label">{{ __('Lessons (in order)') }}</span>
                                    @php $lessonTitles = $this->lessonOptions->pluck('title', 'id'); @endphp
                                    <div class="mt-2 flex items-end gap-2">
                                        <select wire:model.live="lessonChoice" class="sui-control w-full text-xs" aria-label="{{ __('Choose an article') }}">
                                            <option value="">{{ __('Pick an article…') }}</option>
                                            @foreach ($this->lessonOptions as $option)
                                                <option value="{{ $option->id }}">{{ $option->title }}{{ $option->status->value === 'published' ? '' : ' ('.__('draft').')' }}</option>
                                            @endforeach
                                        </select>
                                        <button type="button" wire:click="addLesson" wire:loading.attr="disabled" class="sui-focus shrink-0 rounded-md border border-sui-hairline px-3 py-2 text-xs font-semibold text-ink-600 hover:text-sui-ink">{{ __('Add') }}</button>
                                    </div>
                                    @error('lessonChoice') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror

                                    <ol class="mt-3 space-y-2">
                                        @foreach ($pathLessons as $index => $lesson)
                                            @php $articleId = (int) ($lesson['ai_article_id'] ?? 0); @endphp
                                            <li wire:key="lesson-{{ $articleId }}" class="rounded-lg border border-sui-hairline bg-sui-well p-2.5">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="min-w-0 truncate text-xs font-medium text-ink-700">
                                                        {{ $index +1 }}. {{ $lesson['title'] !== '' ? $lesson['title'] : ($lessonTitles[$articleId] ?? __('Article #'.$articleId)) }}
                                                    </span>
                                                    <div class="flex shrink-0 items-center gap-1.5">
                                                        <div class="flex items-center rounded-md border border-sui-hairline">
                                                            <button type="button" wire:click="moveLesson({{ $articleId }}, 'up')" wire:loading.attr="disabled" class="sui-focus px-1.5 py-1 text-ink-500 hover:text-sui-ink" aria-label="{{ __('Move up') }}"><flux:icon name="chevron-up" class="size-3.5" /></button>
                                                            <button type="button" wire:click="moveLesson({{ $articleId }}, 'down')" wire:loading.attr="disabled" class="sui-focus border-l border-sui-hairline px-1.5 py-1 text-ink-500 hover:text-sui-ink" aria-label="{{ __('Move down') }}"><flux:icon name="chevron-down" class="size-3.5" /></button>
                                                        </div>
                                                        <button type="button" wire:click="removeLesson({{ $articleId }})" wire:loading.attr="disabled" class="sui-focus rounded-md border border-sui-hairline px-1.5 py-1 text-2xs text-sui-danger" aria-label="{{ __('Remove lesson') }}"><flux:icon name="x-mark" class="size-3.5" /></button>
                                                    </div>
                                                </div>
                                                <input type="text" wire:model="pathLessons.{{ $index }}.title" placeholder="{{ __('Custom title (optional)') }}" class="sui-control mt-2 w-full text-2xs" />
                                            </li>
                                        @endforeach
                                    </ol>
                                </div>

                                <label class="flex items-center gap-2 text-xs text-ink-600">
                                    <input type="checkbox" wire:model.live="pathFeatured" class="sui-focus size-4 shrink-0 accent-sui-accent" />
                                    {{ __('Feature on the hub landing') }}
                                </label>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="pathMetaTitle" class="sui-control-label">{{ __('Meta title') }}</label>
                                        <input id="pathMetaTitle" type="text" wire:model="pathMetaTitle" class="sui-control mt-2 w-full text-xs" />
                                    </div>
                                    <div>
                                        <label for="pathMetaDescription" class="sui-control-label">{{ __('Meta description') }}</label>
                                        <textarea id="pathMetaDescription" rows="2" wire:model="pathMetaDescription" class="sui-control mt-2 w-full text-xs"></textarea>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 pt-1">
                                    <flux:button variant="primary" size="sm" wire:click="savePath" icon="check" wire:loading.attr="disabled">
                                        {{ $editingPathId ? __('Save changes') : __('Create path') }}
                                    </flux:button>
                                    @if ($editingPathId)
                                        <flux:button size="sm" wire:click="resetPathForm">{{ __('Cancel') }}</flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-7">
                        @if ($this->paths->isEmpty())
                            <x-app.ui.empty-state icon="map" :title="__('No learning paths')" :description="__('Group published articles into an ordered route.')" />
                        @else
                            <ul class="space-y-3">
                                @foreach ($this->paths as $path)
                                    <li wire:key="path-{{ $path->id }}" class="sui-card rounded-xl border border-sui-hairline p-4">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <p class="text-sm font-semibold text-sui-ink">{{ $path->title }}</p>
                                                    <flux:badge :color="$path->status->badgeColor()" size="sm">{{ $path->status->label() }}</flux:badge>
                                                    @if ($path->is_featured)
                                                        <flux:badge color="amber" size="sm">{{ __('Featured') }}</flux:badge>
                                                    @endif
                                                </div>
                                                <p class="mt-0.5 text-2xs text-ink-500">
                                                    {{ $path->items_count ?? $path->items->count() }} {{ __('lessons') }}
                                                    @if ($path->topic)
                                                        <span aria-hidden="true">·</span> {{ $path->topic->name }}
                                                    @endif
                                                    <span aria-hidden="true">·</span> {{ $path->difficulty->label() }}
                                                </p>
                                            </div>
                                            <div class="flex flex-wrap items-center justify-end gap-1.5">
                                                @foreach ($this->allowedTransitionsFor($path) as $target)
                                                    <button type="button" wire:click="changeStatus('path', {{ $path->id }}, '{{ $target->value }}')" wire:loading.attr="disabled" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-ink-600 transition hover:text-sui-ink">{{ $target->label() }}</button>
                                                @endforeach
                                                <button type="button" wire:click="editPath({{ $path->id }})" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-ink-600 hover:text-sui-ink">{{ __('Edit') }}</button>
                                                <div x-data="{ confirming: false }" class="inline-flex">
                                                    <button type="button" x-show="! confirming" x-on:click="confirming = true" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-sui-danger">{{ __('Delete') }}</button>
                                                    <span x-show="confirming" x-cloak class="inline-flex items-center gap-1">
                                                        <button type="button" wire:click="deletePath({{ $path->id }})" wire:loading.attr="disabled" class="sui-focus rounded-md bg-sui-danger px-2 py-1 text-2xs font-semibold text-white">{{ __('Confirm') }}</button>
                                                        <button type="button" x-on:click="confirming = false" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs text-ink-600">{{ __('No') }}</button>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        @if ($path->items->isNotEmpty())
                                            <ol class="mt-3 space-y-1 border-t border-sui-hairline pt-3">
                                                @foreach ($path->items as $item)
                                                    <li wire:key="path-{{ $path->id }}-item-{{ $item->id }}" class="flex items-center gap-2 text-xs text-ink-600">
                                                        <span class="tabular-nums text-ink-400">{{ $loop->iteration }}.</span>
                                                        <span class="min-w-0 truncate">{{ $item->displayTitle() }}</span>
                                                    </li>
                                                @endforeach
                                            </ol>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif

            {{-- ================================================================ --}}
            {{-- Resources                                                         --}}
            {{-- ================================================================ --}}
            @if ($tab === 'resources')
                <div class="grid gap-6 p-5 lg:grid-cols-12">
                    <div class="lg:col-span-5">
                        <div class="sui-card rounded-xl border border-sui-hairline p-5">
                            <h2 class="text-sm font-semibold tracking-tight text-ink-900">
                                {{ $editingResourceId ? __('Edit resource') : __('New resource') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-500">{{ __('Outbound links readers trust. A full https:// link is required.)') }}</p>

                            <div class="mt-4 space-y-4">
                                <div>
                                    <label for="resourceTitle" class="sui-control-label">{{ __('Title') }}</label>
                                    <input id="resourceTitle" type="text" wire:model="resourceTitle" class="sui-control mt-2 w-full text-xs" />
                                    @error('resourceTitle') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label for="resourceUrl" class="sui-control-label">{{ __('URL') }}</label>
                                    <input id="resourceUrl" type="url" wire:model="resourceUrl" class="sui-control mt-2 w-full text-xs" placeholder="https://" />
                                    @error('resourceUrl') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="resourceKindValue" class="sui-control-label">{{ __('Kind') }}</label>
                                        <select id="resourceKindValue" wire:model.live="resourceKindValue" class="sui-control mt-2 w-full text-xs">
                                            @foreach ($kindOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="resourceProvider" class="sui-control-label">{{ __('Provider') }}</label>
                                        <input id="resourceProvider" type="text" wire:model="resourceProvider" class="sui-control mt-2 w-full text-xs" />
                                    </div>
                                </div>

                                <div>
                                    <label for="resourceTopicId" class="sui-control-label">{{ __('Topic') }}</label>
                                    <select id="resourceTopicId" wire:model.live="resourceTopicId" class="sui-control mt-2 w-full text-xs">
                                        <option value="">{{ __('— None —') }}</option>
                                        @foreach ($this->topicOptions as $option)
                                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('resourceTopicId') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label for="resourceDescription" class="sui-control-label">{{ __('Description') }}</label>
                                    <textarea id="resourceDescription" rows="3" wire:model="resourceDescription" class="sui-control mt-2 w-full text-xs"></textarea>
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="resourceSortOrder" class="sui-control-label">{{ __('Sort order') }}</label>
                                        <input id="resourceSortOrder" type="number" min="0" max="999" wire:model="resourceSortOrder" class="sui-control mt-2 w-full text-xs" :placeholder="__('auto')" />
                                    </div>
                                    <label class="mt-6 flex items-center gap-2 text-xs text-ink-600">
                                        <input type="checkbox" wire:model.live="resourceFeatured" class="sui-focus size-4 shrink-0 accent-sui-accent" />
                                        {{ __('Featured') }}
                                    </label>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 pt-1">
                                    <flux:button variant="primary" size="sm" wire:click="saveResource" icon="check" wire:loading.attr="disabled">
                                        {{ $editingResourceId ? __('Save changes') : __('Create resource') }}
                                    </flux:button>
                                    @if ($editingResourceId)
                                        <flux:button size="sm" wire:click="resetResourceForm">{{ __('Cancel') }}</flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-7">
                        <div class="mb-4 flex flex-wrap items-center gap-2">
                            <input type="search" wire:model.live.debounce.400ms="resourceSearch" placeholder="{{ __('Search resources…') }}" class="sui-control w-full text-xs sm:w-64" />
                            <select wire:model.live="resourceKind" class="sui-control w-full text-xs sm:w-40">
                                <option value="all">{{ __('All kinds') }}</option>
                                @foreach ($kindOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @if ($resourceSearch !== '' || $resourceKind !== 'all')
                                <button type="button" wire:click="clearResourceFilters" class="sui-focus rounded-md border border-sui-hairline px-2.5 py-2 text-2xs font-semibold text-ink-600 hover:text-sui-ink">{{ __('Clear') }}</button>
                            @endif
                        </div>

                        @if ($this->resources->isEmpty())
                            <x-app.ui.empty-state icon="link" :title="__('No resources match')" :description="__('Adjust the filters or add the first reference.')" />
                        @else
                            <div class="overflow-x-auto rounded-xl border border-sui-hairline">
                                <table class="w-full text-left">
                                    <thead class="sui-table-head bg-sui-well">
                                        <tr>
                                            <th class="px-4 py-3 text-2xs font-semibold uppercase tracking-wide text-ink-500">{{ __('Resource') }}</th>
                                            <th class="px-4 py-3 text-2xs font-semibold uppercase tracking-wide text-ink-500">{{ __('Status') }}</th>
                                            <th class="px-4 py-3 text-right text-2xs font-semibold uppercase tracking-wide text-ink-500">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-sui-hairline">
                                        @foreach ($this->resources as $resource)
                                            <tr wire:key="resource-row-{{ $resource->id }}" class="transition duration-150 ease-soft hover:bg-sui-hover">
                                                <td class="px-4 py-3">
                                                    <p class="text-sm font-semibold text-sui-ink">{{ $resource->title }}</p>
                                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs text-ink-500">
                                                        <span>{{ $resource->kind->label() }}</span>
                                                        <span aria-hidden="true">·</span>
                                                        <span>{{ $resource->host() }}</span>
                                                        @if ($resource->topic)
                                                            <span aria-hidden="true">·</span>
                                                            <span>{{ $resource->topic->name }}</span>
                                                        @endif
                                                    </p>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <flux:badge :color="$resource->status->badgeColor()" size="sm">{{ $resource->status->label() }}</flux:badge>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                                                        <div class="flex items-center rounded-md border border-sui-hairline">
                                                            <button type="button" wire:click="reorderResource({{ $resource->id }}, 'up')" wire:loading.attr="disabled" class="sui-focus px-1.5 py-1 text-ink-500 hover:text-sui-ink" aria-label="{{ __('Move up') }}"><flux:icon name="chevron-up" class="size-4" /></button>
                                                            <button type="button" wire:click="reorderResource({{ $resource->id }}, 'down')" wire:loading.attr="disabled" class="sui-focus border-l border-sui-hairline px-1.5 py-1 text-ink-500 hover:text-sui-ink" aria-label="{{ __('Move down') }}"><flux:icon name="chevron-down" class="size-4" /></button>
                                                        </div>
                                                        @foreach ($this->allowedTransitionsFor($resource) as $target)
                                                            <button type="button" wire:click="changeStatus('resource', {{ $resource->id }}, '{{ $target->value }}')" wire:loading.attr="disabled" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-ink-600 transition hover:text-sui-ink">{{ $target->label() }}</button>
                                                        @endforeach
                                                        <button type="button" wire:click="editResource({{ $resource->id }})" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-ink-600 hover:text-sui-ink">{{ __('Edit') }}</button>
                                                        <div x-data="{ confirming: false }" class="inline-flex">
                                                            <button type="button" x-show="! confirming" x-on:click="confirming = true" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs font-semibold text-sui-danger">{{ __('Delete') }}</button>
                                                            <span x-show="confirming" x-cloak class="inline-flex items-center gap-1">
                                                                <button type="button" wire:click="deleteResource({{ $resource->id }})" wire:loading.attr="disabled" class="sui-focus rounded-md bg-sui-danger px-2 py-1 text-2xs font-semibold text-white">{{ __('Confirm') }}</button>
                                                                <button type="button" x-on:click="confirming = false" class="sui-focus rounded-md border border-sui-hairline px-2 py-1 text-2xs text-ink-600">{{ __('No') }}</button>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4">
                                <x-app.ui.pagination :paginator="$this->resources" :label="__('Resources')" />
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </x-app.ui.section>
    </x-app.ui.body>
</div>
