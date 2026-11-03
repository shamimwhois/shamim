<?php

namespace App\Actions\AiHub;

use App\Enums\AiDifficulty;
use App\Enums\AiPublicationStatus;
use App\Models\AiArticle;
use App\Models\AiLearningPath;
use App\Models\AiLearningPathItem;
use App\Models\AiTopic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a learning path and the order of its lessons.
 *
 * The lesson list is the product here, so it is written with the path rather than
 * by a separate call the console might forget to make. Slots keep their own id so
 * a re-order edits the existing rows instead of deleting and rebuilding them,
 * which preserves any per-lesson title and note the operator wrote.
 *
 * Status is delegated to {@see SyncAiPublicationStatus} exactly as it is for
 * articles: saving a path never publishes it.
 */
class SaveAiLearningPath
{
    use ResolvesAiSlug;

    public function __construct(protected SyncAiPublicationStatus $syncStatus) {}

    /**
     * Persist the path, creating it when none is given.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>|null  $lessons  Ordered lesson slots.
     */
    public function handle(User $author, array $data, ?AiLearningPath $path = null, ?array $lessons = null): AiLearningPath
    {
        return DB::transaction(function () use ($author, $data, $path, $lessons): AiLearningPath {
            $path ??= new AiLearningPath;

            $title = (string) ($data['title'] ?? $path->title ?? '');

            $isNew = ! $path->exists;

            $requestedStatus = $this->resolveStatus($data['status'] ?? null) ?? ($isNew ? AiPublicationStatus::Draft : $path->status);

            $currentStatus = $isNew ? AiPublicationStatus::Draft : $path->status;

            $path->fill([
                'ai_topic_id' => $this->resolveTopicId(array_key_exists('ai_topic_id', $data) ? $data['ai_topic_id'] : $path->ai_topic_id),
                'title' => $title,
                'description' => array_key_exists('description', $data) ? $data['description'] : $path->description,
                'difficulty' => $this->resolveDifficulty($data['difficulty'] ?? null) ?? ($isNew ? AiDifficulty::Beginner : $path->difficulty),
                // A brand-new path has no cast attribute to fall back to, so
                // the missing-key branch must resolve to false rather than the
                // null that would violate the NOT NULL column.
                'is_featured' => array_key_exists('is_featured', $data) ? (bool) $data['is_featured'] : (bool) $path->is_featured,
                'prerequisites' => array_key_exists('prerequisites', $data) ? $data['prerequisites'] : $path->prerequisites,
                'meta_title' => array_key_exists('meta_title', $data) ? $data['meta_title'] : $path->meta_title,
                'meta_description' => array_key_exists('meta_description', $data) ? $data['meta_description'] : $path->meta_description,
            ]);

            $path->user_id ??= $author->id;
            $path->forceFill(['status' => $currentStatus]);
            $path->slug = $this->resolveSlug($path, $data, $title);

            $path->save();

            if ($lessons !== null) {
                $this->syncLessons($path, $lessons);
            }

            if (! $isNew && $requestedStatus !== $currentStatus) {
                $this->syncStatus->handle($path, $requestedStatus);
            }

            $path->save();

            return $path->refresh();
        });
    }

    /**
     * Rewrite the ordered lesson slots.
     *
     * @param  array<int, array<string, mixed>>  $lessons
     */
    protected function syncLessons(AiLearningPath $path, array $lessons): void
    {
        $keep = [];
        $order = 0;

        foreach ($lessons as $lesson) {
            $articleId = (int) ($lesson['ai_article_id'] ?? 0);

            if ($articleId <= 0 || ! AiArticle::query()->whereKey($articleId)->exists()) {
                continue;
            }

            $attributes = [
                'ai_learning_path_id' => $path->id,
                'ai_article_id' => $articleId,
                'title' => filled($lesson['title'] ?? null) ? trim((string) $lesson['title']) : null,
                'note' => filled($lesson['note'] ?? null) ? trim((string) $lesson['note']) : null,
                'sort_order' => $order++,
            ];

            $existing = isset($lesson['id'])
                ? AiLearningPathItem::query()->whereKey($lesson['id'])->where('ai_learning_path_id', $path->id)->first()
                : null;

            // A lesson already in the path keeps its slot even when the operator
            // submits it again without the id; writing it twice would break the
            // unique pair index.
            $row = $existing ?? AiLearningPathItem::query()
                ->where('ai_learning_path_id', $path->id)
                ->where('ai_article_id', $articleId)
                ->first() ?? new AiLearningPathItem;

            $row->fill($attributes);
            $row->save();

            $keep[] = $row->id;
        }

        AiLearningPathItem::query()
            ->where('ai_learning_path_id', $path->id)
            ->when($keep !== [], fn ($query) => $query->whereKeyNot($keep))
            ->delete();

        $path->unsetRelation('items');
    }

    /**
     * Resolve a submitted topic id, dropping a reference that does not exist.
     */
    protected function resolveTopicId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 && AiTopic::query()->whereKey($id)->exists() ? $id : null;
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
     * Coerce a submitted status.
     */
    protected function resolveStatus(mixed $value): ?AiPublicationStatus
    {
        if ($value instanceof AiPublicationStatus) {
            return $value;
        }

        return $value === null || $value === '' ? null : AiPublicationStatus::tryFrom((string) $value);
    }
}
