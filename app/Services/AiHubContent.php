<?php

namespace App\Services;

use App\Models\AiArticle;
use App\Models\AiLearningPath;
use App\Models\AiResource;
use App\Models\AiTopic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Reads the public shelves of the AI Knowledge Hub.
 *
 * The hub's pages are all assembled from the same four row types, and every one
 * of them has to agree about what "public" means. Pushing those queries here —
 * memoised per request — keeps the landing page, the topic index, and the
 * article detail reading the same definition instead of each re-typing the
 * `published()` scope and drifting.
 *
 * Only two rules live in this class rather than in the models, and both are
 * presentation rules rather than facts about a row: how a topic tree is nested
 * for navigation, and how the shelves are trimmed to the counts in
 * `config/ai_hub.php`.
 */
class AiHubContent
{
    /**
     * Loaded topic tree, built once per request.
     *
     * @var Collection<int, AiTopic>|null
     */
    protected ?Collection $topics = null;

    /**
     * @var array{articles: int, topics: int, paths: int, resources: int, minutes: int}|null
     */
    protected ?array $statistics = null;

    /**
     * Active topics as a two-level tree, each carrying its public article count.
     *
     * Counted through the `published()` scope on the pivot relation so a topic
     * card can never advertise an article the hub will not open.
     *
     * @return Collection<int, AiTopic>
     */
    public function topicTree(): Collection
    {
        return $this->topics ??= AiTopic::query()
            ->active()
            ->rootLevel()
            ->ordered()
            ->with(['children' => fn (Relation $query) => $query->active()->ordered()->withCount($this->countableArticles())])
            ->withCount($this->countableArticles())
            ->get();
    }

    /**
     * The same tree flattened to roots-then-children, for filter chips.
     *
     * Flattening a nested Eloquent collection degrades it to the support
     * collection base class, so that is the type this method promises.
     *
     * @return SupportCollection<int, AiTopic>
     */
    public function topicChips(): SupportCollection
    {
        return $this->topicTree()
            ->flatMap(fn (AiTopic $topic): array => array_merge([$topic], $topic->children->all()))
            ->values();
    }

    /**
     * The active topic with the given slug, or null when it is switched off.
     */
    public function topic(string $slug): ?AiTopic
    {
        return AiTopic::query()
            ->active()
            ->with(['children' => fn (Relation $query) => $query->active()->ordered()])
            ->withCount($this->countableArticles())
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Root topics as a paged grid, each with its subtopics and public count.
     *
     * The topic index needs the same rows as the landing page's topic grid but
     * in pages, so the count expression lives here rather than being re-typed by
     * the component. Two definitions of "how many articles a topic advertises" is
     * how a grid ends up promising more than the topic page can show.
     *
     * @return LengthAwarePaginator<int, AiTopic>
     */
    public function paginateTopics(int $perPage): LengthAwarePaginator
    {
        return AiTopic::query()
            ->active()
            ->rootLevel()
            ->ordered()
            ->with(['children' => fn (Relation $query) => $query->active()->ordered()->withCount($this->countableArticles())])
            ->withCount($this->countableArticles())
            ->paginate($perPage);
    }

    /**
     * The published articles filed under a topic.
     *
     * Returned as a query rather than a page so the topic screen can add its own
     * filters and pagination on top of the one definition of "readable in this
     * topic", which is the same definition the topic card's count uses.
     *
     * @return Builder<AiArticle>
     */
    public function topicArticles(AiTopic $topic): Builder
    {
        return AiArticle::query()->published()->inTopic($topic->slug);
    }

    /**
     * Hub-wide totals for the landing page's opening band.
     *
     * Every figure is a database aggregate over published rows, so the numbers
     * printed beside the shelves describe the shelves themselves.
     *
     * @return array{articles: int, topics: int, paths: int, resources: int, minutes: int}
     */
    public function statistics(): array
    {
        return $this->statistics ??= [
            'articles' => AiArticle::query()->published()->count(),
            'topics' => AiTopic::query()->active()->count(),
            'paths' => AiLearningPath::query()->published()->count(),
            'resources' => AiResource::query()->published()->count(),
            'minutes' => (int) AiArticle::query()->published()->sum('reading_time'),
        ];
    }

    /**
     * The article the landing page spotlights.
     */
    public function spotlight(): ?AiArticle
    {
        return AiArticle::query()
            ->published()
            ->featured()
            ->with(['topics', 'user'])
            ->recent()
            ->first()
            ?? $this->recent(1)->first();
    }

    /**
     * The newest published articles, shortest read first.
     *
     * @return Collection<int, AiArticle>
     */
    public function recent(?int $limit = null): Collection
    {
        return AiArticle::query()
            ->published()
            ->readableOrder()
            ->with(['topics', 'user'])
            ->take($limit ?? (int) config('ai_hub.landing.recent_articles'))
            ->get();
    }

    /**
     * Featured learning paths, in the order a reader should meet them.
     *
     * @return Collection<int, AiLearningPath>
     */
    public function featuredPaths(?int $limit = null): Collection
    {
        return AiLearningPath::query()
            ->published()
            ->featured()
            ->inReadingOrder()
            ->with('topic')
            ->withCount('items')
            ->take($limit ?? (int) config('ai_hub.landing.featured_paths'))
            ->get();
    }

    /**
     * Featured resources, in operator order.
     *
     * @return Collection<int, AiResource>
     */
    public function featuredResources(?int $limit = null): Collection
    {
        return AiResource::query()
            ->published()
            ->where('is_featured', true)
            ->ordered()
            ->with('topic')
            ->take($limit ?? (int) config('ai_hub.landing.featured_resources'))
            ->get();
    }

    /**
     * Published paths anchored to a topic, in the order a reader should meet them.
     *
     * Shared by the topic shelf and a path's "next routes" rail so both describe
     * "paths for this topic" identically; the row count is the same `withCount`
     * the path card reads for its lesson figure.
     *
     * @return Collection<int, AiLearningPath>
     */
    public function pathsForTopic(AiTopic $topic, ?int $limit = null): Collection
    {
        return AiLearningPath::query()
            ->published()
            ->inTopic($topic->slug)
            ->inReadingOrder()
            ->with('topic')
            ->withCount('items')
            ->take($limit ?? (int) config('ai_hub.landing.featured_paths'))
            ->get();
    }

    /**
     * The other published paths that share this path's topic.
     *
     * A path without a topic has no siblings, and returns an empty collection
     * rather than falling back to "any other path": an untopical path is not a
     * relative of a topical one.
     *
     * @return Collection<int, AiLearningPath>
     */
    public function siblingPaths(AiLearningPath $path, ?int $limit = null): Collection
    {
        $topic = $path->topic;

        if ($topic === null) {
            return new Collection;
        }

        return AiLearningPath::query()
            ->published()
            ->inTopic($topic->slug)
            ->whereKeyNot($path->getKey())
            ->inReadingOrder()
            ->with('topic')
            ->withCount('items')
            ->take($limit ?? (int) config('ai_hub.landing.featured_paths'))
            ->get();
    }

    /**
     * Published resources filed under a topic, in operator order.
     *
     * @return Collection<int, AiResource>
     */
    public function resourcesForTopic(AiTopic $topic, ?int $limit = null): Collection
    {
        return AiResource::query()
            ->published()
            ->inTopic($topic->slug)
            ->ordered()
            ->take($limit ?? (int) config('ai_hub.landing.featured_resources'))
            ->get();
    }

    /**
     * The published path a lesson belongs to, for the article's context strip.
     *
     * A lesson can be filed in more than one path; the one returned is the
     * newest featured-first route, matching what the paths index would have
     * offered the reader. The slot is returned too, so the page can say which
     * step of the sequence the reader is standing in.
     *
     * @return array{path: AiLearningPath, step: int, total: int}|null
     */
    public function pathContext(AiArticle $article): ?array
    {
        $path = AiLearningPath::query()
            ->published()
            ->inReadingOrder()
            ->whereHas('items', fn (Builder $query) => $query->where('ai_article_id', $article->id))
            ->withCount('items')
            ->first();

        if ($path === null) {
            return null;
        }

        $step = $path->items()->where('ai_article_id', $article->id)->value('sort_order');

        return [
            'path' => $path,
            'step' => $step === null ? 1 : (int) $step,
            'total' => $path->items_count ?? $path->lessonCount(),
        ];
    }

    /**
     * Related articles that are actually readable, trimmed for the detail page.
     *
     * The operator may have filed more rows than the page can show, and may have
     * filed an article that has since been archived. Both are handled here rather
     * than in the view so the strip never renders a link that 404s.
     *
     * @return Collection<int, AiArticle>
     */
    public function relatedArticles(AiArticle $article): Collection
    {
        return $article->relatedArticles()
            ->published()
            ->with('topics')
            ->take((int) config('ai_hub.article.related_limit'))
            ->get();
    }

    /**
     * Other public articles filed under the article's primary topic.
     *
     * Used by the topic page's "keep reading" rail and by the article detail
     * when nothing has been curated as related.
     *
     * @return Collection<int, AiArticle>
     */
    public function moreFromTopic(AiArticle $article, int $limit = 3): Collection
    {
        $topic = $article->primaryTopic();

        if ($topic === null) {
            return new Collection;
        }

        return AiArticle::query()
            ->published()
            ->inTopic($topic->slug)
            ->whereKeyNot($article->getKey())
            ->readableOrder()
            ->take($limit)
            ->get();
    }

    /**
     * The count expression shared by the topic tree and the topic page.
     *
     * @return array<string, callable(Builder<AiArticle>): void>
     */
    protected function countableArticles(): array
    {
        // `withCount()` closures receive the Eloquent builder, not the
        // relation object Laravel uses for eager loads — the type must be
        // the query that actually runs the count.
        return [
            'articles' => function (Builder $query): void {
                /** @var Builder<AiArticle> $query */
                $query->published();
            },
        ];
    }
}
