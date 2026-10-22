<?php

namespace App\Actions\AiHub;

use App\Enums\AiArticleFormat;
use App\Enums\AiDifficulty;
use App\Enums\AiPublicationStatus;
use App\Models\AiArticle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a hub article, and everything filed against it.
 *
 * One entry point owns the whole article: its fields, its derived markdown
 * projections, its topic filing, its reference links, and its curated reading
 * list. Splitting those across callers is how an article ends up published with
 * a body that the search projection has never seen.
 *
 * Status is never written here. A requested status change is delegated to
 * {@see SyncAiPublicationStatus}, the only writer of the status/timestamp
 * invariant, so "new content stays a draft until explicitly published" holds no
 * matter which screen submits the save.
 */
class SaveAiArticle
{
    use ResolvesAiSlug;

    public function __construct(
        protected HydrateAiArticleContent $hydrateContent,
        protected SyncAiArticleTopics $syncTopics,
        protected SyncAiArticleLinks $syncLinks,
        protected SyncAiRelatedArticles $syncRelated,
        protected SyncAiPublicationStatus $syncStatus,
    ) {}

    /**
     * Persist the article, creating it when none is given.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int|string>|null  $topicIds
     * @param  array<int, array<string, mixed>>|null  $links
     * @param  array<int, int|string>|null  $relatedIds
     */
    public function handle(User $author, array $data, ?AiArticle $article = null, ?array $topicIds = null, ?array $links = null, ?array $relatedIds = null): AiArticle
    {
        return DB::transaction(function () use ($author, $data, $article, $topicIds, $links, $relatedIds): AiArticle {
            $article ??= new AiArticle;

            $title = (string) ($data['title'] ?? $article->title ?? '');

            $isNew = ! $article->exists;

            $requestedStatus = $this->resolveStatus($data['status'] ?? null) ?? ($isNew ? AiPublicationStatus::Draft : $article->status);

            // Saving never publishes. A new article is filed as a draft whatever
            // the form submitted, and goes public only through the status
            // transition, which is the sole writer of `published_at`.
            $currentStatus = $isNew ? AiPublicationStatus::Draft : $article->status;

            $article->user_id ??= $author->id;
            $article->fill([
                'title' => $title,
                'summary' => array_key_exists('summary', $data) ? $data['summary'] : $article->summary,
                'body' => $data['body'] ?? $article->body,
                'format' => $this->resolveFormat($data['format'] ?? null) ?? ($isNew ? AiArticleFormat::Article : $article->format),
                'difficulty' => $this->resolveDifficulty($data['difficulty'] ?? null) ?? ($isNew ? AiDifficulty::Beginner : $article->difficulty),
                // A brand-new article has no cast attribute to fall back to, so
                // the missing-key branch must resolve to false rather than the
                // null that would violate the NOT NULL column.
                'is_featured' => array_key_exists('is_featured', $data)
                    ? (bool) $data['is_featured']
                    : (bool) $article->is_featured,
                'cover_image' => array_key_exists('cover_image', $data) ? $data['cover_image'] : $article->cover_image,
                'prerequisites' => array_key_exists('prerequisites', $data) ? $data['prerequisites'] : $article->prerequisites,
                'meta_title' => array_key_exists('meta_title', $data) ? $data['meta_title'] : $article->meta_title,
                'meta_description' => array_key_exists('meta_description', $data) ? $data['meta_description'] : $article->meta_description,
            ]);

            $article->slug = $this->resolveSlug($article, $data, $title);

            $derived = $this->hydrateContent->handle(['body' => (string) $article->body]);

            $article->forceFill([
                'status' => $currentStatus,
                'body_html' => $derived['body_html'],
                'body_text' => $derived['body_text'],
                'word_count' => $derived['word_count'],
                'reading_time' => $derived['reading_time'],
            ]);

            $article->save();

            if ($topicIds !== null) {
                $this->syncTopics->handle($article, $topicIds);
            }

            if ($links !== null) {
                $this->syncLinks->handle($article, $links);
            }

            if ($relatedIds !== null) {
                $this->syncRelated->handle($article, $relatedIds);
            }

            if (! $isNew && $requestedStatus !== $currentStatus) {
                $this->syncStatus->handle($article, $requestedStatus);
                $article->save();
            } else {
                $article->save();
            }

            return $article->refresh();
        });
    }

    /**
     * Coerce a submitted format.
     */
    protected function resolveFormat(mixed $value): ?AiArticleFormat
    {
        if ($value instanceof AiArticleFormat) {
            return $value;
        }

        return $value === null || $value === '' ? null : AiArticleFormat::tryFrom((string) $value);
    }

    /**
     * Coerce a submitted difficulty.
     */
    protected function resolveDifficulty(mixed $value): ?AiDifficulty
    {
        if ($value instanceof AiDifficulty) {
            return $value;
        }

        return $value === null || $value === '' ? null : AiDifficulty::tryFrom((string) $value);
    }

    /**
     * Coerce a submitted status, defaulting an unusable value to draft.
     */
    protected function resolveStatus(mixed $value): ?AiPublicationStatus
    {
        if ($value instanceof AiPublicationStatus) {
            return $value;
        }

        return $value === null || $value === '' ? null : AiPublicationStatus::tryFrom((string) $value);
    }
}
